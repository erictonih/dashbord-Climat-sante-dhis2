<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function isConfigured(): bool
    {
        return Config::get('DB_HOST') !== null
            && Config::get('DB_NAME') !== null
            && Config::get('DB_USER') !== null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        if (!self::isConfigured()) {
            throw new HttpError(503, 'db_not_configured',
                'Base de données non configurée : renseignez DB_HOST, DB_NAME, DB_USER et DB_PASS.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST'), Config::int('DB_PORT', 3306), Config::get('DB_NAME'));

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 8,
        ];

        // TLS vérifié vers la base distante (DB_SSL=1, recommandé et activé par défaut sur Vercel).
        if (Config::get('DB_SSL', Config::get('VERCEL') !== null ? '1' : '0') === '1') {
            $ca = self::caFile();
            if ($ca === null) {
                throw new HttpError(500, 'db_tls', 'Certificat racine introuvable pour la connexion TLS à la base.');
            }
            $isNew = class_exists('Pdo\\Mysql');
            $options[$isNew ? constant('Pdo\\Mysql::ATTR_SSL_CA') : PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[$isNew ? constant('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        try {
            self::$pdo = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS', ''), $options);
        } catch (PDOException $e) {
            error_log('Connexion base de données : ' . $e->getMessage());
            throw new HttpError(503, 'db_unreachable', 'Impossible de joindre la base de données.');
        }

        return self::$pdo;
    }

    /** DB_SSL_CA : contenu PEM (certificat propre à l'hébergeur, ex. Aiven) ; sinon magasin système. */
    private static function caFile(): ?string
    {
        $pem = Config::get('DB_SSL_CA');
        if ($pem !== null) {
            $path = sys_get_temp_dir() . '/db-ca-' . substr(sha1($pem), 0, 12) . '.pem';
            if (!is_file($path)) file_put_contents($path, str_replace('\n', "\n", $pem));
            return $path;
        }
        foreach (['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt'] as $path) {
            if (is_file($path)) return $path;
        }
        return null;
    }

    /** Crée les tables techniques absentes (non destructif). Les tables métier sont dans sql/schema.sql. */
    public static function ensureTechnicalTables(): void
    {
        static $done = false;
        if ($done) return;
        $pdo = self::pdo();
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $pdo->exec('CREATE TABLE IF NOT EXISTS dhis2_ou_mapping (
            commune VARCHAR(150) PRIMARY KEY, ou_id VARCHAR(11) NOT NULL,
            ou_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)' . $suffix);
        $pdo->exec('CREATE TABLE IF NOT EXISTS dhis2_de_mapping (
            de_id VARCHAR(11) PRIMARY KEY, de_name VARCHAR(255) DEFAULT NULL,
            maladie VARCHAR(100) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)' . $suffix);
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_state (
            cle VARCHAR(64) PRIMARY KEY, valeur VARCHAR(255) NOT NULL)' . $suffix);
        $done = true;
    }
}
