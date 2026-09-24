#!/usr/bin/env bash
set -eu

cd "$(dirname "$0")/.."

static_checks_completed=false
if command -v python3 >/dev/null 2>&1; then
    python3 -B tests/harness_regression.py
    python3 -B tests/static_regression.py
    static_checks_completed=true
else
    echo "Python runtime unavailable; source-level static checks skipped"
fi

if command -v lua >/dev/null 2>&1; then
    lua tests/regression.lua
    lua tests/api_session_regression.lua
    lua tests/manager_upload_regression.lua
elif [ "$static_checks_completed" = true ]; then
    echo "Lua runtime unavailable; source-level static checks completed (no live Lightroom execution)"
else
    echo "Lua runtime unavailable; no live Lightroom execution occurred"
fi
