<?php
/**
 * Diagnostic de connexion DHIS2
 * -------------------------------
 * À utiliser en premier, avant tout mapping, pour :
 *  1) vérifier que l'authentification fonctionne
 *  2) identifier le NIVEAU hiérarchique des unités d'organisation qui correspond
 *     à tes "communes" (ça peut être niveau 3, 4, 5... selon l'instance)
 *  3) lister les jeux de données (dataSets) disponibles, pour repérer ceux liés
 *     à la surveillance épidémiologique
 *
 * Utilisation : /api/dhis2_test_connexion.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dhis2_client.php';

// 1. Test d'authentification simple : infos système
$systemInfo = dhis2Request('/api/system/info.json', ['fields' => 'version,serverDate,contextPath']);

if ($systemInfo === null) {
    jsonResponse([
        'success' => false,
        'message' => "Échec de connexion à DHIS2. Vérifie DHIS2_BASE_URL, DHIS2_USERNAME, DHIS2_PASSWORD dans config/config.php, et que le serveur est bien joignable (voir logs PHP pour le détail cURL).",
    ], 500);
}

// 2. Niveaux hiérarchiques des unités d'organisation
$niveaux = dhis2Request('/api/organisationUnitLevels.json', ['fields' => 'id,name,level', 'order' => 'level:asc']);

// 3. Comptage d'unités d'organisation par niveau (échantillon des 5 premières par niveau)
$echantillonParNiveau = [];
if (!empty($niveaux['organisationUnitLevels'])) {
    foreach ($niveaux['organisationUnitLevels'] as $niveau) {
        $data = dhis2Request('/api/organisationUnits.json', [
            'filter' => 'level:eq:' . $niveau['level'],
            'fields' => 'id,name',
            'pageSize' => 5,
        ]);
        $echantillonParNiveau[] = [
            'level' => $niveau['level'],
            'name'  => $niveau['name'],
            'total' => $data['pager']['total'] ?? null,
            'exemples' => array_column($data['organisationUnits'] ?? [], 'name'),
        ];
    }
}

// 4. Jeux de données disponibles
$dataSets = dhis2Request('/api/dataSets.json', ['fields' => 'id,name,periodType', 'pageSize' => 50]);

// 5. Optionnel : détail d'un dataSet précis (ses data elements), pour choisir DHIS2_DATASET_UID
$datasetDetail = null;
$datasetUidDemande = $_GET['dataset'] ?? null;
if ($datasetUidDemande) {
    $datasetDetail = dhis2Request("/api/dataSets/$datasetUidDemande.json", [
        'fields' => 'id,name,periodType,dataSetElements[dataElement[id,name]]',
    ]);
}

jsonResponse([
    'success' => true,
    'connexion' => 'OK',
    'dhis2_version' => $systemInfo['version'] ?? null,
    'niveaux_organisation' => $echantillonParNiveau,
    'jeux_de_donnees' => $dataSets['dataSets'] ?? [],
    'detail_dataset' => $datasetDetail,
    'astuce' => "Compare les noms d'exemples ci-dessus (niveaux_organisation) avec les noms de ta table `communes` pour repérer le bon niveau à utiliser dans le mapping (pages/dhis2.php). Pour choisir DHIS2_DATASET_UID, rappelle ce script avec ?dataset=UID pour voir la liste de ses data elements et vérifier qu'il contient bien tes maladies suivies (paludisme, diarrhée, etc.).",
]);
