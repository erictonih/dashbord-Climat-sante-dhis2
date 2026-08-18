<?php
/**
 * Module de corrélation Climat ↔ Santé
 * -------------------------------------
 * Les données climat (NASA POWER) sont quotidiennes, les données de santé
 * (DHIS2, agrégées) sont mensuelles. On calcule donc la corrélation au niveau
 * du MOIS : moyenne climatique du mois vs total de cas du mois, par commune.
 *
 * Exemple : /api/correlation.php?climat=temperature&sante=paludisme&commune=Ambositra
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// Table de cache des résultats (créée automatiquement si absente, non destructif)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS correlations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commune VARCHAR(150) DEFAULT NULL,
        variable_climat VARCHAR(50) NOT NULL,
        variable_sante VARCHAR(100) NOT NULL,
        coefficient_pearson DECIMAL(6,4) NOT NULL,
        nb_observations INT NOT NULL,
        calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$variablesClimatValides = ['temperature', 'precipitation', 'pluie', 'humidite', 'vent', 'rayonnement'];

$climatVar = $_GET['climat'] ?? 'temperature';
$santeVar  = $_GET['sante'] ?? 'paludisme';
$commune   = $_GET['commune'] ?? null;

if (!in_array($climatVar, $variablesClimatValides)) {
    jsonResponse(['success' => false, 'message' => 'Variable climatique invalide'], 400);
}

// --- Moyenne climatique mensuelle par commune ---
$sqlClimat = "
    SELECT commune, DATE_FORMAT(date, '%Y-%m') AS mois, AVG($climatVar) AS valeur
    FROM climat
    WHERE $climatVar IS NOT NULL
";
$params = [];
if ($commune) {
    $sqlClimat .= ' AND commune = :commune';
    $params[':commune'] = $commune;
}
$sqlClimat .= ' GROUP BY commune, mois';

$stmt = $pdo->prepare($sqlClimat);
$stmt->execute($params);
$climatParMois = [];
foreach ($stmt->fetchAll() as $row) {
    $climatParMois[$row['commune'] . '|' . $row['mois']] = (float) $row['valeur'];
}

// --- Total de cas mensuel par commune (issu de DHIS2 via table sante) ---
$sqlSante = "
    SELECT commune, DATE_FORMAT(date, '%Y-%m') AS mois, SUM(nb_cas) AS total
    FROM sante
    WHERE maladie = :maladie
";
$paramsSante = [':maladie' => $santeVar];
if ($commune) {
    $sqlSante .= ' AND commune = :commune';
    $paramsSante[':commune'] = $commune;
}
$sqlSante .= ' GROUP BY commune, mois';

$stmt = $pdo->prepare($sqlSante);
$stmt->execute($paramsSante);
$santeParMois = [];
foreach ($stmt->fetchAll() as $row) {
    $santeParMois[$row['commune'] . '|' . $row['mois']] = (float) $row['total'];
}

// --- Jointure en PHP sur la clé commune|mois ---
$x = [];
$y = [];
foreach ($santeParMois as $cle => $totalCas) {
    if (isset($climatParMois[$cle])) {
        $x[] = $climatParMois[$cle];
        $y[] = $totalCas;
    }
}

$coefficient = pearsonCorrelation($x, $y);

if ($coefficient !== null) {
    $stmt = $pdo->prepare("
        INSERT INTO correlations (commune, variable_climat, variable_sante, coefficient_pearson, nb_observations)
        VALUES (:commune, :climat, :sante, :coef, :n)
    ");
    $stmt->execute([
        ':commune' => $commune,
        ':climat'  => $climatVar,
        ':sante'   => $santeVar,
        ':coef'    => $coefficient,
        ':n'       => count($x),
    ]);
}

jsonResponse([
    'success' => true,
    'variable_climat' => $climatVar,
    'variable_sante' => $santeVar,
    'commune' => $commune,
    'granularite' => 'mensuelle',
    'nb_observations' => count($x),
    'coefficient_pearson' => $coefficient,
    'interpretation' => interpreterCoefficient($coefficient),
]);

function interpreterCoefficient(?float $r): string
{
    if ($r === null) return 'Non calculable (données insuffisantes)';
    $abs = abs($r);
    $sens = $r >= 0 ? 'positive' : 'négative';

    if ($abs < 0.2) return "Corrélation $sens très faible";
    if ($abs < 0.4) return "Corrélation $sens faible";
    if ($abs < 0.6) return "Corrélation $sens modérée";
    if ($abs < 0.8) return "Corrélation $sens forte";
    return "Corrélation $sens très forte";
}
