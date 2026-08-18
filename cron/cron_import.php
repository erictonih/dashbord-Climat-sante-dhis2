<?php
/**
 * Point d'entrée pour tâche planifiée (webcron) — import climat SANS filtre DHIS2
 * -----------------------------------------------
 * Importe la météo pour TOUTES les communes géolocalisées (potentiellement ~1700+).
 * Pour un import quotidien limité aux communes suivies côté santé, utilise plutôt
 * cron/cron_climat.php (plus rapide, et suffisant pour la corrélation climat/santé).
 *
 * InfinityFree ne permet pas de vrais cron jobs serveur.
 * Utilisez un service de webcron externe et gratuit (ex: cron-job.org)
 * pour appeler cette URL toutes les 24h, par exemple :
 *
 *   https://tonsite.infinityfreeapp.com/cron/cron_import.php?token=TON_TOKEN&limit=100&offset=0
 *
 * Le jeton CRON_TOKEN est défini dans config/config.php.
 */

require_once __DIR__ . '/../config/config.php';

if (($_GET['token'] ?? '') !== CRON_TOKEN) {
    http_response_code(403);
    die('Accès refusé.');
}

// Importe les données des 3 derniers jours (par lot, voir limit/offset dans l'URL)
$_GET['start'] = $_GET['start'] ?? date('Ymd', strtotime('-3 days'));
$_GET['end']   = $_GET['end']   ?? date('Ymd');

require __DIR__ . '/../api/import_climat.php';
