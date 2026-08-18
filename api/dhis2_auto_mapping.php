<?php
/**
 * Mapping automatique DHIS2
 * --------------------------
 *  - Communes (table `communes`) → unités d'organisation DHIS2, par correspondance
 *    exacte de nom (normalisée) au niveau hiérarchique DHIS2_NIVEAU_COMMUNE.
 *  - Maladies (config/dhis2_maladies.php) → data elements DHIS2, par mot-clé de recherche.
 *
 * Idempotent : ignore ce qui est déjà mappé. Pensé pour être appelé quotidiennement
 * (voir cron/cron_mapping.php) afin de mapper au fur et à mesure les nouvelles communes.
 *
 * Ne mappe QUE les correspondances non ambiguës (un seul résultat exact) ; les cas
 * incertains sont listés dans la réponse pour un mapping manuel via pages/dhis2.php.
 *
 * Utilisation : /api/dhis2_auto_mapping.php?limit=100&offset=0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dhis2_client.php';

set_time_limit(0);
$pdo = getDB();

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

$limit  = isset($_GET['limit'])  ? (int) $_GET['limit']  : 100;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

// --- 1. Mapping automatique des maladies (peu nombreuses : pas de pagination) ---
$maladiesConfig = file_exists(__DIR__ . '/../config/dhis2_maladies.php')
    ? require __DIR__ . '/../config/dhis2_maladies.php'
    : [];

$deExistants = $pdo->query('SELECT DISTINCT maladie FROM dhis2_de_mapping')->fetchAll(PDO::FETCH_COLUMN);
$maladiesMappees = [];
$maladiesIgnorees = [];

$stmtInsertDe = $pdo->prepare("
    INSERT INTO dhis2_de_mapping (de_id, de_name, maladie) VALUES (:id, :name, :maladie)
    ON DUPLICATE KEY UPDATE de_name = VALUES(de_name), maladie = VALUES(maladie)
");

foreach ($maladiesConfig as $maladie => $motCle) {
    if (in_array($maladie, $deExistants, true)) {
        continue; // déjà mappée, on ne touche pas (évite d'écraser un choix manuel)
    }

    $resultats = dhis2SearchDataElements($motCle, 5);

    if (count($resultats) === 0) {
        $maladiesIgnorees[] = ['maladie' => $maladie, 'mot_cle' => $motCle, 'raison' => 'aucun résultat DHIS2'];
        continue;
    }

    // On retient le premier résultat renvoyé par DHIS2 (le plus pertinent selon leur recherche)
    $de = $resultats[0];
    $stmtInsertDe->execute([':id' => $de['id'], ':name' => $de['name'], ':maladie' => $maladie]);

    $maladiesMappees[] = [
        'maladie' => $maladie,
        'de_id' => $de['id'],
        'de_name' => $de['name'],
        'ambigu' => count($resultats) > 1, // signalé mais mappé quand même (premier résultat)
    ];
}

// --- 2. Mapping automatique des communes (par lot, pour rester dans les limites d'exécution) ---
$communesMappees = [];
$communesIgnorees = [];

if (DHIS2_NIVEAU_COMMUNE === null) {
    $communesIgnorees[] = [
        'raison' => "DHIS2_NIVEAU_COMMUNE n'est pas défini dans config/config.php. " .
                    "Utilise api/dhis2_test_connexion.php pour identifier le bon niveau hiérarchique, puis renseigne-le.",
    ];
} else {
    $stmt = $pdo->prepare("
        SELECT co.id, co.nom
        FROM communes co
        LEFT JOIN dhis2_ou_mapping m ON m.commune = co.nom
        WHERE m.commune IS NULL AND co.nom IS NOT NULL AND co.nom <> ''
        ORDER BY co.id
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $communesAMapper = $stmt->fetchAll();

    $stmtInsertOu = $pdo->prepare("
        INSERT INTO dhis2_ou_mapping (commune, ou_id, ou_name) VALUES (:commune, :id, :name)
        ON DUPLICATE KEY UPDATE ou_id = VALUES(ou_id), ou_name = VALUES(ou_name)
    ");

    foreach ($communesAMapper as $commune) {
        $resultats = dhis2SearchOrgUnitsByLevel($commune['nom'], DHIS2_NIVEAU_COMMUNE, 5);

        $nomNormalise = normaliserNom($commune['nom']);
        $correspondancesExactes = array_values(array_filter(
            $resultats,
            fn($ou) => normaliserNom($ou['name']) === $nomNormalise
        ));

        if (count($correspondancesExactes) === 1) {
            $ou = $correspondancesExactes[0];
            $stmtInsertOu->execute([':commune' => $commune['nom'], ':id' => $ou['id'], ':name' => $ou['name']]);
            $communesMappees[] = ['commune' => $commune['nom'], 'ou_id' => $ou['id'], 'ou_name' => $ou['name']];
        } elseif (count($correspondancesExactes) > 1) {
            $communesIgnorees[] = ['commune' => $commune['nom'], 'raison' => 'plusieurs unités DHIS2 identiques à ce niveau (mapping manuel requis)'];
        } elseif (count($resultats) > 0) {
            $communesIgnorees[] = ['commune' => $commune['nom'], 'raison' => 'résultats trouvés mais aucun nom exact au niveau ciblé'];
        } else {
            $communesIgnorees[] = ['commune' => $commune['nom'], 'raison' => 'aucune unité DHIS2 trouvée à ce niveau'];
        }

        usleep(150000); // pause pour ne pas surcharger le serveur DHIS2
    }
}

jsonResponse([
    'success' => true,
    'maladies' => ['mappees' => $maladiesMappees, 'ignorees' => $maladiesIgnorees],
    'communes' => [
        'mappees' => $communesMappees,
        'ignorees' => $communesIgnorees,
        'lot' => ['limit' => $limit, 'offset' => $offset, 'suivant' => $offset + $limit],
    ],
]);
