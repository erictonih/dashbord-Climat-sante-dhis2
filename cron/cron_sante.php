<?php
/**
 * Tâche planifiée quotidienne : import des données de santé DHIS2 (mois en cours
 * + mois précédent, pour capter les corrections/retards de saisie).
 *
 *   https://tonsite.infinityfreeapp.com/cron/cron_sante.php?token=TON_TOKEN&limit=200&offset=0
 */

require_once __DIR__ . '/../config/config.php';

if (($_GET['token'] ?? '') !== CRON_TOKEN) {
    http_response_code(403);
    die('Accès refusé.');
}

$_GET['period_start'] = $_GET['period_start'] ?? date('Ym', strtotime('-1 month'));
$_GET['period_end']   = $_GET['period_end']   ?? date('Ym');

require __DIR__ . '/../api/import_sante_dhis2.php';
