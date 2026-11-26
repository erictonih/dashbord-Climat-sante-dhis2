<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/** Appels parallèles à l'API NASA POWER (données quotidiennes par point). */
final class NasaPower
{
    private const URL = 'https://power.larc.nasa.gov/api/temporal/daily/point';

    /**
     * @param array<int,array{latitude:mixed,longitude:mixed}> $points
     * @return array<int,?array> paramètres NASA par point, null en cas d'échec
     */
    public function fetch(array $points, string $start, string $end): array
    {
        $base = Config::isLocal() ? Config::get('NASA_POWER_URL', self::URL) : self::URL;
        $multi = curl_multi_init();
        $handles = [];
        foreach ($points as $key => $point) {
            $ch = curl_init($base . '?' . http_build_query([
                'parameters' => 'T2M,PRECTOTCORR,RH2M,WS2M,ALLSKY_SFC_SW_DWN', 'community' => 'AG',
                'longitude' => $point['longitude'], 'latitude' => $point['latitude'],
                'start' => $start, 'end' => $end, 'format' => 'JSON',
            ]));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '']);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 1.0);
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $data = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200
                ? json_decode((string) curl_multi_getcontent($ch), true) : null;
            $results[$key] = $data['properties']['parameter'] ?? null;
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);
        return $results;
    }
}
