-- Climat & Santé — schéma correspondant à l'application (MySQL 8 / MariaDB / TiDB).
-- Non destructif : CREATE TABLE IF NOT EXISTS uniquement.
-- Les tables climat et santé référencent la commune par son NOM.

CREATE TABLE IF NOT EXISTS communes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) DEFAULT NULL,
    nom VARCHAR(150) NOT NULL,
    district VARCHAR(150) DEFAULT NULL,
    region VARCHAR(150) DEFAULT NULL,
    latitude DECIMAL(9,6) DEFAULT NULL,
    longitude DECIMAL(9,6) DEFAULT NULL,
    KEY idx_communes_nom (nom)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS climat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commune VARCHAR(150) NOT NULL,
    date DATE NOT NULL,
    temperature DECIMAL(5,2) DEFAULT NULL,
    precipitation DECIMAL(7,2) DEFAULT NULL,
    pluie DECIMAL(7,2) DEFAULT NULL,
    humidite DECIMAL(5,2) DEFAULT NULL,
    vent DECIMAL(5,2) DEFAULT NULL,
    rayonnement DECIMAL(7,2) DEFAULT NULL,
    KEY idx_climat_commune_date (commune, date),
    KEY idx_climat_date (date)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sante (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commune VARCHAR(150) NOT NULL,
    date DATE NOT NULL,
    maladie VARCHAR(100) NOT NULL,
    nb_cas INT NOT NULL DEFAULT 0,
    KEY idx_sante_commune_date (commune, date, maladie),
    KEY idx_sante_date (date),
    KEY idx_sante_maladie (maladie)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dhis2_ou_mapping (
    commune VARCHAR(150) PRIMARY KEY,
    ou_id VARCHAR(11) NOT NULL,
    ou_name VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dhis2_de_mapping (
    de_id VARCHAR(11) PRIMARY KEY,
    de_name VARCHAR(255) DEFAULT NULL,
    maladie VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_state (
    cle VARCHAR(64) PRIMARY KEY,
    valeur VARCHAR(255) NOT NULL
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    ip_key CHAR(40) NOT NULL,
    ts INT UNSIGNED NOT NULL,
    KEY idx_login (ip_key, ts)
) DEFAULT CHARSET=utf8mb4;

-- Index conseillés sur une base existante (à exécuter une seule fois, après vérification) :
-- ALTER TABLE climat ADD INDEX idx_climat_commune_date (commune, date), ADD INDEX idx_climat_date (date);
-- ALTER TABLE sante  ADD INDEX idx_sante_commune_date (commune, date, maladie), ADD INDEX idx_sante_date (date);
-- ALTER TABLE communes ADD INDEX idx_communes_nom (nom);
