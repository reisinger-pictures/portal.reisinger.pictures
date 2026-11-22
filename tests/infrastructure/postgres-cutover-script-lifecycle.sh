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
        printf 'healthy\n'
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
            if [[ "${FAKE_SWITCH_FAIL:-}" == 'recreate' ]] && [[ " $* " == *'--force-recreate'* ]]; then
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
    printf 'MariaDB source fixture\n' > "$case_root/db-data/fixture.bin"
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
    mkdir -p "$FAKE_DOCKER_STATE"
}

assert_lock_released() {
    flock -n "$PORTAL_STATE_DIR/cutover.lock" true \
        || fail 'the host cutover lock was still held after the script exited'
}

assert_preflight_refuses_before_downtime() {
    local log_file="$1"
    local expected_message="$2"
    local scenario="$3"
    local status
    printf 'Checking preflight refusal scenario: %s\n' "$scenario"
    set +e
    "$CUTOVER_SCRIPT" > "$log_file" 2>&1
    status=$?
    set -e
    [[ "$status" -ne 0 ]] || fail "invalid cutover preflight unexpectedly succeeded for scenario '$scenario'"
    grep -Fq "$expected_message" "$log_file" \
        || { cat "$log_file" >&2; fail "preflight refusal did not name: $expected_message"; }
    [[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'a refused preflight wrote an in-progress marker'
    [[ ! -e "$PORTAL_BACKUP_DIR" ]] || fail 'a refused preflight created the backup directory'
    if grep -Fq 'stop backend' "$FAKE_DOCKER_LOG"; then
        fail 'a refused preflight stopped the live backend before refusing'
    fi
    assert_lock_released
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

export PORTAL_BACKUP_DIR="$temporary_root/production-override-must-not-be-used/backups"
export PORTAL_STATE_DIR="$temporary_root/production-override-must-not-be-used/state"
unset PORTAL_STACK_DIR
set +e
"$CUTOVER_SCRIPT" > "$temporary_root/reject-production-overrides.log" 2>&1
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
set +e
"$CUTOVER_SCRIPT" > "$temporary_root/lock-contention-cutover.log" 2>&1
contention_status=$?
set -e
[[ "$contention_status" -ne 0 ]] || fail 'cutover was accepted while another run held the host lock'
grep -Fq 'already holds the host lock' "$temporary_root/lock-contention-cutover.log" \
    || { cat "$temporary_root/lock-contention-cutover.log" >&2; fail 'cutover did not name the held host lock'; }
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
    'The PostgreSQL target filesystem has less than 3x the current MariaDB data size free.' \
    'low target space with DockerRootDir/volumes present'
grep -Fxq "$FAKE_DOCKER_ROOT_DIR/volumes" "$FAKE_DF_LOG" \
    || fail 'a not-yet-created PostgreSQL volume did not check the Docker volumes filesystem'

prepare_case postgres-low-space-docker-root-only false
export FAKE_TARGET_FREE_KIB=0
export FAKE_PG_CONTAINER_EXISTS=false
assert_preflight_refuses_before_downtime \
    "$temporary_root/postgres-low-space-docker-root-only.log" \
    'The PostgreSQL target filesystem has less than 3x the current MariaDB data size free.' \
    'low target space with DockerRootDir/volumes absent'
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
        'Compose database services do not match the MariaDB/PostgreSQL cutover topology.' \
        "unsupported target volume: $invalid_volume_mode"
done

prepare_case postgres-wrong-volume-mount
export FAKE_PG_MOUNTED_VOLUME_NAME='unrelated_pg_data'
assert_preflight_refuses_before_downtime \
    "$temporary_root/postgres-wrong-volume-mount.log" \
    'The existing PostgreSQL container does not mount the Compose target volume.' \
    'existing container with mismatched target volume'

prepare_case successful-cutover
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
assert_artisan_config_override "$FAKE_DOCKER_LOG"
assert_database_command_contract "$FAKE_DOCKER_LOG"
assert_pg_migration_command "$FAKE_DOCKER_LOG"
assert_mariadb_status_command "$FAKE_DOCKER_LOG"
assert_pg_commands_target_db_pg "$FAKE_DOCKER_LOG"
assert_lock_released

# With the rollback machinery removed, a failed switch must fail closed: the
# named FATAL, the saved .env backup and the in-progress marker survive for the
# operator, and no rollback helper may restore the previous engine.
prepare_case switch-recreate-fails-closed
export FAKE_SWITCH_FAIL=recreate
set +e
"$CUTOVER_SCRIPT" > "$temporary_root/switch-recreate-fails-closed.log" 2>&1
switch_status=$?
set -e
[[ "$switch_status" -ne 0 ]] || fail 'a failed backend recreate was accepted as a successful cutover'
grep -Fq 'Switch failed while recreating the backend' "$temporary_root/switch-recreate-fails-closed.log" \
    || { cat "$temporary_root/switch-recreate-fails-closed.log" >&2; fail 'switch failure did not name the failing step and preserved artifacts'; }
env_file="$PORTAL_STACK_DIR/.env"
grep -Fxq 'DB_CONNECTION=pgsql' "$env_file" \
    || fail 'a failed switch did not leave the host .env on PostgreSQL (rollback attempted?)'
[[ -f "$PORTAL_STATE_DIR/in-progress" ]] || fail 'a failed switch removed the in-progress marker'
env_backup="$(ls -1 "$PORTAL_STACK_DIR"/.env.*.bak 2>/dev/null | head -1)"
[[ -n "$env_backup" && -f "$env_backup" ]] || fail 'a failed switch did not keep the saved .env backup'
grep -Fxq 'DB_CONNECTION=mariadb' "$env_backup" || fail 'the saved .env backup lost its pre-cutover MariaDB setting'
assert_lock_released

echo 'PASS: host cutover backup, config-cache override, disk/volume preflight, host lock, fail-closed switch, and named fail-closed preflight lifecycle'
