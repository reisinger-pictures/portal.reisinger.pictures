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
    mkdir -p "$FAKE_DOCKER_STATE"
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

"$CUTOVER_SCRIPT" --rollback > "$temporary_root/explicit-rollback.log" 2>&1 || {
    cat "$temporary_root/explicit-rollback.log" >&2
    fail 'the explicit rollback lifecycle did not complete'
}
assert_mariadb_env
[[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'explicit rollback left the in-progress marker behind'

prepare_case automatic-rollback
export FAKE_HEALTH_MODE=unhealthy-first
set +e
"$CUTOVER_SCRIPT" > "$temporary_root/automatic-rollback.log" 2>&1
automatic_status=$?
set -e
[[ "$automatic_status" -ne 0 ]] || fail 'failed PostgreSQL health unexpectedly reported a successful cutover'
grep -Fq 'automatically rolled back to MariaDB' "$temporary_root/automatic-rollback.log" \
    || { cat "$temporary_root/automatic-rollback.log" >&2; fail 'automatic rollback did not name the PostgreSQL switch failure'; }
assert_mariadb_env
[[ ! -e "$PORTAL_STATE_DIR/in-progress" ]] || fail 'automatic rollback left the in-progress marker behind'
[[ "$(cat "$FAKE_DOCKER_STATE/backend-health-count")" == '2' ]] \
    || fail 'automatic rollback did not test PostgreSQL failure and MariaDB recovery health separately'

echo 'PASS: host cutover backup, switch, explicit rollback, and automatic rollback lifecycle'
