<?php
/**
 * Configuration générale du projet
 * Climat & Santé - Corrélation climatique et épidémiologique
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('Indian/Antananarivo');

// --- Base de données (InfinityFree) ---
define('DB_HOST', 'sql203.infinityfree.com');
define('DB_NAME', 'if0_42321663_analyse');
define('DB_USER', 'if0_42321663');
define('DB_PASS', 'eiHBNbiggbTZE'); // à compléter

// --- API NASA POWER ---
// T2M = température, PRECTOTCORR = précipitation, RH2M = humidité, WS2M = vent, ALLSKY_SFC_SW_DWN = rayonnement
define('NASA_POWER_BASE_URL', 'https://power.larc.nasa.gov/api/temporal/daily/point');
define('NASA_POWER_PARAMETERS', 'T2M,PRECTOTCORR,RH2M,WS2M,ALLSKY_SFC_SW_DWN');
define('NASA_POWER_COMMUNITY', 'AG');

// --- Chemins ---
define('BASE_URL', ''); // vide si déployé à la racine de htdocs
define('ROOT_PATH', dirname(__DIR__));

// --- Seuils de risque (basés sur le nombre de cas, à affiner) ---
define('SEUIL_RISQUE_FAIBLE', 33);
define('SEUIL_RISQUE_MOYEN', 66);


define('DHIS2_BASE_URL', 'https://simr.snis-sante.net'); // à remplacer par ton instance réelle
define('DHIS2_USERNAME', 'Eric');
define('DHIS2_PASSWORD', 'Eric_2022');

// Niveau hiérarchique DHIS2 qui correspond à "commune" dans ta base.
// IMPORTANT : détermine cette valeur avec api/dhis2_test_connexion.php avant d'activer
// le mapping automatique (voir le tableau "niveaux_organisation" renvoyé).
// Laisse à null tant que tu n'as pas vérifié, le mapping auto des communes sera alors ignoré.
define('DHIS2_NIVEAU_COMMUNE', 4); // exemple : à ajuster selon ton instance

// UID du dataSet DHIS2 à interroger (surveillance épidémiologique).
// Trouve-le via api/dhis2_test_connexion.php (liste "jeux_de_donnees"), puis colle
// l'id ici (ex: 'BfMAe6Itzgt'). Laisse vide tant que non déterminé.
define('DHIS2_DATASET_UID', 'OgBNvarRJPv');

// Jeton de sécurité pour les scripts déclenchés par webcron (cron/*.php)
define('CRON_TOKEN', 'r0aJc1wAOtldqkzgmIfvuqvSGIpMc9WO');
