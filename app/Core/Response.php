<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** En-têtes de sécurité communs à toutes les réponses dynamiques. */
    public static function securityHeaders(): void
    {
        header_remove('X-Powered-By');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
            . "style-src-attr 'unsafe-inline'; img-src 'self' data: https://tile.openstreetmap.org; "
            . "font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; "
            . "form-action 'self'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        // OpenStreetMap exige un Referer : seule l'origine est transmise aux sites tiers.
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        if (!Config::isLocal()) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
        }
    }

    /** @param int $cache Durée (s) de mise en cache privée dans le navigateur. */
    public static function json(array $data, int $status = 200, int $cache = 0): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        if ($cache > 0 && $status === 200) {
            header("Cache-Control: private, max-age=$cache, stale-while-revalidate=" . ($cache * 5));
            header('Vary: Cookie');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function redirect(string $to): void
    {
        http_response_code(303);
        header('Location: ' . $to);
    }
}
