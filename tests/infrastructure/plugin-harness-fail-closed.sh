#!/usr/bin/env bash
# INFRA-6 regression: admin.lrplugin/tests/run.sh was fail-open. With neither
# python3 nor lua on PATH it printed a disclosure and exited 0 after executing
# zero checks. The harness now counts executed suites and must fail closed.
#
# The test provides the only external command the runner needs before its
# interpreter probes (`dirname`) and nothing else, so both python3 and lua are
# genuinely absent without touching the host PATH.
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
RUNNER="$ROOT_DIR/admin.lrplugin/tests/run.sh"
BASH_BIN="${BASH:-/bin/bash}"

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

[[ -f "$RUNNER" ]] || fail "plugin harness runner is missing: $RUNNER"
[[ -x "$BASH_BIN" ]] || fail "bash is not executable: $BASH_BIN"

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/plugin-harness-fail-closed.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT
mkdir -p "$WORK_DIR/bin"

ln -s "$(command -v dirname)" "$WORK_DIR/bin/dirname"

set +e
output="$(env -i PATH="$WORK_DIR/bin" "$BASH_BIN" "$RUNNER" 2>&1)"
status=$?
set -e

[[ "$status" -ne 0 ]] \
    || fail "the plugin harness exited 0 with no test runtime available (output: $output)"
grep -Fq 'no test runtime available' <<<"$output" \
    || fail "the plugin harness did not explain the failure: $output"

printf 'PASS: plugin harness fails closed without python3/lua\n'
