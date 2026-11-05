#!/usr/bin/env bash
set -euo pipefail

if [[ "$EUID" -ne 0 ]]; then
    command -v sudo >/dev/null 2>&1 || {
        echo 'FAIL: sudo is required to exercise the root-only host cutover script.' >&2
        exit 1
    }
    exec sudo --preserve-env=PATH -- bash "$BASH_SOURCE" "$@"
fi

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
CUTOVER_SCRIPT="$ROOT_DIR/deployment/migrate-to-postgres.sh"
temporary_root="$(mktemp -d "${TMPDIR:-/tmp}/portal-cutover-lifecycle.XXXXXX")"
original_path="$PATH"
trap 'rm -rf -- "$temporary_root"' EXIT

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

cat > "$temporary_root/docker" <<'DOCKER'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$FAKE_DOCKER_LOG"
printf '\n' >> "$FAKE_DOCKER_LOG"

if [[ -n "${FAKE_LOCK_PROBE:-}" ]]; then
    if flock -n "$PORTAL_STATE_DIR/cutover.lock" true 2>/dev/null; then
        printf 'released\n' >> "$FAKE_LOCK_PROBE"
    else
        printf 'held\n' >> "$FAKE_LOCK_PROBE"
    fi
fi

joined=" $* "
if [[ "${1-}" == 'info' ]]; then
    exit 0
fi
if [[ "${1-}" == 'image' && "${2-}" == 'inspect' ]]; then
    exit 0
fi
if [[ "${1-}" == 'inspect' ]]; then
    if [[ "$joined" == *'portal_backend'* ]]; then
        count=0
        if [[ -f "$FAKE_DOCKER_STATE/backend-health-count" ]]; then
            count="$(cat "$FAKE_DOCKER_STATE/backend-health-count")"
        fi
        count=$((count + 1))
        printf '%s\n' "$count" > "$FAKE_DOCKER_STATE/backend-health-count"
        if [[ "${FAKE_HEALTH_MODE:-healthy}" == 'unhealthy-first' && "$count" -eq 1 ]]; then
            printf 'unhealthy\n'
        else
            printf 'healthy\n'
        fi
    elif [[ "$joined" == *'portal_db'* ]]; then
        if [[ "$joined" == *'/var/lib/mysql'* ]]; then
            printf '%s\n' "$FAKE_DB_DATA_DIR"
        else
            printf 'true\n'
        fi
    fi
    exit 0
fi
if [[ "${1-}" == 'exec' ]]; then
    if [[ "$joined" == *'mariadb-dump'* ]]; then
        if [[ "${FAKE_DUMP_MODE:-ok}" == 'fail' ]]; then
            printf 'simulated MariaDB dump failure\n' >&2
            exit 1
        fi
        head -c 2048 /dev/urandom
    elif [[ "$joined" == *'SELECT TABLE_NAME FROM information_schema.TABLES'* ]]; then
        printf 'roles\nsettings\nusers\nuser_roles\n'
    elif [[ "$joined" == *'SELECT COUNT(*) FROM'* ]]; then
        printf '1\n'
    elif [[ "$joined" == *'pg_isready'* ]]; then
        exit 0
    fi
    exit 0
fi
if [[ "${1-}" == 'compose' ]]; then
    shift
    while (($#)); do
        case "$1" in
            --project-directory|--env-file|-f) shift 2 ;;
            version|config|run|stop|up) command="$1"; shift; break ;;
            *) shift ;;
        esac
    done
    case "${command:-}" in
        version)
            printf 'Docker Compose version v5.7.0\n'
            ;;
        config)
            if [[ "${1-}" == '--services' ]]; then
                printf 'db\ndb_pg\nbackend\n'
            else
                cat <<'JSON'
{"services":{"db":{"container_name":"portal_db","image":"mariadb:11.4","volumes":[{"source":"db_data"}]},"db_pg":{"container_name":"portal_db_pg","image":"postgres:16","volumes":[{"source":"db_pg_data"}]},"backend":{"image":"portal/backend:cutover-test"}}}
JSON
            fi
            ;;
        run)
            if [[ " $* " == *'php artisan list --raw'* ]]; then
                printf 'portal:db-copy\n'
            elif [[ " $* " == *'migrate:status --database=mariadb'* ]]; then
                printf 'All MariaDB migrations are complete.\n'
            fi
            ;;
        stop|up)
            ;;
        *)
            printf 'unexpected docker compose command: %s %s\n' "$command" "$*" >&2
            exit 2
            ;;
    esac
    exit 0
