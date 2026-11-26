<?php
declare(strict_types=1);

namespace App\Dhis2;

use App\Core\Config;
use App\Core\HttpError;

/**
 * Client DHIS2 (lecture seule).
 *  - jeton d'accès personnel (DHIS2_TOKEN, recommandé) ou identifiant / mot de passe ;
 *  - HTTPS obligatoire, redirections désactivées (les identifiants ne fuient pas ailleurs) ;
 *  - requêtes parallèles, nouvelles tentatives sur 429 / 502 / 503 / 504 ;
 *  - erreurs explicites, sans jamais exposer d'identifiants.
 */
final class Dhis2Client
{
    private const RETRYABLE = [0, 429, 502, 503, 504];

    public static function isConfigured(): bool
    {
        return Config::get('DHIS2_BASE_URL') !== null
            && (Config::get('DHIS2_TOKEN') !== null
                || (Config::get('DHIS2_USERNAME') !== null && Config::get('DHIS2_PASSWORD') !== null));
    }

    public static function datasetUid(): ?string
    {
        $uid = Config::get('DHIS2_DATASET_UID');
        return $uid !== null && self::isUid($uid) ? $uid : null;
    }

    public static function isUid(string $value): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9]{10}$/', $value) === 1;
    }

    /** Neutralise les caractères qui changeraient le sens d'un filtre DHIS2 (propriété:opérateur:valeur). */
    public static function filterValue(string $value): string
    {
        return trim((string) preg_replace('/[:\[\],]+/', ' ', $value));
    }

    /** Paramètres répétés au format DHIS2 : filter=a&filter=b (et non filter[]=a). */
    public static function buildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $values) {
            foreach ((array) $values as $value) {
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
            }
        }
        return implode('&', $parts);
    }

    private function handle(string $endpoint, array $params, int $timeout): \CurlHandle
    {
        if (!self::isConfigured()) {
            throw new HttpError(503, 'dhis2_not_configured',
                'DHIS2 non configuré : renseignez DHIS2_BASE_URL et DHIS2_TOKEN (ou DHIS2_USERNAME et DHIS2_PASSWORD).');
        }
        $base = rtrim((string) Config::get('DHIS2_BASE_URL'), '/');
        if (!str_starts_with($base, 'https://') && !Config::isLocal()) {
            throw new HttpError(500, 'dhis2_insecure', 'DHIS2_BASE_URL doit utiliser HTTPS.');
        }

        $url = $base . '/api/' . ltrim($endpoint, '/') . ($params ? '?' . self::buildQuery($params) : '');
        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'climat-sante/2.0',
        ];
        if ($token = Config::get('DHIS2_TOKEN')) {
            $headers[] = 'Authorization: ApiToken ' . $token;
        } else {
            $options[CURLOPT_USERPWD] = Config::get('DHIS2_USERNAME') . ':' . Config::get('DHIS2_PASSWORD');
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        return $ch;
    }

    /**
     * Exécute plusieurs GET en parallèle.
     * @param array<array-key, array{0:string,1?:array}> $requests
     * @return array<array-key, array{status:int, data:?array}>
     */
    public function getMany(array $requests, int $concurrency = 4, int $timeout = 25): array
    {
        $results = [];
        $pending = $requests;
        for ($attempt = 1; $attempt <= 3 && $pending; $attempt++) {
            if ($attempt > 1) usleep(400000 * ($attempt - 1));
            $retry = [];
            foreach (array_chunk($pending, $concurrency, true) as $chunk) {
                $multi = curl_multi_init();
                $handles = [];
                foreach ($chunk as $key => $request) {
                    $handles[$key] = $this->handle($request[0], $request[1] ?? [], $timeout);
                    curl_multi_add_handle($multi, $handles[$key]);
                }
                do {
                    $status = curl_multi_exec($multi, $running);
                    if ($running) curl_multi_select($multi, 1.0);
                } while ($running && $status === CURLM_OK);

                foreach ($handles as $key => $ch) {
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if (curl_error($ch) !== '' ) $code = 0;
                    $data = json_decode((string) curl_multi_getcontent($ch), true);
                    $results[$key] = ['status' => $code, 'data' => ($code === 200 && is_array($data)) ? $data : null];
                    if (in_array($code, self::RETRYABLE, true)) $retry[$key] = $chunk[$key];
                    curl_multi_remove_handle($multi, $ch);
                }
                curl_multi_close($multi);
            }
            $pending = $retry;
        }
        return $results;
    }

    /** GET unique ; lève une HttpError explicite en cas d'échec. */
    public function get(string $endpoint, array $params = []): array
    {
        $result = $this->getMany([[$endpoint, $params]])[0];
        if ($result['data'] === null) throw self::error($result['status'], $endpoint);
        return $result['data'];
    }

    public static function error(int $status, string $endpoint): HttpError
    {
        error_log("DHIS2 HTTP $status sur $endpoint");
        return match (true) {
            $status === 0   => new HttpError(504, 'dhis2_unreachable', 'Le serveur DHIS2 ne répond pas.'),
            $status === 401 => new HttpError(502, 'dhis2_auth', 'DHIS2 a refusé les identifiants configurés.'),
            $status === 403 => new HttpError(502, 'dhis2_forbidden', "Le compte DHIS2 n'a pas accès à cette ressource."),
            $status === 404 => new HttpError(404, 'dhis2_not_found', 'Ressource introuvable dans DHIS2.'),
            $status === 200 => new HttpError(502, 'dhis2_invalid', "DHIS2 a renvoyé une réponse non JSON (vérifiez l'URL)."),
            default         => new HttpError(502, 'dhis2_error', "Erreur DHIS2 (HTTP $status)."),
        };
    }
}
