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
original_df="$(command -v df)"
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
    if [[ " $* " == *'--format'* ]]; then
        printf '%s\n' "$FAKE_DOCKER_ROOT_DIR"
    fi
    exit 0
fi
if [[ "${1-}" == 'image' && "${2-}" == 'inspect' ]]; then
    exit 0
fi
if [[ "${1-}" == 'inspect' ]]; then
    if [[ "$joined" == *'portal_db_pg'* ]]; then
        [[ "${FAKE_PG_CONTAINER_EXISTS:-true}" == 'true' ]] || exit 1
        printf '%s|%s\n' "$FAKE_PG_MOUNTED_VOLUME_NAME" "$FAKE_PG_DATA_DIR"
    elif [[ "$joined" == *'portal_backend'* ]]; then
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
                volume_json='{"name":"cutover-test_db_pg_data","driver":"local"}'
                case "${FAKE_COMPOSE_VOLUME_MODE:-local}" in
                    external) volume_json='{"name":"cutover-test_db_pg_data","driver":"local","external":true}' ;;
                    driver-options) volume_json='{"name":"cutover-test_db_pg_data","driver":"local","driver_opts":{"type":"none","o":"bind","device":"/tmp"}}' ;;
                esac
                cat <<JSON
{"services":{"db":{"container_name":"portal_db","image":"mariadb:11.4","volumes":[{"source":"db_data"}]},"db_pg":{"container_name":"portal_db_pg","image":"postgres:16","volumes":[{"source":"db_pg_data"}]},"backend":{"image":"portal/backend:cutover-test"}},"volumes":{"db_data":{"driver":"local"},"db_pg_data":$volume_json}}
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
        stop)
            ;;
        up)
            if [[ "${FAKE_UP_FAIL_ONCE:-}" == 'true' \
                && " $* " == *' --force-recreate '* \
                && ! -e "$FAKE_DOCKER_STATE/up-failed-once" ]]; then
                if [[ -e "$PORTAL_STATE_DIR/switch-attempted" ]]; then
                    touch "$FAKE_DOCKER_STATE/switch-marker-seen"
                fi
                touch "$FAKE_DOCKER_STATE/up-failed-once"
                printf 'simulated backend recreate failure\n' >&2
                exit 1
            fi
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

cat > "$temporary_root/df" <<'DF'
#!/usr/bin/env bash
set -euo pipefail
target_path="${*: -1}"
printf '%s\n' "$target_path" >> "$FAKE_DF_LOG"
if [[ "$target_path" == "$FAKE_PG_DATA_DIR" \
    || "$target_path" == "$FAKE_DOCKER_ROOT_DIR" \
    || "$target_path" == "$FAKE_DOCKER_ROOT_DIR/volumes" ]]; then
    printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n'
    printf 'fake-target 1048576 0 %s 0%% %s\n' "$FAKE_TARGET_FREE_KIB" "$target_path"
else
    exec "$REAL_DF" "$@"
fi
DF
chmod 0755 "$temporary_root/df"

prepare_case() {
    local name="$1"
    local create_docker_volumes_dir="${2:-true}"
    local case_root="$temporary_root/$name"
    mkdir -p "$case_root/bin" "$case_root/stack" "$case_root/db-data" "$case_root/pg-data" "$case_root/docker-root"
    if [[ "$create_docker_volumes_dir" == 'true' ]]; then
        mkdir -p "$case_root/docker-root/volumes"
    fi
    cp -- "$temporary_root/docker" "$case_root/bin/docker"
    chmod 0755 "$case_root/bin/docker"
    cp -- "$temporary_root/df" "$case_root/bin/df"
    chmod 0755 "$case_root/bin/df"
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
    export FAKE_DF_LOG="$case_root/df.log"
    export FAKE_DB_DATA_DIR="$case_root/db-data"
    export FAKE_PG_DATA_DIR="$case_root/pg-data"
    export FAKE_PG_MOUNTED_VOLUME_NAME='cutover-test_db_pg_data'
    export FAKE_PG_CONTAINER_EXISTS=true
    export FAKE_DOCKER_VOLUMES_DIR="$create_docker_volumes_dir"
    export FAKE_COMPOSE_VOLUME_MODE=local
    export FAKE_DOCKER_ROOT_DIR="$case_root/docker-root"
    export FAKE_TARGET_FREE_KIB=1048576
    export REAL_DF="$original_df"
    export FAKE_HEALTH_MODE=healthy
    export FAKE_DUMP_MODE=ok
    export FAKE_UP_FAIL_ONCE=''
    export FAKE_LOCK_PROBE=''
    mkdir -p "$FAKE_DOCKER_STATE"
}

