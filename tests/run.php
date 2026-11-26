<?php
// Tests d'intégration. Prérequis : voir tests/run.sh. Efface les tables de la base de TEST.
$base = getenv('TEST_URL') ?: 'http://127.0.0.1:8000';
$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!str_contains((string) getenv('DB_NAME'), 'test')) exit("Refus : la base doit contenir « test » dans son nom.\n");
foreach (['communes', 'climat', 'sante', 'dhis2_ou_mapping', 'dhis2_de_mapping', 'app_state', 'login_attempts', 'correlations'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec(file_get_contents(__DIR__ . '/../sql/schema.sql'));
foreach (glob('/tmp/login-*.json') as $f) unlink($f);
$ins = $pdo->prepare('INSERT INTO communes (code, nom, district, region, latitude, longitude) VALUES (?,?,?,?,?,?)');
foreach ([['A','Commune Alpha','District 1','Région Test',-18.9,47.5], ['B','Commune Bêta','District 1','Région Test',-19.8,47.0],
          ['C','Commune Gamma','District 2','Région Test',-17.5,48.2], ['D','<b>XSS</b>','District 2','Région Test',-21.4,47.1]] as $c) $ins->execute($c);

$jar = tempnam(sys_get_temp_dir(), 'jar');
$fail = 0;
function http(string $method, string $path, array $headers = [], ?string $body = null, bool $cookies = true): array {
    global $base, $jar;
    $ch = curl_init($base . $path);
    $respHeaders = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$respHeaders) { $respHeaders .= $h; return strlen($h); }, CURLOPT_TIMEOUT => 60]);
    if ($cookies) curl_setopt_array($ch, [CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return ['status' => $status, 'body' => $raw, 'json' => json_decode($raw, true), 'headers' => $respHeaders];
}
function check(string $label, bool $ok, mixed $debug = null): void {
    global $fail;
    echo ($ok ? '  ok    ' : '  ÉCHEC ') . $label . "\n";
    if (!$ok) { $fail++; if ($debug) echo '        ' . substr(is_string($debug) ? $debug : json_encode($debug), 0, 600) . "\n"; }
}

echo "Accès\n";
check('page protégée → /login', http('GET', '/')['status'] === 303);
check('API protégée → 401', http('GET', '/api/communes')['status'] === 401);
check('import protégé → 401', http('POST', '/api/import/sante')['status'] === 401);
check('cron sans secret → 401', http('GET', '/cron/sante')['status'] === 401);
check('cron avec jeton en URL → 401', http('GET', '/cron/sante?token=' . getenv('CRON_SECRET'))['status'] === 401);
foreach (['/app/Core/Config.php', '/vercel.json', '/sql/schema.sql', '/.env.example'] as $p) {
    check("fichier interne non servi : $p", !str_contains(http('GET', $p)['body'], 'APP_SECRET') && http('GET', $p)['status'] === 303);
}
$r = http('GET', '/login');
check('en-têtes de sécurité', str_contains($r['headers'], "script-src 'self'") && str_contains($r['headers'], 'X-Frame-Options: DENY'));
check('origine étrangère refusée', http('POST', '/login', ['Origin: https://evil.example'], 'utilisateur=admin&mot_de_passe=x')['status'] === 403);
check('mauvais mot de passe → 401', http('POST', '/login', [], 'utilisateur=admin&mot_de_passe=mauvais')['status'] === 401);
$r = http('POST', '/login', [], http_build_query(['utilisateur' => 'admin', 'mot_de_passe' => getenv('TEST_PASSWORD')]));
check('connexion → 303 + cookie HttpOnly Strict', $r['status'] === 303 && stripos($r['headers'], 'HttpOnly') !== false && stripos($r['headers'], 'SameSite=Strict') !== false, $r['headers']);
$page = http('GET', '/');
preg_match('/csrf-token" content="([a-f0-9]{64})"/', $page['body'], $m);
$csrf = $m[1] ?? '';
check('tableau de bord affiché avec jeton CSRF', $page['status'] === 200 && $csrf !== '', $page['body']);
foreach (['/carte', '/communes', '/import-climat', '/import-dhis2'] as $p) check("page $p", http('GET', $p)['status'] === 200);
$post = fn(string $p, array $d, bool $token = true) => http('POST', $p, array_merge(['Content-Type: application/json'], $token ? ["X-CSRF-Token: $csrf"] : []), json_encode($d));
check('POST sans CSRF → 403', $post('/api/communes', ['nom' => 'x'], false)['status'] === 403);
check('GET sur action → 404', http('GET', '/api/import/sante')['status'] === 404);
$cookie = file_get_contents($jar);
preg_match('/cs_session\s+(\S+)/', $cookie, $m);
check('cookie falsifié refusé', http('GET', '/api/communes', ['Cookie: cs_session=' . strrev($m[1] ?? 'a.b')], null, false)['status'] === 401);

echo "Données\n";
$r = http('GET', '/api/dashboard/summary'); check('résumé sur base vide', $r['status'] === 200 && $r['json']['sante']['cas'] === null, $r['body']);
$r = $post('/api/communes', ['nom' => 'Commune Delta', 'latitude' => '-20', 'longitude' => '47']); check('ajout commune', $r['status'] === 200, $r['body']);
check('latitude invalide → 422', $post('/api/communes', ['nom' => 'z', 'latitude' => '200'])['status'] === 422);
$r = http('GET', '/api/communes?q=' . urlencode('%')); check('joker de recherche neutralisé', $r['json']['total'] === 0, $r['body']);

echo "DHIS2\n";
$r = http('GET', '/api/dhis2/overview'); check('diagnostic', ($r['json']['version'] ?? '') === '2.40-test' && $r['json']['niveaux'][0]['total'] === 3, $r['body']);
check('UID invalide → 422', http('GET', '/api/dhis2/dataset?id=' . urlencode('../me'))['status'] === 422);
$r = $post('/api/dhis2/mappings/auto', ['limit' => 20, 'offset' => 0]); check('mapping automatique', $r['status'] === 200 && count($r['json']['communes']['mappees']) === 5, $r['body']);
$pdo->exec('DELETE FROM dhis2_de_mapping');
foreach (['DeTest00001', 'DeTest00002'] as $de) $post('/api/dhis2/mappings', ['type' => 'maladie', 'id' => $de, 'maladie' => 'paludisme']);
check('commune inconnue refusée', $post('/api/dhis2/mappings', ['type' => 'commune', 'id' => 'OuTest00001', 'commune' => 'Nulle'])['status'] === 422);
$month = date('Ym');
$r = $post('/api/import/sante', ['debut' => date('Ym', strtotime('first day of -3 month')), 'fin' => $month, 'limit' => 2, 'offset' => 0]);
check('import santé par lot', $r['status'] === 200 && $r['json']['lot']['suivant'] === 2 && !$r['json']['lot']['termine'], $r['body']);
$v = $pdo->query('SELECT MIN(nb_cas), MAX(nb_cas), COUNT(*) FROM sante')->fetch(PDO::FETCH_NUM);
check('sommes : deux éléments d\'une maladie additionnés (15), non numérique ignoré', $v == [15, 15, 8], $v);
$post('/api/import/sante', ['debut' => $month, 'fin' => $month, 'limit' => 2, 'offset' => 0]);
check('réimport sans doublon', (int) $pdo->query('SELECT COUNT(*) FROM sante')->fetchColumn() === 8);
$pdo->exec("UPDATE dhis2_ou_mapping SET ou_id = 'OuInterdit1' WHERE commune = 'Commune Gamma'");
$r = $post('/api/import/sante', ['debut' => $month, 'fin' => $month, 'limit' => 100, 'offset' => 0]);
$errors = array_values(array_filter($r['json']['resultats'] ?? [], fn($x) => $x['erreur']));
check('unité refusée isolée, les autres importées', count($errors) === 1 && $errors[0]['commune'] === 'Commune Gamma' && (int) $pdo->query('SELECT COUNT(*) FROM sante')->fetchColumn() === 10, $r['body']);
check('plus de 12 mois → 422', $post('/api/import/sante', ['debut' => '202401', 'fin' => '202512'])['status'] === 422);

echo "Climat et tableau de bord\n";
$r = $post('/api/import/climat', ['debut' => date('Y-m-d', strtotime('-100 days')), 'fin' => date('Y-m-d'), 'limit' => 20, 'offset' => 0, 'mappees' => '1']);
check('import climat', $r['status'] === 200 && $r['json']['lot']['termine'] && $r['json']['resultats'][0]['lignes'] === 101, $r['body']);
$post('/api/import/climat', ['debut' => date('Y-m-d', strtotime('-5 days')), 'fin' => date('Y-m-d'), 'limit' => 20]);
check('réimport climat sans doublon', (int) $pdo->query('SELECT COUNT(*) FROM climat')->fetchColumn() === 505);
check('-999 converti en NULL', (int) $pdo->query('SELECT COUNT(rayonnement) FROM climat')->fetchColumn() === 0);
$r = http('GET', '/api/dashboard/summary'); check('résumé', $r['json']['sante']['cas'] > 0 && $r['json']['climat']['temperature'] > 15, $r['body']);
$r = http('GET', '/api/dashboard/series?mois=6'); check('séries : une ligne par jour', count($r['json']['climat']) === 101, count($r['json']['climat'] ?? []));
check('cache privé sur les lectures', str_contains($r['headers'], 'Cache-Control: private'));
check('filtres', count(http('GET', '/api/dashboard/filters')['json']['communes']) === 5);
$r = http('GET', '/api/correlation?maladie=paludisme&climat=temperature'); check('corrélation', $r['status'] === 200 && $r['json']['nb_observations'] > 0, $r['body']);
check('variable injectée refusée', http('GET', '/api/correlation?maladie=paludisme&climat=' . urlencode('1) --'))['status'] === 422);
$r = http('GET', '/api/map'); check('carte', count($r['json']['communes']) === 5, $r['body']);

echo "Tâches planifiées\n";
foreach (['sante', 'climat', 'mapping'] as $job) {
    $r = http('GET', "/cron/$job", ['Authorization: Bearer ' . getenv('CRON_SECRET')], null, false);
    check("cron $job", $r['status'] === 200, $r['body']);
}
echo "Déconnexion et force brute\n";
check('déconnexion', http('POST', '/logout', [], "csrf=$csrf")['status'] === 303 && http('GET', '/api/communes')['status'] === 401);
for ($i = 0; $i < 5; $i++) http('POST', '/login', [], 'utilisateur=admin&mot_de_passe=faux');
check('blocage après 5 échecs (429)', http('POST', '/login', [], http_build_query(['utilisateur' => 'admin', 'mot_de_passe' => getenv('TEST_PASSWORD')]))['status'] === 429);
$pdo->exec('DELETE FROM login_attempts');
foreach (glob('/tmp/login-*.json') as $f) unlink($f);

echo $fail ? "\n$fail échec(s)\n" : "\nTous les tests passent.\n";
exit($fail ? 1 : 0);
