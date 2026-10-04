#!/bin/sh
# Runs the test suite in a PHP 8.3 container (same PHP version as production).
# Usage: tests/run.sh
set -e
cd "$(dirname "$0")/.."
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c '
    set -e
    for f in $(find gateways addons tests -name "*.php"); do php -l "$f" > /dev/null; done
    echo "Lint OK"
    php tests/amounts_test.php
    php -S 127.0.0.1:8099 tests/mp_mock_server.php > /dev/null 2>&1 &
    sleep 1
    MP_MOCK_URL=http://127.0.0.1:8099 php tests/link_test.php
'
