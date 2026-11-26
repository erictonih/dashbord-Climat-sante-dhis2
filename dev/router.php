<?php
// Développement local : APP_ENV=local php -S 127.0.0.1:8000 -t public dev/router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/') || $path === '/robots.txt') return false;
require __DIR__ . '/../api/index.php';
