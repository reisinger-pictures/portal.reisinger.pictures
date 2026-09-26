#!/usr/bin/env bash
set -eu

cd "$(dirname "$0")/.."

# INFRA-6: count how many real suites ran. A missing interpreter must never
# turn this harness into a silent, green no-op.
checks_run=0
python_available=false
if command -v python3 >/dev/null 2>&1; then
    python3 -B tests/harness_regression.py
    python3 -B tests/static_regression.py
    checks_run=$((checks_run + 1))
    python_available=true
else
    echo "Python runtime unavailable; source-level static checks skipped"
fi

if command -v lua >/dev/null 2>&1; then
    lua tests/regression.lua
    lua tests/api_session_regression.lua
    lua tests/manager_upload_regression.lua
    lua tests/legacy_password_migration_regression.lua
    checks_run=$((checks_run + 1))
elif [ "$python_available" = true ]; then
    echo "Lua runtime unavailable; source-level static checks completed (no live Lightroom execution)"
else
    echo "Lua runtime unavailable; no live Lightroom execution occurred"
fi

if [ "$checks_run" -eq 0 ]; then
    echo "FAIL: no test runtime available (neither python3 nor lua found); zero checks executed." >&2
    exit 1
fi

echo "PASS: admin.lrplugin test suites executed (checks_run=$checks_run)"
