<?php
/**
 * Recherche d'unités d'organisation DHIS2 (pour mapper une commune à son ou_id)
 * Exemple : /api/dhis2_search_orgunits.php?q=Ambositra
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dhis2_client.php';

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    jsonResponse(['success' => false, 'message' => 'Requête trop courte (minimum 2 caractères)'], 400);
}

$resultats = dhis2SearchOrgUnits($q);

jsonResponse(['success' => true, 'resultats' => $resultats]);
