<?php
/**
 * Gestion des correspondances DHIS2 :
 *  - dhis2_ou_mapping : commune (nom, table `communes`) <-> unité d'organisation DHIS2
 *  - dhis2_de_mapping : maladie (libre, table `sante`)    <-> élément de donnée DHIS2
 *
 * GET  /api/dhis2_mapping.php                → liste les deux mappings
 * POST /api/dhis2_mapping.php  (action=map_ou)   commune, ou_id, ou_name
 * POST /api/dhis2_mapping.php  (action=map_de)   de_id, de_name, maladie
 * POST /api/dhis2_mapping.php  (action=delete_ou) commune
 * POST /api/dhis2_mapping.php  (action=delete_de) de_id
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// Création automatique des tables de mapping si elles n'existent pas encore
$pdo->exec("
    CREATE TABLE IF NOT EXISTS dhis2_ou_mapping (
        commune VARCHAR(150) PRIMARY KEY,
        ou_id VARCHAR(11) NOT NULL,
        ou_name VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS dhis2_de_mapping (
        de_id VARCHAR(11) PRIMARY KEY,
        de_name VARCHAR(255) DEFAULT NULL,
        maladie VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'map_ou':
            $stmt = $pdo->prepare("
                INSERT INTO dhis2_ou_mapping (commune, ou_id, ou_name)
                VALUES (:commune, :ou_id, :ou_name)
                ON DUPLICATE KEY UPDATE ou_id = VALUES(ou_id), ou_name = VALUES(ou_name)
            ");
            $stmt->execute([
                ':commune' => $_POST['commune'],
                ':ou_id'   => $_POST['ou_id'],
                ':ou_name' => $_POST['ou_name'] ?? null,
            ]);
            jsonResponse(['success' => true]);
            break;

        case 'map_de':
            $stmt = $pdo->prepare("
                INSERT INTO dhis2_de_mapping (de_id, de_name, maladie)
                VALUES (:de_id, :de_name, :maladie)
                ON DUPLICATE KEY UPDATE de_name = VALUES(de_name), maladie = VALUES(maladie)
            ");
            $stmt->execute([
                ':de_id'   => $_POST['de_id'],
                ':de_name' => $_POST['de_name'] ?? null,
                ':maladie' => $_POST['maladie'],
            ]);
            jsonResponse(['success' => true]);
            break;

        case 'delete_ou':
            $stmt = $pdo->prepare('DELETE FROM dhis2_ou_mapping WHERE commune = :commune');
            $stmt->execute([':commune' => $_POST['commune']]);
            jsonResponse(['success' => true]);
            break;

        case 'delete_de':
            $stmt = $pdo->prepare('DELETE FROM dhis2_de_mapping WHERE de_id = :de_id');
            $stmt->execute([':de_id' => $_POST['de_id']]);
            jsonResponse(['success' => true]);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Action inconnue'], 400);
    }
}

// GET : liste des mappings existants
$ouMapping = $pdo->query('SELECT * FROM dhis2_ou_mapping ORDER BY commune')->fetchAll();
$deMapping = $pdo->query('SELECT * FROM dhis2_de_mapping ORDER BY maladie')->fetchAll();

jsonResponse([
    'success' => true,
    'communes_mappees' => $ouMapping,
    'maladies_mappees' => $deMapping,
]);
