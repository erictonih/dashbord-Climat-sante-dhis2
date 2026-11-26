<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;
use App\Core\Config;
use App\Core\HttpError;
use App\Core\Request;
use App\Core\Response;
use App\Services\CronRunner;

/** Routage : tout est protégé par défaut ; seules /login et /cron/* ont leur propre contrôle. */
final class Kernel
{
    private const PAGES = [
        '/'             => ['dashboard', 'Tableau de bord'],
        '/carte'        => ['carte', 'Carte des risques'],
        '/communes'     => ['communes', 'Communes'],
        '/import-climat' => ['climat', 'Import climat'],
        '/import-dhis2' => ['dhis2', 'Import DHIS2'],
    ];

    public static function handle(): void
    {
        Response::securityHeaders();
        $path = Request::path();
        $method = Request::method() === 'HEAD' ? 'GET' : Request::method();
        $isJson = str_starts_with($path, '/api/') || str_starts_with($path, '/cron/');

        try {
            self::route($method, $path);
        } catch (HttpError $e) {
            self::fail($isJson, $e->status, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            // Le détail reste dans les journaux ; rien de technique n'est exposé.
            error_log((string) $e);
            self::fail($isJson, 500, 'internal', 'Une erreur interne est survenue.');
        }
    }

    private static function fail(bool $json, int $status, string $code, string $message): void
    {
        if ($json) {
            Response::json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
        } else {
            http_response_code($status);
            View::render('error', ['title' => "Erreur $status", 'message' => $message], false);
        }
    }

    private static function route(string $method, string $path): void
    {
        if (str_starts_with($path, '/cron/')) {
            self::cron($method, substr($path, 6));
            return;
        }
        if ($path === '/login') {
            self::login($method);
            return;
        }

        if (Auth::session() === null) {
            if (str_starts_with($path, '/api/')) {
                throw new HttpError(401, 'unauthenticated', 'Session expirée : reconnectez-vous.');
            }
            Response::redirect('/login');
            return;
        }

        if ($path === '/logout' && $method === 'POST') {
            if (!Auth::checkCsrf($_POST['csrf'] ?? null)) throw new HttpError(403, 'csrf', 'Jeton de sécurité invalide.');
            Auth::logout();
            Response::redirect('/login');
            return;
        }

        $routes = Api::routes();
        if (isset($routes["$method $path"])) {
            if ($method === 'POST' && !Auth::checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
                throw new HttpError(403, 'csrf', 'Jeton de sécurité invalide : rechargez la page.');
            }
            [$action] = $routes["$method $path"];
            $data = (new Api())->$action();
            Response::json(['success' => true] + $data, 200, $routes["$method $path"][1] ?? 0);
            return;
        }

        if ($method === 'GET' && isset(self::PAGES[$path])) {
            [$view, $title] = self::PAGES[$path];
            View::render($view, ['title' => $title, 'path' => $path]);
            return;
        }

        throw new HttpError(404, 'not_found', 'Page introuvable.');
    }

    private static function login(string $method): void
    {
        if (!Auth::isConfigured()) {
            throw new HttpError(503, 'auth_not_configured',
                'Accès fermé : définissez APP_SECRET (32 caractères min.) et APP_PASSWORD_HASH.');
        }
        if (Auth::session() !== null) {
            Response::redirect('/');
            return;
        }
        $error = null;
        if ($method === 'POST') {
            self::assertSameOrigin();
            $user = is_string($_POST['utilisateur'] ?? null) ? trim($_POST['utilisateur']) : '';
            $password = is_string($_POST['mot_de_passe'] ?? null) ? $_POST['mot_de_passe'] : '';
            try {
                if (Auth::attempt($user, substr($password, 0, 200))) {
                    Response::redirect('/');
                    return;
                }
                $error = 'Identifiant ou mot de passe incorrect.';
                http_response_code(401);
            } catch (HttpError $e) {
                $error = $e->getMessage();
                http_response_code($e->status);
            }
        }
        View::render('login', ['title' => 'Connexion', 'error' => $error], false);
    }

    private static function assertSameOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin === null) return;
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        if ($host . ($port ? ":$port" : '') !== ($_SERVER['HTTP_HOST'] ?? '')) {
            throw new HttpError(403, 'bad_origin', 'Origine de la requête refusée.');
        }
    }

    /** Vercel Cron envoie « Authorization: Bearer $CRON_SECRET ». Aucun jeton dans l'URL. */
    private static function cron(string $method, string $job): void
    {
        $secret = (string) Config::get('CRON_SECRET', '');
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (strlen($secret) < 24 || !hash_equals("Bearer $secret", $header)) {
            throw new HttpError(401, 'unauthorized', 'Accès refusé.');
        }
        if ($method !== 'GET' || !in_array($job, ['mapping', 'climat', 'sante', 'climat-complet'], true)) {
            throw new HttpError(404, 'not_found', 'Tâche inconnue.');
        }
        Response::json(['success' => true] + (new CronRunner())->run($job));
    }
}
