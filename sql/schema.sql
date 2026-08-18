-- =====================================================
-- Schéma de la base de données : Climat & Santé
-- Compatible MySQL / MariaDB (InfinityFree)
-- =====================================================

CREATE TABLE IF NOT EXISTS communes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(150) NOT NULL,
    region VARCHAR(150) DEFAULT NULL,
    latitude DECIMAL(9,6) NOT NULL,
    longitude DECIMAL(9,6) NOT NULL,
    population INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS climat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commune_id INT NOT NULL,
    date DATE NOT NULL,
    temperature DECIMAL(5,2) DEFAULT NULL,      -- T2M (°C)
    precipitation DECIMAL(6,2) DEFAULT NULL,    -- PRECTOTCORR (mm)
    humidite DECIMAL(5,2) DEFAULT NULL,         -- RH2M (%)
    vent DECIMAL(5,2) DEFAULT NULL,             -- WS2M (m/s)
    source VARCHAR(50) DEFAULT 'NASA_POWER',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_commune_date (commune_id, date),
    CONSTRAINT fk_climat_commune FOREIGN KEY (commune_id) REFERENCES communes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maladies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commune_id INT NOT NULL,
    date DATE NOT NULL,
    type ENUM('paludisme','diarrhee','infection_respiratoire','grippe','autre') NOT NULL,
    nb_cas INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_maladies_commune FOREIGN KEY (commune_id) REFERENCES communes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table optionnelle pour stocker les résultats de corrélation déjà calculés
CREATE TABLE IF NOT EXISTS correlations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commune_id INT DEFAULT NULL, -- NULL = corrélation globale (toutes communes)
    variable_climat ENUM('temperature','precipitation','humidite','vent') NOT NULL,
    variable_sante ENUM('paludisme','diarrhee','infection_respiratoire','grippe') NOT NULL,
    coefficient_pearson DECIMAL(6,4) NOT NULL,
    nb_observations INT NOT NULL,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table pour stocker un indice de risque calculé par commune / date
CREATE TABLE IF NOT EXISTS risques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    commune_id INT NOT NULL,
    date DATE NOT NULL,
    indice_risque DECIMAL(5,2) NOT NULL, -- 0 à 100
    niveau ENUM('faible','moyen','eleve') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_commune_date_risque (commune_id, date),
    CONSTRAINT fk_risques_commune FOREIGN KEY (commune_id) REFERENCES communes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exemple de données (à adapter)
INSERT INTO communes (nom, region, latitude, longitude, population) VALUES
('Antananarivo', 'Analamanga', -18.879100, 47.507900, 1300000),
('Toamasina', 'Atsinanana', -18.166600, 49.383300, 300000),
('Mahajanga', 'Boeny', -15.716700, 46.316700, 250000);
