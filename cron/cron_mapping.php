<?php
/**
 * Tâche planifiée quotidienne : mapping automatique communes/maladies DHIS2
 * À appeler via un webcron externe (ex: cron-job.org), une fois par jour :
 *
 *   https://tonsite.infinityfreeapp.com/cron/cron_mapping.php?token=TON_TOKEN&limit=100&offset=0
 *
 * Si tu as plus de 100 communes à mapper, programme plusieurs appels avec des
 * offsets différents (0, 100, 200...), ou augmente `limit` (au prix d'un temps
 * d'exécution plus long — attention à la limite InfinityFree).
 */

require_once __DIR__ . '/../config/config.php';

if (($_GET['token'] ?? '') !== CRON_TOKEN) {
    http_response_code(403);
    die('Accès refusé.');
}

require __DIR__ . '/../api/dhis2_auto_mapping.php';
