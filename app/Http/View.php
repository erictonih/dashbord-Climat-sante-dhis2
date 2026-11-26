<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;

final class View
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** URL d'une ressource statique avec empreinte, pour un cache long sans fichiers périmés. */
    public static function asset(string $path): string
    {
        $version = substr((string) getenv('VERCEL_GIT_COMMIT_SHA') ?: (string) getenv('VERCEL_DEPLOYMENT_ID'), -10);
        return "/assets/$path" . ($version !== '' ? '?v=' . rawurlencode($version) : '');
    }

    public static function render(string $view, array $data = [], bool $withShell = true): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $data['csrf'] = Auth::csrfToken();
        $data['user'] = Auth::session()['u'] ?? null;
        extract($data, EXTR_SKIP);
        ob_start();
        require __DIR__ . "/../views/$view.php";
        $content = ob_get_clean();
        require __DIR__ . '/../views/layout.php';
    }
}
