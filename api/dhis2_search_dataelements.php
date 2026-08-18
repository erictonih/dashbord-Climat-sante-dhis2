<?php
/**
 * Recherche d'éléments de données DHIS2 (pour mapper un indicateur à une maladie)
 * Exemple : /api/dhis2_search_dataelements.php?q=paludisme
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dhis2_client.php';

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    jsonResponse(['success' => false, 'message' => 'Requête trop courte (minimum 2 caractères)'], 400);
}

$resultats = dhis2SearchDataElements($q);

jsonResponse(['success' => true, 'resultats' => $resultats]);