assert_lock_released() {
    flock -n "$PORTAL_STATE_DIR/cutover.lock" true \
        || fail 'the host cutover lock was still held after the script exited'
}

assert_preflight_refuses_before_downtime() {
    local log_file="$1"
    local expected_message="$2"
    local status
    set +e
    "$CUTOVER_SCRIPT" > "$log_file" 2>&1
    status=$?
    set -e
    [[ "$status" -ne 0 ]] || fail 'an invalid cutover preflight unexpectedly succeeded'
    grep -Fq "$expected_message" "$log_file" \
        || { cat "$log_file" >&2; fail "preflight refusal did not name: $expected_message"; }
    [[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'a refused preflight wrote an in-progress marker'
    [[ ! -e "$PORTAL_BACKUP_DIR" ]] || fail 'a refused preflight created the backup directory'
    if grep -Fq 'stop backend' "$FAKE_DOCKER_LOG"; then
        fail 'a refused preflight stopped the live backend before refusing'
    fi
    assert_lock_released
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

assert_artisan_config_override() {
    local log="$1"
    local line found=0
    while IFS= read -r line; do
        [[ "$line" == *'php artisan'* ]] || continue
        found=$((found + 1))
        [[ "$line" == *'APP_CONFIG_CACHE=/tmp/portal-cutover-config-'* ]] \
            || fail "an Artisan invocation lacks the absent APP_CONFIG_CACHE override: $line"
        [[ "$line" != *'bootstrap/cache/config.php'* ]] \
            || fail "an Artisan invocation targets the live shared config cache: $line"
    done < "$log"
    [[ "$found" -gt 0 ]] || fail 'no Artisan invocation was recorded'
}

assert_database_command_contract() {
    local log="$1"
    local line found=0
    while IFS= read -r line; do
        [[ "$line" == *'DB_CONNECTION='* ]] || continue
        found=$((found + 1))
        [[ "$line" == *'DB_URL='* ]] || fail "a database command lacks the explicit DB_URL= override: $line"
        [[ "$line" == *'APP_CONFIG_CACHE=/tmp/portal-cutover-config-'* ]] \
            || fail "a database command lacks the absent APP_CONFIG_CACHE override: $line"
    done < "$log"
    [[ "$found" -gt 0 ]] || fail 'no database command was recorded'
}

assert_pg_migration_command() {
    local log="$1"
    local line found=0
    while IFS= read -r line; do
        [[ "$line" == *'php artisan migrate --force'* ]] || continue
        found=$((found + 1))
        local expected
        for expected in \
            'DB_CONNECTION=pgsql' 'DB_HOST=db_pg' 'DB_PORT=5432' 'DB_URL=' \
            'APP_CONFIG_CACHE=/tmp/portal-cutover-config-'; do
            [[ "$line" == *"$expected"* ]] \
                || fail "the target migration lacks '$expected': $line"
        done
    done < "$log"
    [[ "$found" -eq 1 ]] || fail "expected exactly one target migration command, found $found"
}

assert_mariadb_status_command() {
    local log="$1"
    local line found=0
    while IFS= read -r line; do
        [[ "$line" == *'migrate:status --database=mariadb'* ]] || continue
        found=$((found + 1))
        [[ "$line" == *'DB_CONNECTION=mariadb'* ]] \
            || fail "the source migration status does not target MariaDB: $line"
    done < "$log"
    [[ "$found" -eq 1 ]] || fail "expected exactly one source migration status command, found $found"
}

assert_pg_commands_target_db_pg() {
    local log="$1"
    local line found=0
    while IFS= read -r line; do
        [[ "$line" == *'DB_CONNECTION=pgsql'* ]] || continue
        found=$((found + 1))
        [[ "$line" == *'DB_HOST=db_pg'* ]] || fail "a PostgreSQL command does not target db_pg: $line"
        [[ "$line" == *'DB_PORT=5432'* ]] || fail "a PostgreSQL command does not target port 5432: $line"
    done < "$log"
    [[ "$found" -ge 1 ]] || fail 'no PostgreSQL command was recorded'
}

assert_switch_marker() {
    local marker="$PORTAL_STATE_DIR/switch-attempted"
    [[ -f "$marker" ]] || fail 'the successful cutover did not retain the switch marker'
    [[ "$(stat -c '%a' "$marker")" == '600' ]] || fail 'the switch marker permissions are not 0600'
    grep -Fxq 'switch=attempted' "$marker" || fail 'the switch marker does not record the switch attempt'
    local backup
    backup="$(sed -n 's/^env_backup=//p' "$marker" | head -n 1)"
    [[ -n "$backup" && -f "$backup" ]] || fail 'the switch marker does not name an existing MariaDB .env backup'
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

prepare_case db-url-preflight
cat >> "$PORTAL_STACK_DIR/.env" <<'ENV'
DB_URL=mysql://mariadb.invalid/portal
ENV
set +e
"$CUTOVER_SCRIPT" > "$temporary_root/db-url-preflight.log" 2>&1
db_url_status=$?
set -e
[[ "$db_url_status" -ne 0 ]] || fail 'a non-empty DB_URL was accepted for cutover'
grep -Fq 'A non-empty DB_URL is set in the host .env and would override the DB_* coordinates' "$temporary_root/db-url-preflight.log" \
    || { cat "$temporary_root/db-url-preflight.log" >&2; fail 'the DB_URL preflight did not name the overriding connection setting'; }
grep -Fxq 'DB_URL=mysql://mariadb.invalid/portal' "$PORTAL_STACK_DIR/.env" \
    || fail 'the DB_URL preflight changed the host .env'
[[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'the DB_URL preflight wrote an in-progress marker'
[[ ! -e "$PORTAL_BACKUP_DIR" ]] || fail 'the DB_URL preflight began backup work before refusing'
if grep -Fq 'stop backend' "$FAKE_DOCKER_LOG"; then
    fail 'the DB_URL preflight stopped the live backend before refusing'
fi
assert_lock_released

prepare_case postgres-low-space
export FAKE_TARGET_FREE_KIB=0
export FAKE_PG_CONTAINER_EXISTS=false
assert_preflight_refuses_before_downtime \
    "$temporary_root/postgres-low-space.log" \
    'The PostgreSQL target filesystem has less than 3x the current MariaDB data size free.'
grep -Fxq "$FAKE_DOCKER_ROOT_DIR/volumes" "$FAKE_DF_LOG" \
    || fail 'a not-yet-created PostgreSQL volume did not check the Docker volumes filesystem'

prepare_case postgres-low-space-docker-root-only false
export FAKE_TARGET_FREE_KIB=0
export FAKE_PG_CONTAINER_EXISTS=false
assert_preflight_refuses_before_downtime \
    "$temporary_root/postgres-low-space-docker-root-only.log" \
    'The PostgreSQL target filesystem has less than 3x the current MariaDB data size free.'
grep -Fxq "$FAKE_DOCKER_ROOT_DIR" "$FAKE_DF_LOG" \
    || fail 'an absent Docker volumes directory did not fall back to DockerRootDir'
if grep -Fxq "$FAKE_DOCKER_ROOT_DIR/volumes" "$FAKE_DF_LOG"; then
    fail 'the missing Docker volumes directory was still used for disk preflight'
fi

for invalid_volume_mode in external driver-options; do
    prepare_case "postgres-invalid-$invalid_volume_mode-volume"
    export FAKE_COMPOSE_VOLUME_MODE="$invalid_volume_mode"
    assert_preflight_refuses_before_downtime \
        "$temporary_root/postgres-invalid-$invalid_volume_mode-volume.log" \
        'Compose database services do not match the MariaDB/PostgreSQL cutover topology.'
done

prepare_case postgres-wrong-volume-mount
export FAKE_PG_MOUNTED_VOLUME_NAME='unrelated_pg_data'
assert_preflight_refuses_before_downtime \
    "$temporary_root/postgres-wrong-volume-mount.log" \
    'The existing PostgreSQL container does not mount the Compose target volume.'

prepare_case explicit-rollback
"$CUTOVER_SCRIPT" > "$temporary_root/success.log" 2>&1 || {
    cat "$temporary_root/success.log" >&2
    fail 'the simulated host cutover did not complete'
}
grep -Fxq "$FAKE_PG_DATA_DIR" "$FAKE_DF_LOG" \
    || fail 'the existing PostgreSQL container did not measure its mounted volume source'
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
assert_switch_marker
assert_artisan_config_override "$FAKE_DOCKER_LOG"
assert_database_command_contract "$FAKE_DOCKER_LOG"
assert_pg_migration_command "$FAKE_DOCKER_LOG"
assert_mariadb_status_command "$FAKE_DOCKER_LOG"
assert_pg_commands_target_db_pg "$FAKE_DOCKER_LOG"
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
[[ ! -e "$PORTAL_STATE_DIR/switch-attempted" ]] || fail 'explicit rollback left the switch marker behind'
[[ ! -e "$PORTAL_STATE_DIR/last-env-backup" ]] || fail 'explicit rollback left the legacy saved-env pointer behind'
assert_lock_released

# A legacy pointer from an older script version must never make a later,
# markerless rollback restore an obsolete whole-file .env backup.
legacy_backup="$PORTAL_STACK_DIR/.env.legacy.bak"
cp -- "$env_file" "$legacy_backup"
printf '%s\n' "$legacy_backup" > "$PORTAL_STATE_DIR/last-env-backup"
sed -i '/^QUEUE_CONNECTION=/a POST_ROLLBACK_SENTINEL=preserve-this-value' "$env_file"
env_checksum="$(sha256sum "$env_file" | awk '{print $1}')"
log_lines_before="$(wc -l < "$FAKE_DOCKER_LOG")"
set +e
"$CUTOVER_SCRIPT" --rollback > "$temporary_root/repeated-rollback.log" 2>&1
repeated_rollback_status=$?
set -e
[[ "$repeated_rollback_status" -ne 0 ]] || fail 'a markerless repeated rollback unexpectedly succeeded'
grep -Fq 'refusing to restore a stale .env backup' "$temporary_root/repeated-rollback.log" \
    || { cat "$temporary_root/repeated-rollback.log" >&2; fail 'a markerless repeated rollback did not refuse the stale backup'; }
[[ "$(sha256sum "$env_file" | awk '{print $1}')" == "$env_checksum" ]] \
    || fail 'a markerless repeated rollback reverted unrelated .env changes'
grep -Fxq 'POST_ROLLBACK_SENTINEL=preserve-this-value' "$env_file" \
    || fail 'a markerless repeated rollback removed a later .env setting'
if tail -n +"$((log_lines_before + 1))" "$FAKE_DOCKER_LOG" | grep -Fq 'up -d --force-recreate'; then
    fail 'a markerless repeated rollback started the backend service'
fi
assert_lock_released

prepare_case edited-env-rollback
"$CUTOVER_SCRIPT" > "$temporary_root/edited-env-cutover.log" 2>&1 || {
    cat "$temporary_root/edited-env-cutover.log" >&2
    fail 'the simulated cutover before the edited-env rollback did not complete'
}
env_file="$PORTAL_STACK_DIR/.env"
sed -i 's/^DB_CONNECTION=pgsql$/DB_CONNECTION=mariadb/' "$env_file"
grep -Fxq 'DB_CONNECTION=mariadb' "$env_file" || fail 'the regression could not edit the host .env back to MariaDB'
env_checksum="$(sha256sum "$env_file" | awk '{print $1}')"
marker_checksum="$(sha256sum "$PORTAL_STATE_DIR/switch-attempted" | awk '{print $1}')"
log_lines_before="$(wc -l < "$FAKE_DOCKER_LOG")"
set +e
"$CUTOVER_SCRIPT" --rollback > "$temporary_root/edited-env-unacknowledged-rollback.log" 2>&1
edited_env_status=$?
set -e
[[ "$edited_env_status" -ne 0 ]] || fail 'a rollback after an edited-back MariaDB .env was accepted without acknowledgement'
grep -Fq 'A PostgreSQL switch was already attempted' "$temporary_root/edited-env-unacknowledged-rollback.log" \
    || { cat "$temporary_root/edited-env-unacknowledged-rollback.log" >&2; fail 'the refused rollback did not name the recorded switch attempt'; }
grep -Fq -- '--accept-postgres-write-loss' "$temporary_root/edited-env-unacknowledged-rollback.log" \
    || { cat "$temporary_root/edited-env-unacknowledged-rollback.log" >&2; fail 'the refused edited-env rollback did not name the acknowledgement flag'; }
[[ "$(sha256sum "$env_file" | awk '{print $1}')" == "$env_checksum" ]] \
    || fail 'a refused edited-env rollback modified the host .env'
[[ "$(sha256sum "$PORTAL_STATE_DIR/switch-attempted" | awk '{print $1}')" == "$marker_checksum" ]] \
    || fail 'a refused edited-env rollback modified the switch marker'
if tail -n +"$((log_lines_before + 1))" "$FAKE_DOCKER_LOG" | grep -Fq 'up -d --force-recreate'; then
    fail 'a refused edited-env rollback started the backend service'
fi
assert_lock_released

"$CUTOVER_SCRIPT" --rollback --accept-postgres-write-loss > "$temporary_root/edited-env-acknowledged-rollback.log" 2>&1 || {
    cat "$temporary_root/edited-env-acknowledged-rollback.log" >&2
    fail 'the acknowledged rollback after an edited-back MariaDB .env did not complete'
}
grep -Fq 'acknowledged for a previously attempted PostgreSQL switch' "$temporary_root/edited-env-acknowledged-rollback.log" \
    || fail 'the acknowledged edited-env rollback did not name the recorded switch attempt'
assert_mariadb_env
[[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'the edited-env rollback left the in-progress marker behind'
[[ ! -e "$PORTAL_STATE_DIR/switch-attempted" ]] || fail 'the edited-env rollback left the switch marker behind'
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
[[ ! -e "$PORTAL_STATE_DIR/switch-attempted" ]] || fail 'automatic rollback left the switch marker behind'
[[ "$(cat "$FAKE_DOCKER_STATE/backend-health-count")" == '2' ]] \
    || fail 'automatic rollback did not test PostgreSQL failure and MariaDB recovery health separately'
assert_lock_released

prepare_case switch-process-failure
export FAKE_UP_FAIL_ONCE=true
set +e
"$CUTOVER_SCRIPT" > "$temporary_root/switch-process-failure.log" 2>&1
switch_failure_status=$?
set -e
[[ "$switch_failure_status" -ne 0 ]] || fail 'a simulated backend recreate failure unexpectedly reported a successful cutover'
grep -Fq 'automatically rolled back to MariaDB' "$temporary_root/switch-process-failure.log" \
    || { cat "$temporary_root/switch-process-failure.log" >&2; fail 'the switch process failure did not trigger an automatic rollback'; }
[[ -e "$FAKE_DOCKER_STATE/switch-marker-seen" ]] \
    || fail 'the switch marker was not present when the backend recreate failed'
[[ ! -e "$PORTAL_STATE_DIR/switch-attempted" ]] \
    || fail 'the automatic rollback after a switch process failure left the switch marker behind'
assert_mariadb_env
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
grep -Fq 'No .env backup was recorded; MariaDB connection keys will be normalized to the rollback target.' "$temporary_root/pre-switch-rollback.log" \
    || fail 'the pre-switch rollback did not disclose that it normalizes MariaDB connection keys'
assert_mariadb_env
[[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'pre-switch rollback left the in-progress marker behind'
assert_lock_released

echo 'PASS: host cutover backup, config-cache override, disk/volume preflight, host lock, acknowledged rollback, stale-pointer rejection, edited-env rollback, automatic and switch-process-failure rollback, and pre-switch rollback lifecycle'
