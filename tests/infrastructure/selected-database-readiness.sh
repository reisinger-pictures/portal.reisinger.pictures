#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
WAITER="$ROOT_DIR/deployment/wait-for-selected-database.sh"
temporary_root="$(mktemp -d "${TMPDIR:-/tmp}/portal-db-readiness.XXXXXX")"
trap 'rm -rf -- "$temporary_root"' EXIT
mkdir -p "$temporary_root/bin"

cat > "$temporary_root/bin/php" <<'PHP'
#!/bin/sh
set -eu

printf '%s\n' "${DB_CONNECTION:-missing}" >> "$DB_PROBE_LOG"
count=0
if [ -f "$DB_PROBE_COUNT" ]; then
    count="$(cat "$DB_PROBE_COUNT")"
fi
count=$((count + 1))
printf '%s\n' "$count" > "$DB_PROBE_COUNT"

case "${DB_PROBE_SCENARIO:-ready}" in
    ready)
        [ "$DB_CONNECTION" = "${DB_PROBE_EXPECTED_CONNECTION:?}" ]
        ;;
    delayed)
        [ "$DB_CONNECTION" = "${DB_PROBE_EXPECTED_CONNECTION:?}" ] && [ "$count" -ge 3 ]
        ;;
    unavailable)
        exit 1
        ;;
    *)
        exit 2
        ;;
esac
PHP
cat > "$temporary_root/bin/sleep" <<'SLEEP'
#!/bin/sh
exit 0
SLEEP
chmod 0755 "$temporary_root/bin/php" "$temporary_root/bin/sleep"

export PATH="$temporary_root/bin:$PATH"
export DB_PROBE_LOG="$temporary_root/probed-connections.log"
export DB_PROBE_COUNT="$temporary_root/probe-count"

run_case() {
    local connection="$1"
    local scenario="$2"
    local expected_status="$3"
    local output="$temporary_root/output-$connection-$scenario.txt"
    rm -f -- "$DB_PROBE_LOG" "$DB_PROBE_COUNT"

    set +e
    DB_CONNECTION="$connection" \
        DB_PROBE_EXPECTED_CONNECTION="$connection" \
        DB_PROBE_SCENARIO="$scenario" \
        "$WAITER" >"$output" 2>&1
    local status=$?
    set -e

    [ "$status" -eq "$expected_status" ] || {
        cat "$output" >&2
        printf 'FAIL: %s/%s returned %s, expected %s.\n' "$connection" "$scenario" "$status" "$expected_status" >&2
        exit 1
    }

    if [ "$expected_status" -eq 0 ]; then
        grep -Fq "Selected database ($connection) accepts Laravel queries." "$output" \
            || { cat "$output" >&2; echo 'FAIL: readiness success was not reported.' >&2; exit 1; }
    else
        grep -Fq "FATAL: selected database '$connection' did not accept a Laravel query" "$output" \
            || { cat "$output" >&2; echo 'FAIL: unavailable selected database was not reported fail-closed.' >&2; exit 1; }
    fi

    if [ -f "$DB_PROBE_LOG" ]; then
        if grep -Fv "$connection" "$DB_PROBE_LOG" >/dev/null; then
            echo 'FAIL: readiness probed a connection other than DB_CONNECTION.' >&2
            exit 1
        fi
    fi
}

run_case mariadb ready 0
run_case pgsql delayed 0
run_case pgsql unavailable 1

set +e
DB_CONNECTION=mysql "$WAITER" >"$temporary_root/invalid-connection.txt" 2>&1
invalid_status=$?
set -e
[ "$invalid_status" -ne 0 ] || { echo 'FAIL: unsupported DB_CONNECTION was accepted.' >&2; exit 1; }
grep -Fq 'DB_CONNECTION must select mariadb or pgsql' "$temporary_root/invalid-connection.txt" \
    || { cat "$temporary_root/invalid-connection.txt" >&2; echo 'FAIL: invalid DB_CONNECTION was not named.' >&2; exit 1; }

echo 'PASS: selected database readiness retries and fails closed without probing the unselected engine'