fi

printf 'unexpected docker command: %s\n' "$joined" >&2
exit 2
DOCKER
chmod 0755 "$temporary_root/docker"

prepare_case() {
    local name="$1"
    local case_root="$temporary_root/$name"
    mkdir -p "$case_root/bin" "$case_root/stack" "$case_root/db-data"
    cp -- "$temporary_root/docker" "$case_root/bin/docker"
    chmod 0755 "$case_root/bin/docker"
    touch "$case_root/stack/compose.yaml"
    cat > "$case_root/stack/.env" <<'ENV'
DB_CONNECTION=mariadb
DB_PASSWORD=not-a-real-secret
QUEUE_CONNECTION=database
ENV
    export PATH="$case_root/bin:$original_path"
    export PORTAL_STACK_DIR="$case_root/stack"
    export PORTAL_BACKUP_DIR="$case_root/backups"
    export PORTAL_STATE_DIR="$case_root/state"
    export FAKE_DOCKER_LOG="$case_root/docker.log"
    export FAKE_DOCKER_STATE="$case_root/docker-state"
    export FAKE_DB_DATA_DIR="$case_root/db-data"
    export FAKE_HEALTH_MODE=healthy
    export FAKE_DUMP_MODE=ok
    export FAKE_LOCK_PROBE=''
    mkdir -p "$FAKE_DOCKER_STATE"
}

assert_lock_released() {
    flock -n "$PORTAL_STATE_DIR/cutover.lock" true \
        || fail 'the host cutover lock was still held after the script exited'
}

assert_mariadb_env() {
    local env_file="$PORTAL_STACK_DIR/.env"
    grep -Fxq 'DB_CONNECTION=mariadb' "$env_file" || fail 'rollback did not restore DB_CONNECTION=mariadb'
    grep -Fxq 'DB_HOST=db' "$env_file" || fail 'rollback did not select the MariaDB host'
    grep -Fxq 'DB_PORT=3306' "$env_file" || fail 'rollback did not select the MariaDB port'
    grep -Fxq 'DB_QUEUE_CONNECTION=mariadb' "$env_file" || fail 'rollback did not select the MariaDB queue connection'
    grep -Fxq 'DB_CACHE_CONNECTION=mariadb' "$env_file" || fail 'rollback did not select the MariaDB cache connection'
    grep -Fxq 'DB_CACHE_LOCK_CONNECTION=mariadb' "$env_file" || fail 'rollback did not select the MariaDB cache-lock connection'
}

export PORTAL_BACKUP_DIR="$temporary_root/production-override-must-not-be-used/backups"
export PORTAL_STATE_DIR="$temporary_root/production-override-must-not-be-used/state"
unset PORTAL_STACK_DIR
set +e
"$CUTOVER_SCRIPT" --rollback > "$temporary_root/reject-production-overrides.log" 2>&1
override_status=$?
set -e
[[ "$override_status" -ne 0 ]] || fail 'test-only backup/state overrides were accepted with the production stack path'
grep -Fq 'are test-only and require PORTAL_STACK_DIR' "$temporary_root/reject-production-overrides.log" \
    || { cat "$temporary_root/reject-production-overrides.log" >&2; fail 'production override guard did not name the required controlled stack'; }

