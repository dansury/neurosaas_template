#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php tests/run.php
php tests/toolkit.php
for test in modules/site_yacloud_openrouter/tests/*.php; do
  php "$test" > /tmp/neurosaas-suite.log 2>&1 || { cat /tmp/neurosaas-suite.log; exit 1; }
  printf '%s: ' "$(basename "$test")"
  tail -n 1 /tmp/neurosaas-suite.log
done
python3 tests/http_test.py
python3 tests/toolkit_http.py

