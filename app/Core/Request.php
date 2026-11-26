<?php
declare(strict_types=1);

namespace App\Core;

/** Lecture et validation des entrées. */
final class Request
{
    private static ?array $body = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public static function clientIp(): string
    {
        // Sur Vercel, x-real-ip est posé par la plateforme (non falsifiable par le client).
        if (Config::get('VERCEL') !== null && !empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** Corps JSON ou formulaire. */
    public static function body(): array
    {
        if (self::$body !== null) return self::$body;
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $raw = (string) file_get_contents('php://input', false, null, 0, 65536);
            $data = json_decode($raw, true);
            if (!is_array($data)) throw new HttpError(400, 'invalid_json', 'Corps JSON invalide.');
            return self::$body = $data;
        }
        return self::$body = $_POST;
    }

    private static function raw(string $name): string
    {
        $source = self::method() === 'GET' ? $_GET : self::body() + $_GET;
        $value = $source[$name] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function string(string $name, int $max = 150, bool $required = false): ?string
    {
        $value = self::raw($name);
        if ($value === '') {
            if ($required) self::fail("Le champ « $name » est obligatoire.");
            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max
            || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            self::fail("Le champ « $name » est invalide.");
        }
        return $value;
    }

    public static function int(string $name, int $default, int $min, int $max): int
    {
        $value = self::raw($name);
        if ($value === '') return $default;
        if (!preg_match('/^\d{1,9}$/', $value)) self::fail("Le champ « $name » doit être un entier.");
        return max($min, min($max, (int) $value));
    }

    public static function float(string $name, float $min, float $max): ?float
    {
        $value = str_replace(',', '.', self::raw($name));
        if ($value === '') return null;
        if (!is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            self::fail("Le champ « $name » est hors limites.");
        }
        return (float) $value;
    }

    public static function date(string $name, string $format, string $default): string
    {
        $value = self::raw($name);
        if ($value === '') return $default;
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        if (!$date || $date->format($format) !== $value) self::fail("Format invalide pour « $name ».");
        return $value;
    }

    private static function fail(string $message): never
    {
        throw new HttpError(422, 'invalid_input', $message);
    }
}
