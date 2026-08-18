<?php
/**
 * Import des données de santé agrégées depuis DHIS2
 * ---------------------------------------------------
 * Commune (mappée) → ou_id DHIS2 → UN SEUL appel dataValueSets pour tout le
 * dataSet (DHIS2_DATASET_UID) → somme par data element mappé → Table `sante`
 *
 * Endpoint DHIS2 utilisé :
 *   GET /api/dataValueSets.json?dataSet=DATASET_UID&period=PERIOD&orgUnit=ORGUNIT_UID
 *
 * Un seul appel réseau par (commune, période) ramène TOUTES les données du
 * dataSet — bien plus efficace que d'interroger chaque maladie séparément.
 *
 * Prérequis :
 *  - DHIS2_DATASET_UID renseigné dans config/config.php (voir api/dhis2_test_connexion.php)
 *  - au moins une commune mappée (dhis2_ou_mapping) et une maladie mappée (dhis2_de_mapping)
 *
 * Utilisation :
 *   /api/import_sante_dhis2.php?period_start=202501&period_end=202506&limit=10&offset=0
 *   /api/import_sante_dhis2.php?commune=Ambositra%20I&period_start=202501&period_end=202506
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dhis2_client.php';

set_time_limit(0);

$pdo = getDB();

if (empty(DHIS2_DATASET_UID)) {
    jsonResponse([
        'success' => false,
        'message' => "DHIS2_DATASET_UID n'est pas renseigné dans config/config.php. Utilise api/dhis2_test_connexion.php (liste \"jeux_de_donnees\") pour trouver l'UID du dataSet de surveillance épidémiologique, puis renseigne-le.",
    ], 400);
}

$communeUnique = $_GET['commune'] ?? null;
$periodStart = trim($_GET['period_start'] ?? '') ?: date('Ym', strtotime('-1 month'));
$periodEnd   = trim($_GET['period_end']   ?? '') ?: date('Ym');
$limit  = isset($_GET['limit'])  ? (int) $_GET['limit']  : 10;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

if (!preg_match('/^\d{6}$/', $periodStart) || !preg_match('/^\d{6}$/', $periodEnd)) {
    jsonResponse([
        'success' => false,
        'message' => "Format de période invalide (attendu : AAAAMM, ex: 202501). Reçu : period_start=\"$periodStart\", period_end=\"$periodEnd\".",
    ], 400);
}

$periodes = genererPeriodesMensuelles($periodStart, $periodEnd);

// --- Chargement des mappings maladies (de_id => maladie) ---
$deMappings = $pdo->query('SELECT de_id, maladie FROM dhis2_de_mapping')->fetchAll();
if (empty($deMappings)) {
    jsonResponse([
        'success' => false,
        'message' => "Aucune maladie mappée. Utilise pages/dhis2.php pour mapper au moins une maladie avant l'import.",
    ], 400);
}
// Index rapide de_id -> maladie
$maladieParDeId = [];
foreach ($deMappings as $de) {
    $maladieParDeId[$de['de_id']] = $de['maladie'];
}

// --- Chargement des communes mappées (commune => ou_id) ---
if ($communeUnique) {
    $stmt = $pdo->prepare('SELECT commune, ou_id FROM dhis2_ou_mapping WHERE commune = :commune');
    $stmt->execute([':commune' => $communeUnique]);
    $ouMappings = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT commune, ou_id FROM dhis2_ou_mapping ORDER BY commune LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $ouMappings = $stmt->fetchAll();
}

if (empty($ouMappings)) {
    jsonResponse([
        'success' => false,
        'message' => "Aucune commune mappée à une unité d'organisation DHIS2 (ou offset trop grand). Utilise pages/dhis2.php pour mapper des communes.",
    ], 400);
}

$checkStmt = $pdo->prepare('SELECT id FROM sante WHERE commune = :commune AND date = :date AND maladie = :maladie LIMIT 1');
$insertStmt = $pdo->prepare('INSERT INTO sante (commune, date, maladie, nb_cas) VALUES (:commune, :date, :maladie, :nb_cas)');
$updateStmt = $pdo->prepare('UPDATE sante SET nb_cas = :nb_cas WHERE id = :id');

$resultats = [];

foreach ($ouMappings as $ou) {
    $totalLignes = 0;

    foreach ($periodes as $periode) {
        $date = DateTime::createFromFormat('Ym', $periode)->format('Y-m-01');

        // Un seul appel réseau pour TOUT le dataSet, cette commune, cette période
        $dataValues = dhis2GetDataValueSet(DHIS2_DATASET_UID, $ou['ou_id'], $periode);

        if (empty($dataValues)) {
            usleep(150000);
            continue;
        }

        // Somme par data element (toutes catégories confondues : âge, sexe...)
        $sommeParDataElement = dhis2SommeParDataElement($dataValues);

        // On ne garde que les data elements qu'on a mappés à une maladie
        foreach ($sommeParDataElement as $deId => $valeur) {
            if (!isset($maladieParDeId[$deId])) {
                continue; // data element présent dans le dataSet mais pas mappé à une maladie suivie
            }
            $maladie = $maladieParDeId[$deId];

            $checkStmt->execute([
                ':commune' => $ou['commune'],
                ':date'    => $date,
                ':maladie' => $maladie,
            ]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                $updateStmt->execute([':nb_cas' => $valeur, ':id' => $existing['id']]);
            } else {
                $insertStmt->execute([
                    ':commune' => $ou['commune'],
                    ':date'    => $date,
                    ':maladie' => $maladie,
                    ':nb_cas'  => $valeur,
                ]);
            }
            $totalLignes++;
        }

        usleep(150000); // pause : 1 seul appel par (commune, période) désormais
    }

    $resultats[] = ['commune' => $ou['commune'], 'lignes_importees' => $totalLignes];
}

jsonResponse([
    'success' => true,
    'dataset' => DHIS2_DATASET_UID,
    'periodes' => $periodes,
    'lot' => ['limit' => $limit, 'offset' => $offset, 'suivant' => $offset + $limit],
    'resultats' => $resultats,
]);
