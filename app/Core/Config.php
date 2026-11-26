<?php
declare(strict_types=1);

namespace App\Core;

/** Toute la configuration vient des variables d'environnement : aucun secret dans le code. */
final class Config
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return ($value === false || trim($value) === '') ? $default : $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return ($value !== null && ctype_digit($value)) ? (int) $value : $default;
    }

    /** En développement local uniquement : autorise HTTP (cookies et DHIS2 simulé). */
    public static function isLocal(): bool
    {
        return self::get('APP_ENV') === 'local' && self::get('VERCEL') === null;
    }
}
