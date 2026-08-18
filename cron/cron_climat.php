<?php
/**
 * Tâche planifiée quotidienne : import des données climatiques (NASA POWER)
 * pour les communes déjà mappées à DHIS2 (only_mapped=1).
 *
 *   https://tonsite.infinityfreeapp.com/cron/cron_climat.php?token=TON_TOKEN&limit=200&offset=0
 */

require_once __DIR__ . '/../config/config.php';

if (($_GET['token'] ?? '') !== CRON_TOKEN) {
    http_response_code(403);
    die('Accès refusé.');
}

// Récupère les 3 derniers jours (couvre les éventuels retards de publication NASA POWER)
$_GET['start'] = $_GET['start'] ?? date('Ymd', strtotime('-3 days'));
$_GET['end']   = $_GET['end']   ?? date('Ymd');
$_GET['only_mapped'] = '1';

require __DIR__ . '/../api/import_climat.php';
