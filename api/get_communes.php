<?php
/**
 * Endpoint JSON : liste des communes avec dernières données climat/santé
 * + indice de risque calculé à la volée (pas de table `risques` dans ta base)
 * Utilisé par assets/js/map.js pour peupler la carte Leaflet
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// Ne charge que les communes géolocalisées ET ayant au moins une donnée climat
// (sur 1742 communes, évite de renvoyer des milliers de marqueurs vides)
$sql = "
    SELECT
        co.id, co.nom, co.district, co.region, co.latitude, co.longitude,
        cl.temperature, cl.precipitation, cl.humidite, cl.vent, cl.date AS date_climat
    FROM communes co
    INNER JOIN climat cl ON cl.commune = co.nom
        AND cl.date = (SELECT MAX(date) FROM climat WHERE commune = co.nom)
    WHERE co.latitude IS NOT NULL AND co.longitude IS NOT NULL
    GROUP BY co.id
    ORDER BY co.nom
";
$communes = $pdo->query($sql)->fetchAll();

// Cas de maladies des 30 derniers jours par commune
$stmtCas = $pdo->prepare("
    SELECT COALESCE(SUM(nb_cas), 0) AS total
    FROM sante
    WHERE commune = :nom AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");

$casParCommune = [];
foreach ($communes as $c) {
    $stmtCas->execute([':nom' => $c['nom']]);
    $casParCommune[$c['nom']] = (int) $stmtCas->fetchColumn();
}

$maxCas = !empty($casParCommune) ? max($casParCommune) : 0;

foreach ($communes as &$commune) {
    $nbCas = $casParCommune[$commune['nom']] ?? 0;
    $commune['nb_cas_30j'] = $nbCas;

    // Indice de risque simplifié = position relative par rapport au max de cas observé
    $indice = $maxCas > 0 ? round(($nbCas / $maxCas) * 100) : 0;
    $commune['indice_risque'] = $indice;
    $commune['niveau'] = niveauRisque($indice);
    $commune['couleur'] = couleurRisque($commune['niveau']);
}
unset($commune);

jsonResponse(['success' => true, 'nb_communes' => count($communes), 'communes' => $communes]);
