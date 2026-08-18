<?php
/**
 * Client minimal pour l'API DHIS2
 * Doc API : https://docs.dhis2.org/en/develop/using-the-api/dhis-core-version-master/introduction.html
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Construit une query string supportant les paramètres répétés
 * (nécessaire pour DHIS2 : ?filter=a&filter=b, pas filter[]=a&filter[]=b)
 */
function dhis2BuildQuery(array $params): string
{
    $parts = [];
    foreach ($params as $key => $value) {
        foreach ((array) $value as $v) {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $v);
        }
    }
    return implode('&', $parts);
}

/**
 * Effectue une requête GET authentifiée sur l'API DHIS2
 */
function dhis2Request(string $endpoint, array $params = []): ?array
{
    $url = rtrim(DHIS2_BASE_URL, '/') . $endpoint;
    if (!empty($params)) {
        $url .= '?' . dhis2BuildQuery($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => DHIS2_USERNAME . ':' . DHIS2_PASSWORD,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        error_log("Erreur DHIS2 (HTTP $httpCode) sur $endpoint : $error");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Normalise un nom pour comparaison (minuscules, sans accents, espaces réduits)
 */
function normaliserNom(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace(['’', "'", '-'], ' ', $s);
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($translit !== false) $s = $translit;
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/**
 * Recherche des unités d'organisation (communes/CSB) par nom
 */
function dhis2SearchOrgUnits(string $query, int $limit = 20): array
{
    $data = dhis2Request('/api/organisationUnits.json', [
        'filter' => "name:ilike:$query",
        'fields' => 'id,name,level,path,parent[name]',
        'pageSize' => $limit,
    ]);
    return $data['organisationUnits'] ?? [];
}

/**
 * Recherche des unités d'organisation par nom ET niveau hiérarchique précis
 * (utilisé par le mapping automatique pour éviter les doublons de niveaux)
 */
function dhis2SearchOrgUnitsByLevel(string $nom, int $niveau, int $limit = 10): array
{
    $data = dhis2Request('/api/organisationUnits.json', [
        'filter' => ["name:ilike:$nom", "level:eq:$niveau"],
        'fields' => 'id,name,level',
        'pageSize' => $limit,
    ]);
    return $data['organisationUnits'] ?? [];
}

/**
 * Recherche des éléments de données (data elements) par nom
 * Ce sont typiquement les "maladies" ou indicateurs suivis dans DHIS2
 */
function dhis2SearchDataElements(string $query, int $limit = 20): array
{
    $data = dhis2Request('/api/dataElements.json', [
        'filter' => "name:ilike:$query",
        'fields' => 'id,name,valueType,aggregationType',
        'pageSize' => $limit,
    ]);
    return $data['dataElements'] ?? [];
}

/**
 * Récupère TOUTES les valeurs d'un dataSet DHIS2 pour une unité d'organisation
 * et une période données (un seul appel réseau au lieu d'un par data element).
 *
 * Endpoint : GET /api/dataValueSets.json?dataSet=DATASET_UID&period=PERIOD&orgUnit=ORGUNIT_UID
 *
 * Retourne le tableau brut `dataValues` (chaque entrée contient dataElement,
 * categoryOptionCombo, value...). À sommer par data element côté appelant si
 * plusieurs combinaisons de catégories existent (âge, sexe, etc.).
 */
function dhis2GetDataValueSet(string $datasetUid, string $orgUnitId, string $period): array
{
    $data = dhis2Request('/api/dataValueSets.json', [
        'dataSet'  => $datasetUid,
        'period'   => $period,
        'orgUnit'  => $orgUnitId,
    ]);

    return $data['dataValues'] ?? [];
}

/**
 * Regroupe et somme les valeurs d'un dataValueSet par data element
 * (toutes catégories confondues : âge, sexe, etc.)
 *
 * @param array $dataValues Résultat de dhis2GetDataValueSet()
 * @return array<string, float> [dataElementId => somme]
 */
function dhis2SommeParDataElement(array $dataValues): array
{
    $sommes = [];
    foreach ($dataValues as $dv) {
        $deId = $dv['dataElement'] ?? null;
        if ($deId === null) continue;
        $sommes[$deId] = ($sommes[$deId] ?? 0.0) + (float) ($dv['value'] ?? 0);
    }
    return $sommes;
}

/**
 * Récupère la valeur agrégée (somme sur toutes les catégories) d'un SEUL data element
 * pour une unité d'organisation et une période données (YYYYMM).
 * Conservé pour le diagnostic/tests ponctuels ; l'import en masse utilise plutôt
 * dhis2GetDataValueSet() (un seul appel pour tout le dataSet).
 */
function dhis2GetAggregateValue(string $dataElementId, string $orgUnitId, string $period): ?float
{
    $data = dhis2Request('/api/dataValueSets.json', [
        'dataElement' => $dataElementId,
        'period'      => $period,
        'orgUnit'     => $orgUnitId,
    ]);

    if ($data === null || empty($data['dataValues'])) {
        return null;
    }

    $total = 0.0;
    foreach ($data['dataValues'] as $dv) {
        $total += (float) $dv['value'];
    }

    return $total;
}

/**
 * Génère la liste des périodes mensuelles DHIS2 (YYYYMM) entre deux mois inclus
 */
function genererPeriodesMensuelles(string $debut, string $fin): array
{
    $periodes = [];
    $date = DateTime::createFromFormat('Ym', $debut);
    $dateFin = DateTime::createFromFormat('Ym', $fin);

    while ($date <= $dateFin) {
        $periodes[] = $date->format('Ym');
        $date->modify('+1 month');
    }

    return $periodes;
}
