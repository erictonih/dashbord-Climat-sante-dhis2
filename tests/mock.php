<?php
// Faux serveurs DHIS2 et NASA POWER pour les tests (données entièrement fictives).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = [];
foreach (explode('&', $_SERVER['QUERY_STRING'] ?? '') as $part) {
    [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
    $query[rawurldecode($k)][] = urldecode($v);
}
header('Content-Type: application/json');
$out = fn($d) => print(json_encode($d));

if ($path === '/nasa') {
    $params = [];
    $d = new DateTime($query['start'][0]);
    $end = new DateTime($query['end'][0]);
    for ($i = 0; $d <= $end; $i++, $d->modify('+1 day')) {
        foreach (['T2M' => 22 + 3 * sin($i / 9), 'PRECTOTCORR' => max(0, 6 * sin($i / 5)), 'RH2M' => 75, 'WS2M' => 2.5, 'ALLSKY_SFC_SW_DWN' => -999] as $k => $v) {
            $params[$k][$d->format('Ymd')] = round($v, 2);
        }
    }
    return $out(['properties' => ['parameter' => $params]]);
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'ApiToken test-token') { http_response_code(401); return $out([]); }
if ($path === '/api/system/info.json') return $out(['version' => '2.40-test']);
if ($path === '/api/organisationUnitLevels.json') return $out(['organisationUnitLevels' => [['name' => 'Commune', 'level' => 4]]]);
if ($path === '/api/dataSets.json') return $out(['dataSets' => [['id' => 'DataSet0001', 'name' => 'Surveillance (test)', 'periodType' => 'Monthly']]]);
if ($path === '/api/organisationUnits.json') {
    $filter = implode(' ', $query['filter'] ?? []);
    preg_match('/name:ilike:(.*?)(?: level|$)/', $filter, $m);
    $name = $m[1] ?? 'Exemple';
    return $out(['pager' => ['total' => 3], 'organisationUnits' => [['id' => 'Ou' . substr(md5($name), 0, 9), 'name' => $name, 'level' => 4]]]);
}
if ($path === '/api/dataElements.json') return $out(['dataElements' => [['id' => 'DeTest00001', 'name' => '<img src=x onerror=alert(1)> Paludisme test']]]);
if ($path === '/api/dataValueSets.json') {
    // Une unité « interdite » fait échouer tout le paquet, comme un vrai DHIS2.
    if (in_array('OuInterdit1', $query['orgUnit'], true)) { http_response_code(409); return $out(['message' => 'interdit']); }
    $values = [];
    foreach ($query['orgUnit'] as $ou) foreach ($query['period'] as $pe) {
        foreach ([['DeTest00001', '5'], ['DeTest00001', '7'], ['DeTest00002', '3'], ['DeTest00002', 'abc']] as [$de, $v]) {
            $values[] = ['orgUnit' => $ou, 'period' => $pe, 'dataElement' => $de, 'value' => $v];
        }
    }
    return $out(['dataValues' => $values]);
}
http_response_code(404); $out([]);
