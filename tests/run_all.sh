#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php tests/run.php
php tests/layout.php
php tests/toolkit.php
php tests/diagnostics.php
php tests/operator_workflows.php
python3 tests/pull_python.py
for test in admin/llm_connector/tests/*.php; do
  php "$test" > /tmp/neurosaas-suite.log 2>&1 || { cat /tmp/neurosaas-suite.log; exit 1; }
  printf '%s: ' "$(basename "$test")"
  tail -n 1 /tmp/neurosaas-suite.log
done
python3 tests/http_test.py
python3 tests/toolkit_http.py
python3 tests/account_http.py
python3 tests/local_diagnostics_http.py


