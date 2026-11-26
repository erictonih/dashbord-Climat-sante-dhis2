#!/usr/bin/env bash
# Lance l'application et les faux services puis les tests. Nécessite PHP (pdo_mysql, curl) et une base MySQL de test.
set -euo pipefail
cd "$(dirname "$0")/.."
export APP_ENV=local APP_USER=admin TEST_PASSWORD="mot-de-passe-de-test" APP_PASSWORD="mot-de-passe-de-test"
export APP_SECRET="secret-de-test-uniquement-0123456789abcdef" CRON_SECRET="cron-de-test-uniquement-0123456789"
export DB_HOST="${DB_HOST:-127.0.0.1}" DB_NAME="${DB_NAME:-climat_test}" DB_USER="${DB_USER:-test}" DB_PASS="${DB_PASS:-test}" DB_SSL=0
export DHIS2_BASE_URL=http://127.0.0.1:8001 DHIS2_TOKEN=test-token DHIS2_DATASET_UID=DataSet0001 DHIS2_NIVEAU_COMMUNE=4
export NASA_POWER_URL=http://127.0.0.1:8001/nasa
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8001 tests/mock.php >/dev/null 2>&1 & MOCK=$!
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 -t public dev/router.php >"${APP_LOG:-/dev/null}" 2>&1 & APP=$!
trap 'kill $MOCK $APP 2>/dev/null || true' EXIT
sleep 1
php tests/run.php
if [ "${KEEP:-0}" = 1 ]; then wait; fi