prepare_case lock-contention
install -d -m 0700 "$PORTAL_STATE_DIR"
held_lock_fd=''
exec {held_lock_fd}>"$PORTAL_STATE_DIR/cutover.lock"
flock -n "$held_lock_fd" || fail 'the lifecycle harness could not hold the host cutover lock'
for contention_mode in cutover rollback; do
    set +e
    if [[ "$contention_mode" == 'cutover' ]]; then
        "$CUTOVER_SCRIPT" > "$temporary_root/lock-contention-$contention_mode.log" 2>&1
    else
        "$CUTOVER_SCRIPT" --rollback > "$temporary_root/lock-contention-$contention_mode.log" 2>&1
    fi
    contention_status=$?
    set -e
    [[ "$contention_status" -ne 0 ]] || fail "$contention_mode was accepted while another run held the host lock"
    grep -Fq 'already holds the host lock' "$temporary_root/lock-contention-$contention_mode.log" \
        || { cat "$temporary_root/lock-contention-$contention_mode.log" >&2; fail "$contention_mode did not name the held host lock"; }
done
flock -u "$held_lock_fd"
exec {held_lock_fd}>&-
assert_lock_released

prepare_case explicit-rollback
"$CUTOVER_SCRIPT" > "$temporary_root/success.log" 2>&1 || {
    cat "$temporary_root/success.log" >&2
    fail 'the simulated host cutover did not complete'
}
env_file="$PORTAL_STACK_DIR/.env"
grep -Fxq 'DB_CONNECTION=pgsql' "$env_file" || fail 'successful cutover did not select PostgreSQL'
grep -Fxq 'DB_HOST=db_pg' "$env_file" || fail 'successful cutover did not select the PostgreSQL host'
grep -Fxq 'DB_PORT=5432' "$env_file" || fail 'successful cutover did not select the PostgreSQL port'
grep -Fxq 'DB_QUEUE_CONNECTION=pgsql' "$env_file" || fail 'successful cutover did not switch queue storage'
grep -Fxq 'DB_CACHE_CONNECTION=pgsql' "$env_file" || fail 'successful cutover did not switch cache storage'
grep -Fxq 'DB_CACHE_LOCK_CONNECTION=pgsql' "$env_file" || fail 'successful cutover did not switch cache lock storage'
[[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'successful cutover left the in-progress marker behind'
backup_dump=("$PORTAL_BACKUP_DIR"/mariadb-*.sql.gz)
[[ -f "${backup_dump[0]}" ]] || fail 'successful cutover did not retain its backup dump'
[[ "$(stat -c '%a' "${backup_dump[0]}")" == '600' ]] || fail 'backup dump permissions are not 0600'
gzip -t "${backup_dump[0]}" || fail 'backup dump failed gzip integrity verification'
sha256sum -c "${backup_dump[0]}.sha256" >/dev/null || fail 'backup checksum verification failed'
grep -Fq $'table\trows' "$PORTAL_BACKUP_DIR"/mariadb-*.manifest.tsv || fail 'backup row manifest header is missing'
grep -Fq 'portal_db_pg' "$FAKE_DOCKER_LOG" || fail 'the target PostgreSQL container was never checked'
grep -Fq -- '--accept-postgres-write-loss' "$temporary_root/success.log" \
    || fail 'successful cutover did not name the acknowledged rollback command'
assert_lock_released

set +e
"$CUTOVER_SCRIPT" --rollback > "$temporary_root/unacknowledged-rollback.log" 2>&1
unacknowledged_status=$?
set -e
[[ "$unacknowledged_status" -ne 0 ]] || fail 'a post-switch manual rollback was accepted without acknowledgement'
grep -Fq 'MariaDB is the pre-cutover snapshot and is NOT updated from PostgreSQL' "$temporary_root/unacknowledged-rollback.log" \
    || { cat "$temporary_root/unacknowledged-rollback.log" >&2; fail 'the refused rollback did not name the irreversible PostgreSQL write loss'; }
grep -Fq -- '--accept-postgres-write-loss' "$temporary_root/unacknowledged-rollback.log" \
    || { cat "$temporary_root/unacknowledged-rollback.log" >&2; fail 'the refused rollback did not name the acknowledgement flag'; }
grep -Fxq 'DB_CONNECTION=pgsql' "$env_file" || fail 'a refused post-switch rollback still changed the host .env'
assert_lock_released

"$CUTOVER_SCRIPT" --rollback --accept-postgres-write-loss > "$temporary_root/explicit-rollback.log" 2>&1 || {
    cat "$temporary_root/explicit-rollback.log" >&2
    fail 'the explicit rollback lifecycle did not complete'
}
grep -Fq 'PostgreSQL-only writes will not appear in the active MariaDB database' "$temporary_root/explicit-rollback.log" \
    || fail 'acknowledged rollback did not log the PostgreSQL write-divergence boundary'
assert_mariadb_env
[[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'explicit rollback left the in-progress marker behind'
assert_lock_released

prepare_case automatic-rollback
export FAKE_HEALTH_MODE=unhealthy-first
export FAKE_LOCK_PROBE="$temporary_root/automatic-lock-probe"
set +e
"$CUTOVER_SCRIPT" > "$temporary_root/automatic-rollback.log" 2>&1
automatic_status=$?
set -e
[[ "$automatic_status" -ne 0 ]] || fail 'failed PostgreSQL health unexpectedly reported a successful cutover'
grep -Fq 'Automatic rollback boundary: the PostgreSQL switch failed its health gate.' "$temporary_root/automatic-rollback.log" \
    || { cat "$temporary_root/automatic-rollback.log" >&2; fail 'automatic rollback did not log the PostgreSQL write-loss boundary'; }
grep -Fq 'automatically rolled back to MariaDB' "$temporary_root/automatic-rollback.log" \
    || { cat "$temporary_root/automatic-rollback.log" >&2; fail 'automatic rollback did not name the PostgreSQL switch failure'; }
grep -Fxq 'held' "$FAKE_LOCK_PROBE" || fail 'the host lock was not held while the automatic rollback ran'
if grep -Fxq 'released' "$FAKE_LOCK_PROBE"; then
    fail 'the host lock was released during the automatic rollback'
fi
assert_mariadb_env
[[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'automatic rollback left the in-progress marker behind'
[[ "$(cat "$FAKE_DOCKER_STATE/backend-health-count")" == '2' ]] \
    || fail 'automatic rollback did not test PostgreSQL failure and MariaDB recovery health separately'
assert_lock_released

prepare_case pre-switch-rollback
export FAKE_DUMP_MODE=fail
set +e
"$CUTOVER_SCRIPT" > "$temporary_root/pre-switch-failure.log" 2>&1
pre_switch_status=$?
set -e
[[ "$pre_switch_status" -ne 0 ]] || fail 'a simulated pre-switch dump failure unexpectedly reported success'
grep -Fq 'MariaDB dump failed.' "$temporary_root/pre-switch-failure.log" \
    || { cat "$temporary_root/pre-switch-failure.log" >&2; fail 'the pre-switch failure was not the simulated dump failure'; }
grep -Fxq 'DB_CONNECTION=mariadb' "$PORTAL_STACK_DIR/.env" || fail 'the pre-switch failure changed the host .env'
[[ -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'the pre-switch failure did not preserve its in-progress marker'
assert_lock_released

"$CUTOVER_SCRIPT" --rollback > "$temporary_root/pre-switch-rollback.log" 2>&1 || {
    cat "$temporary_root/pre-switch-rollback.log" >&2
    fail 'a manual rollback before the switch required an acknowledgement'
}
grep -Fq 'the host .env still selects MariaDB' "$temporary_root/pre-switch-rollback.log" \
    || fail 'the pre-switch rollback did not name the MariaDB selection boundary'
assert_mariadb_env
[[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'pre-switch rollback left the in-progress marker behind'
assert_lock_released

echo 'PASS: host cutover backup, host lock, acknowledged rollback, automatic rollback, and pre-switch rollback lifecycle'
