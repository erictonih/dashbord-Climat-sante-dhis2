<?php
/**
 * Endpoint JSON : séries temporelles (climat + maladies) pour Chart.js
 * Exemple : /api/get_climat_data.php?commune=Ambositra&start=2025-01-01&end=2025-12-31
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$commune = $_GET['commune'] ?? null;
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-6 months'));
$end   = $_GET['end']   ?? date('Y-m-d');

// --- Série climatique ---
$sqlClimat = "SELECT date, temperature, precipitation, humidite, vent, rayonnement FROM climat WHERE date BETWEEN :start AND :end";
$params = [':start' => $start, ':end' => $end];
if ($commune) {
    $sqlClimat .= ' AND commune = :commune';
    $params[':commune'] = $commune;
}
$sqlClimat .= ' ORDER BY date ASC';

$stmt = $pdo->prepare($sqlClimat);
$stmt->execute($params);
$climat = $stmt->fetchAll();

// --- Série des cas de maladies (regroupés par mois et par type) ---
$sqlSante = "
    SELECT DATE_FORMAT(date, '%Y-%m') AS mois, maladie, SUM(nb_cas) AS total
    FROM sante
    WHERE date BETWEEN :start AND :end
";
if ($commune) {
    $sqlSante .= ' AND commune = :commune';
}
$sqlSante .= ' GROUP BY mois, maladie ORDER BY mois ASC';

$stmt = $pdo->prepare($sqlSante);
$stmt->execute($params);
$santeRaw = $stmt->fetchAll();

$maladies = [];
foreach ($santeRaw as $row) {
    $maladies[$row['mois']][$row['maladie']] = (int) $row['total'];
}

jsonResponse([
    'success' => true,
    'periode' => ['start' => $start, 'end' => $end],
    'climat' => $climat,
    'maladies' => $maladies,
]);
