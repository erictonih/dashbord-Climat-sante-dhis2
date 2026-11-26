<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authentification sans état (adaptée au serverless) :
 * cookie signé HMAC, jeton CSRF dérivé de la session, limitation des tentatives.
 */
final class Auth
{
    private const TTL = 8 * 3600;
    private const MAX_ATTEMPTS = 5;
    private const WINDOW = 900;

    public static function isConfigured(): bool
    {
        return strlen((string) Config::get('APP_SECRET', '')) >= 32
            && (Config::get('APP_PASSWORD_HASH') !== null || strlen((string) Config::get('APP_PASSWORD', '')) >= 12);
    }

    private static function cookieName(): string
    {
        return Config::isLocal() ? 'cs_session' : '__Host-cs_session';
    }

    private static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, (string) Config::get('APP_SECRET'));
    }

    /** Change quand le mot de passe change : les anciennes sessions deviennent invalides. */
    private static function credentialVersion(): string
    {
        return substr(self::sign('cred|' . Config::get('APP_USER', 'admin') . '|'
            . Config::get('APP_PASSWORD_HASH', Config::get('APP_PASSWORD', ''))), 0, 16);
    }

    /** @return array{u:string,exp:int,n:string,v:string}|null */
    public static function session(): ?array
    {
        if (!self::isConfigured()) return null;
        $raw = $_COOKIE[self::cookieName()] ?? '';
        if (!is_string($raw) || substr_count($raw, '.') !== 1) return null;
        [$payload, $signature] = explode('.', $raw);
        if (!hash_equals(self::sign($payload), $signature)) return null;
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (!is_array($data) || ($data['exp'] ?? 0) < time()) return null;
        if (!hash_equals(self::credentialVersion(), (string) ($data['v'] ?? ''))) return null;
        return $data;
    }

    public static function csrfToken(): string
    {
        $session = self::session();
        return $session ? self::sign('csrf|' . $session['n']) : '';
    }

    public static function checkCsrf(?string $token): bool
    {
        $expected = self::csrfToken();
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    public static function attempt(string $user, string $password): bool
    {
        $ipKey = substr(self::sign('ip|' . Request::clientIp()), 0, 40);
        if (self::attempts($ipKey) >= self::MAX_ATTEMPTS) {
            throw new HttpError(429, 'too_many_attempts', 'Trop de tentatives. Réessayez dans 15 minutes.');
        }

        $hash = Config::get('APP_PASSWORD_HASH');
        $passwordOk = $hash !== null
            ? password_verify($password, $hash)
            : hash_equals((string) Config::get('APP_PASSWORD'), $password);
        $userOk = hash_equals((string) Config::get('APP_USER', 'admin'), $user);

        if (!$passwordOk || !$userOk) {
            self::recordAttempt($ipKey);
            usleep(random_int(400000, 700000));
            return false;
        }

        self::startSession($user);
        return true;
    }

    private static function startSession(string $user): void
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'u' => $user, 'exp' => time() + self::TTL,
            'n' => bin2hex(random_bytes(16)), 'v' => self::credentialVersion(),
        ])), '+/', '-_'), '=');
        self::cookie($payload . '.' . self::sign($payload), time() + self::TTL);
    }

    public static function logout(): void
    {
        self::cookie('', time() - 3600);
    }

    private static function cookie(string $value, int $expires): void
    {
        setcookie(self::cookieName(), $value, [
            'expires' => $expires, 'path' => '/', 'secure' => !Config::isLocal(),
            'httponly' => true, 'samesite' => 'Strict',
        ]);
    }

    // --- Limitation des tentatives : en base si disponible, sinon fichier local à l'instance ---

    private static function attemptsFile(string $key): string
    {
        return sys_get_temp_dir() . '/login-' . $key . '.json';
    }

    private static function recentFromFile(string $key): array
    {
        $list = json_decode((string) @file_get_contents(self::attemptsFile($key)), true);
        return array_values(array_filter(is_array($list) ? $list : [], fn($t) => $t > time() - self::WINDOW));
    }

    private static function attemptsTable(): ?\PDO
    {
        if (!Database::isConfigured()) return null;
        try {
            $pdo = Database::pdo();
            $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
                ip_key CHAR(40) NOT NULL, ts INT UNSIGNED NOT NULL, INDEX idx_login (ip_key, ts))');
            return $pdo;
        } catch (\Throwable $e) {
            error_log('login_attempts : ' . $e->getMessage());
            return null;
        }
    }

    private static function attempts(string $key): int
    {
        $count = count(self::recentFromFile($key));
        if ($pdo = self::attemptsTable()) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_key = ? AND ts > ?');
            $stmt->execute([$key, time() - self::WINDOW]);
            $count = max($count, (int) $stmt->fetchColumn());
        }
        return $count;
    }

    private static function recordAttempt(string $key): void
    {
        $list = self::recentFromFile($key);
        $list[] = time();
        @file_put_contents(self::attemptsFile($key), json_encode($list), LOCK_EX);
        if ($pdo = self::attemptsTable()) {
            $pdo->prepare('INSERT INTO login_attempts (ip_key, ts) VALUES (?, ?)')->execute([$key, time()]);
            $pdo->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 86400]);
        }
    }
}
