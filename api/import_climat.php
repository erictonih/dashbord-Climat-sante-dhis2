<?php
/**
 * Import automatique des données climatiques
 * ------------------------------------------
 * Table communes → Lat/Lon → API NASA POWER → JSON → Table climat
 *
 * IMPORTANT : dans ta base réelle, la table `climat` référence la commune par
 * son NOM (colonne `commune`), pas par un commune_id.
 *
 * Utilisation :
 *   - une commune : /api/import_climat.php?commune=Ambositra&start=20250101&end=20250131
 *   - toutes les communes : /api/import_climat.php?start=20250101&end=20250131
 *   - par lot : /api/import_climat.php?limit=50&offset=0
 *   - uniquement les communes déjà mappées à DHIS2 : ...&only_mapped=1
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

set_time_limit(0);

$pdo = getDB();

$communeNom = $_GET['commune'] ?? null;
$start = trim($_GET['start'] ?? '') ?: date('Ymd', strtotime('-30 days'));
$end   = trim($_GET['end']   ?? '') ?: date('Ymd');

if (!preg_match('/^\d{8}$/', $start) || !preg_match('/^\d{8}$/', $end)) {
    jsonResponse([
        'success' => false,
        'message' => "Format de date invalide (attendu : AAAAMMJJ, ex: 20250101). Reçu : start=\"$start\", end=\"$end\".",
    ], 400);
}
$limit  = isset($_GET['limit'])  ? (int) $_GET['limit']  : 20;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$onlyMapped = ($_GET['only_mapped'] ?? '0') === '1';

/**
 * Récupère les données climatiques via l'API NASA POWER
 */
function fetchNasaPower(float $lat, float $lon, string $start, string $end): ?array
{
    $url = NASA_POWER_BASE_URL . '?' . http_build_query([
        'parameters' => NASA_POWER_PARAMETERS,
        'community'  => NASA_POWER_COMMUNITY,
        'longitude'  => $lon,
        'latitude'   => $lat,
        'start'      => $start,
        'end'        => $end,
        'format'     => 'JSON',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        error_log("Erreur NASA POWER (HTTP $httpCode) pour $lat,$lon : $error");
        return null;
    }

    $data = json_decode($response, true);
    return $data['properties']['parameter'] ?? null;
}

/**
 * Enregistre les données climatiques d'une commune en base
 * (dédoublonnage manuel car la table `climat` n'a pas de clé unique (commune, date))
 */
function importCommune(PDO $pdo, array $commune, string $start, string $end): int
{
    if (empty($commune['latitude']) || empty($commune['longitude'])) {
        return 0;
    }

    $parametres = fetchNasaPower((float) $commune['latitude'], (float) $commune['longitude'], $start, $end);
    if ($parametres === null) {
        return 0;
    }

    $dates = array_keys($parametres['T2M'] ?? []);
    if (empty($dates)) {
        return 0;
    }

    $checkStmt = $pdo->prepare('SELECT id FROM climat WHERE commune = :commune AND date = :date LIMIT 1');
    $insertStmt = $pdo->prepare("
        INSERT INTO climat (commune, date, temperature, precipitation, pluie, humidite, vent, rayonnement)
        VALUES (:commune, :date, :temperature, :precipitation, :pluie, :humidite, :vent, :rayonnement)
    ");
    $updateStmt = $pdo->prepare("
        UPDATE climat SET temperature = :temperature, precipitation = :precipitation, pluie = :pluie,
            humidite = :humidite, vent = :vent, rayonnement = :rayonnement
        WHERE id = :id
    ");

    $count = 0;
    foreach ($dates as $dateBrute) {
        $date = DateTime::createFromFormat('Ymd', $dateBrute)->format('Y-m-d');

        $temperature   = $parametres['T2M'][$dateBrute] ?? null;
        $precipitation = $parametres['PRECTOTCORR'][$dateBrute] ?? null;
        $humidite      = $parametres['RH2M'][$dateBrute] ?? null;
        $vent          = $parametres['WS2M'][$dateBrute] ?? null;
        $rayonnement   = $parametres['ALLSKY_SFC_SW_DWN'][$dateBrute] ?? null;

        foreach ([$temperature, $precipitation, $humidite, $vent, $rayonnement] as &$val) {
            if ($val !== null && (float) $val <= -900) $val = null;
        }
        unset($val);

        $params = [
            ':commune'       => $commune['nom'],
            ':date'          => $date,
            ':temperature'   => $temperature,
            ':precipitation' => $precipitation,
            ':pluie'         => $precipitation,
            ':humidite'      => $humidite,
            ':vent'          => $vent,
            ':rayonnement'   => $rayonnement,
        ];

        $checkStmt->execute([':commune' => $commune['nom'], ':date' => $date]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $updateParams = $params;
            unset($updateParams[':commune'], $updateParams[':date']);
            $updateParams[':id'] = $existing['id'];
            $updateStmt->execute($updateParams);
        } else {
            $insertStmt->execute($params);
        }
        $count++;
    }

    return $count;
}

// --- Sélection des communes à traiter ---
if ($communeNom) {
    $stmt = $pdo->prepare('SELECT id, nom, latitude, longitude FROM communes WHERE nom = :nom LIMIT 1');
    $stmt->execute([':nom' => $communeNom]);
    $communes = $stmt->fetchAll();
} elseif ($onlyMapped) {
    // Ne récupère la météo que pour les communes déjà mappées à DHIS2
    $stmt = $pdo->prepare('
        SELECT co.id, co.nom, co.latitude, co.longitude
        FROM communes co
        INNER JOIN dhis2_ou_mapping m ON m.commune = co.nom
        WHERE co.latitude IS NOT NULL AND co.longitude IS NOT NULL
        ORDER BY co.id
        LIMIT :limit OFFSET :offset
    ');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $communes = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare('
        SELECT id, nom, latitude, longitude FROM communes
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL
        ORDER BY id
        LIMIT :limit OFFSET :offset
    ');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $communes = $stmt->fetchAll();
}

$resultats = [];
foreach ($communes as $commune) {
    $nbLignes = importCommune($pdo, $commune, $start, $end);
    $resultats[] = ['commune' => $commune['nom'], 'lignes_importees' => $nbLignes];
    usleep(300000);
}

jsonResponse([
    'success' => true,
    'periode' => ['start' => $start, 'end' => $end],
    'lot' => ['limit' => $limit, 'offset' => $offset, 'suivant' => $offset + $limit],
    'resultats' => $resultats,
]);
