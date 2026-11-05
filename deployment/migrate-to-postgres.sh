#!/usr/bin/env bash
set -euo pipefail

readonly STACK_DIR="${PORTAL_STACK_DIR:-/opt/stacks/portal-reisinger-pictures}"
readonly COMPOSE_FILE="$STACK_DIR/compose.yaml"
readonly ENV_FILE="$STACK_DIR/.env"
readonly BACKUP_DIR="${PORTAL_BACKUP_DIR:-/var/backups/portal}"
readonly STATE_DIR="${PORTAL_STATE_DIR:-/var/lib/portal-postgres-cutover}"
readonly MARKER_FILE="$STATE_DIR/in-progress"
readonly LAST_ENV_BACKUP_FILE="$STATE_DIR/last-env-backup"
readonly SOURCE_CONTAINER="portal_db"
readonly TARGET_SERVICE="db_pg"
readonly TARGET_CONTAINER="portal_db_pg"
readonly BACKEND_SERVICE="backend"

DRY_RUN=false
ROLLBACK=false
CURRENT_PHASE="PREFLIGHT"

usage() {
    cat <<'USAGE'
Usage: deployment/migrate-to-postgres.sh [--dry-run | --rollback | --help]

Runs on the AlmaLinux production host as root. The stack must be at
/opt/stacks/portal-reisinger-pictures (or PORTAL_STACK_DIR for a controlled
test environment). PORTAL_BACKUP_DIR and PORTAL_STATE_DIR may also be set for
isolated tests only when PORTAL_STACK_DIR points at that controlled stack; the
production defaults remain /var/backups/portal and /var/lib/portal-postgres-cutover.
The migration phases are:

  PREFLIGHT  Check the stack, containers, images, free disk, source env, and
             that no unfinished cutover marker exists.
  BACKUP     Stop the backend to establish a write freeze, then create and
             verify a private MariaDB dump and exact per-table row manifest.
  PG         Start only db_pg (portal_db_pg) and wait for PostgreSQL readiness.
  MIGRATE    Run Laravel migrations in a one-off backend container against PG.
  COPY       Copy MariaDB rows to PG with portal:db-copy.
  VERIFY     Run all copy invariants and independent smoke queries.
  SWITCH     Back up and edit the host .env, recreate the backend, and wait
             for its healthcheck. MariaDB remains running and untouched.

  --dry-run  Print the complete plan without requiring the stack or changing
             anything. Safe to run on a development machine.
  --rollback Restore the saved host .env, select the legacy MariaDB coordinates,
             and recreate the backend. MariaDB is not restored.
  --help     Show this help.

The backup is stored under /var/backups/portal with mode 0600. The in-progress
marker is deliberately left behind after a failure; use --rollback before
starting a new attempt.
USAGE
}

plan() {
    cat <<PLAN
PREFLIGHT:
  test -d '$STACK_DIR' && test -f '$COMPOSE_FILE' && test -f '$ENV_FILE'
  docker info
  docker compose -f '$COMPOSE_FILE' version
  command -v python3 gzip sha256sum du df stat install awk grep sed seq; fail before downtime if any are missing
  docker inspect '$SOURCE_CONTAINER'; require State.Running=true
  docker compose -f '$COMPOSE_FILE' config --services; require db, db_pg, backend
  docker compose -f '$COMPOSE_FILE' config --format json | python3 <validate db/db_pg names, images, and volumes>
  docker compose -f '$COMPOSE_FILE' config --format json | python3 <read backend image>
  docker image inspect <configured backend image>
  docker compose -f '$COMPOSE_FILE' run --rm --no-deps backend php -r <require pdo_mysql and pdo_pgsql>
  docker compose -f '$COMPOSE_FILE' run --rm --no-deps backend php artisan list --raw | grep '^portal:db-copy([[:space:]]|$)'
  test ! -e '$MARKER_FILE'
  python3 <read DB_CONNECTION and optional DB_HOST/DB_PORT privately; use historical Compose defaults when host/port are absent>
  docker compose -f '$COMPOSE_FILE' run --rm --no-deps -e DB_CONNECTION=mariadb -e DB_HOST=<source> -e DB_PORT=<source> backend php artisan migrate:status --database=mariadb --no-ansi; require no Pending migrations
  docker inspect -f <MariaDB /var/lib/mysql host mount> '$SOURCE_CONTAINER'
  du -sb <MariaDB data path>; df -Pk <data path and backup filesystem>; require 3x free
  install -d -o root -g root -m 0700 '$STATE_DIR' '$BACKUP_DIR'
BACKUP:
  docker compose -f '$COMPOSE_FILE' stop '$BACKEND_SERVICE'  # write freeze
  docker exec '$SOURCE_CONTAINER' mariadb-dump --single-transaction --routines --triggers --events --all-databases --tz-utc | gzip > '$BACKUP_DIR/mariadb-<UTC>.sql.gz'
  chmod 0600; gzip -t '$BACKUP_DIR/mariadb-<UTC>.sql.gz'; stat -c %s; require size >= 1 KiB
  sha256sum '$BACKUP_DIR/mariadb-<UTC>.sql.gz' > '$BACKUP_DIR/mariadb-<UTC>.sql.gz.sha256'
  docker exec '$SOURCE_CONTAINER' mariadb -N -e 'SELECT TABLE_NAME FROM information_schema.TABLES ...'
  for each source table: docker exec '$SOURCE_CONTAINER' mariadb -N -e 'SELECT COUNT(*) FROM portal_db.<table>' > manifest
PG:
  docker compose -f '$COMPOSE_FILE' up -d '$TARGET_SERVICE'
  repeat docker exec '$TARGET_CONTAINER' pg_isready -h 127.0.0.1 -p 5432 until ready (timeout 120s)
MIGRATE:
  docker compose -f '$COMPOSE_FILE' run --rm --no-deps -e DB_CONNECTION=pgsql -e DB_HOST=db_pg -e DB_PORT=5432 -e DB_URL= backend php artisan migrate --force
COPY:
  docker compose -f '$COMPOSE_FILE' run --rm --no-deps -e DB_CONNECTION=pgsql -e DB_HOST=db_pg -e DB_PORT=5432 -e DB_URL= backend php artisan portal:db-copy --source=mariadb --target=pgsql --source-host=<DB_HOST from .env> --source-port=<DB_PORT from .env> --target-host=db_pg --target-port=5432
VERIFY:
  run portal:db-copy --verify-only against PG
  run php artisan migrate:status --database=pgsql against PG
  run Tinker SELECT 1 and COUNT queries for users, orders, and settings against PG
SWITCH:
  cp -p '$ENV_FILE' '$ENV_FILE.<UTC>.bak'; atomically set DB_CONNECTION=pgsql, DB_HOST=db_pg, DB_PORT=5432, and queue/cache connection keys to pgsql
  docker compose -f '$COMPOSE_FILE' up -d --force-recreate '$BACKEND_SERVICE'; wait for healthy
  if recreation or healthcheck fails: restore the saved .env and recreate the backend against MariaDB
ROLLBACK:
  restore saved .env; set DB_CONNECTION=mariadb, DB_HOST=db, DB_PORT=3306, and queue/cache connection keys to mariadb
  docker compose -f '$COMPOSE_FILE' up -d --force-recreate '$BACKEND_SERVICE'; wait for healthy; do not alter MariaDB
PLAN
}

fail() {
    printf 'FATAL: %s\n' "$1" >&2
    exit 1
}

on_error() {
    local status=$?
    printf 'FATAL: stopped during %s (exit %s). The in-progress marker, if created, was preserved.\n' \
        "$CURRENT_PHASE" "$status" >&2
    exit "$status"
}

banner() {
    CURRENT_PHASE="$1"
    printf '\n========== %s ==========\n' "$CURRENT_PHASE"
}

read_env_value() {
    local key="$1"
    local default="${2-}"
    local has_default=false
    [[ "$#" -ge 2 ]] && has_default=true

    python3 - "$ENV_FILE" "$key" "$default" "$has_default" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
key = sys.argv[2]
default = sys.argv[3]
has_default = sys.argv[4] == "true"
prefix = key + "="
matches = [line[len(prefix):].strip() for line in path.read_text().splitlines() if line.startswith(prefix)]
if len(matches) > 1 or (not matches and not has_default):
    raise SystemExit(2)
value = matches[0] if matches else default
if len(value) >= 2 and value[0] == value[-1] and value[0] in "'\"":
    value = value[1:-1]
print(value)
PY
}

wait_for_postgres() {
    local attempt
    for attempt in $(seq 1 60); do
        if docker exec "$TARGET_CONTAINER" pg_isready -h 127.0.0.1 -p 5432 >/dev/null 2>&1; then
            printf 'PostgreSQL readiness: accepting connections.\n'
            return 0
        fi
        sleep 2
    done
    fail "PostgreSQL container '$TARGET_CONTAINER' did not become ready."
}

wait_for_backend() {
    local attempt status
    for attempt in $(seq 1 60); do
        status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' portal_backend 2>/dev/null || true)"
        if [[ "$status" == "healthy" ]]; then
            printf 'Backend healthcheck: healthy.\n'
            return 0
        fi
        if [[ "$status" == "unhealthy" || "$status" == "no-healthcheck" ]]; then
            printf 'FATAL: backend did not report a healthy status.\n' >&2
            return 1
        fi
        sleep 5
    done
    printf 'FATAL: backend healthcheck did not become healthy before timeout.\n' >&2
    return 1
}

compose() {
    docker compose --project-directory "$STACK_DIR" --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

require_root() {
    [[ "$EUID" -eq 0 ]] || fail 'Run this command as root on the production host.'
}

validate_test_directory_overrides() {
    if [[ -n "${PORTAL_BACKUP_DIR:-}" || -n "${PORTAL_STATE_DIR:-}" ]]; then
        [[ -n "${PORTAL_STACK_DIR:-}" && "$STACK_DIR" != '/opt/stacks/portal-reisinger-pictures' ]] \
            || fail 'PORTAL_BACKUP_DIR and PORTAL_STATE_DIR are test-only and require PORTAL_STACK_DIR to point at a controlled test stack.'
    fi
}

atomic_write_marker() {
    local env_backup="$1"
    local temporary="$MARKER_FILE.tmp"
    {
        printf 'started_at=%s\n' "$RUN_TIMESTAMP"
        printf 'env_backup=%s\n' "$env_backup"
    } > "$temporary"
    chmod 0600 "$temporary"
    mv -f "$temporary" "$MARKER_FILE"
}

save_marker_env_backup() {
    local env_backup="$1"
    local temporary="$MARKER_FILE.tmp"
    {
        printf 'started_at=%s\n' "$RUN_TIMESTAMP"
        printf 'env_backup=%s\n' "$env_backup"
    } > "$temporary"
    chmod 0600 "$temporary"
    mv -f "$temporary" "$MARKER_FILE"
}

restore_env_backup() {
    local backup="$1"
    [[ -f "$backup" ]] || fail 'The saved host .env backup is missing; refusing to guess the rollback values.'
    [[ "$backup" == "$STACK_DIR"/.env.* ]] || fail 'The saved .env backup path is outside the configured stack directory.'

    local temporary="$ENV_FILE.rollback.tmp"
    cp -p -- "$backup" "$temporary"
    mv -f -- "$temporary" "$ENV_FILE"
}

rollback_failed_switch() {
    local backup="$1"
    printf 'Switch health failed; restoring the saved host .env and returning the backend to MariaDB.\n' >&2
    restore_env_backup "$backup"
    patch_host_env_for_mariadb
    compose up -d --force-recreate "$BACKEND_SERVICE"
    wait_for_backend || fail 'Automatic switch rollback restored .env but the backend did not become healthy.'
    rm -f -- "$MARKER_FILE"
    fail 'The PostgreSQL switch failed and was automatically rolled back to MariaDB.'
}

patch_host_env_for_database() {
    local connection="$1"
    local host="$2"
    local port="$3"
    python3 - "$ENV_FILE" "$connection" "$host" "$port" <<'PY'
import os
import pathlib
import stat
import sys
import tempfile

env_path = pathlib.Path(sys.argv[1])
connection = sys.argv[2]
host = sys.argv[3]
port = sys.argv[4]
original = env_path.read_bytes()
metadata = env_path.stat()
lines = original.decode("utf-8").splitlines(keepends=True)
required = {"DB_CONNECTION": connection}
managed = {"DB_HOST": host, "DB_PORT": port}
optional = {
    "DB_QUEUE_CONNECTION": connection,
    "DB_CACHE_CONNECTION": connection,
    "DB_CACHE_LOCK_CONNECTION": connection,
}
updates = {**required, **managed, **optional}
tracked_keys = set(required) | set(managed) | set(optional)
found = {key: 0 for key in tracked_keys}
updated = []

for line in lines:
    newline = "\r\n" if line.endswith("\r\n") else "\n" if line.endswith("\n") else ""
    content = line[:-len(newline)] if newline else line
    matched = False
    for key, value in updates.items():
        if content.startswith(key + "="):
            if key in found:
                found[key] += 1
            updated.append(f"{key}={value}{newline}")
            matched = True
            break
    if not matched:
        updated.append(line)

if found["DB_CONNECTION"] != 1 or any(found[key] > 1 for key in set(managed) | set(optional)):
    raise SystemExit(2)

newline_style = "\r\n" if b"\r\n" in original else "\n"
for key in ("DB_HOST", "DB_PORT", "DB_QUEUE_CONNECTION", "DB_CACHE_CONNECTION", "DB_CACHE_LOCK_CONNECTION"):
    if found[key] == 0:
        if updated and not updated[-1].endswith(("\n", "\r")):
            updated[-1] += newline_style
        value = managed.get(key, optional.get(key))
        updated.append(f"{key}={value}{newline_style}")

fd, temporary_name = tempfile.mkstemp(prefix=".env.pg.", dir=env_path.parent)
try:
    with os.fdopen(fd, "wb") as temporary:
        temporary.write("".join(updated).encode("utf-8"))
        temporary.flush()
        os.fsync(temporary.fileno())
    os.chmod(temporary_name, stat.S_IMODE(metadata.st_mode))
    os.chown(temporary_name, metadata.st_uid, metadata.st_gid)
    os.replace(temporary_name, env_path)
finally:
    if os.path.exists(temporary_name):
        os.unlink(temporary_name)

PY
}

patch_host_env_for_postgres() {
    patch_host_env_for_database pgsql db_pg 5432
}

patch_host_env_for_mariadb() {
    patch_host_env_for_database mariadb db 3306
}

run_rollback() {
    require_root
    validate_test_directory_overrides
    banner ROLLBACK
    [[ -d "$STACK_DIR" && -f "$COMPOSE_FILE" && -f "$ENV_FILE" ]] \
        || fail 'The host stack directory, compose.yaml, or .env is missing.'
    docker info >/dev/null 2>&1 || fail 'Docker daemon is not usable.'
    compose version >/dev/null 2>&1 || fail 'docker compose is not usable.'

    local backup=''
    if [[ -f "$MARKER_FILE" ]]; then
        backup="$(sed -n 's/^env_backup=//p' "$MARKER_FILE" | head -n 1)"
    elif [[ -f "$LAST_ENV_BACKUP_FILE" ]]; then
        backup="$(cat "$LAST_ENV_BACKUP_FILE")"
    else
        fail 'No in-progress cutover or saved .env backup is available for rollback.'
    fi

    if [[ -n "$backup" ]]; then
        restore_env_backup "$backup"
        printf 'Restored the saved host .env; values were not displayed.\n'
    else
        printf 'No environment switch had been recorded; the host .env was left unchanged.\n'
    fi

    patch_host_env_for_mariadb

    compose up -d --force-recreate "$BACKEND_SERVICE"
    wait_for_backend
    rm -f -- "$MARKER_FILE"
    printf 'Rollback complete. MariaDB was not modified or restored.\n'
}

run_cutover() {
    require_root
    validate_test_directory_overrides
    banner PREFLIGHT

    [[ -d "$STACK_DIR" ]] || fail "Stack directory '$STACK_DIR' is missing."
    [[ -f "$COMPOSE_FILE" ]] || fail 'compose.yaml is missing from the stack directory.'
    [[ -f "$ENV_FILE" ]] || fail 'The protected host .env is missing.'
    docker info >/dev/null 2>&1 || fail 'Docker daemon is not usable.'
    compose version >/dev/null 2>&1 || fail 'docker compose is not usable.'
    local required_binary
    for required_binary in python3 gzip sha256sum du df stat install awk grep sed seq; do
        command -v "$required_binary" >/dev/null 2>&1 \
            || fail "Required host command '$required_binary' is missing. Install it before starting the cutover."
    done
    docker inspect "$SOURCE_CONTAINER" >/dev/null 2>&1 || fail "MariaDB container '$SOURCE_CONTAINER' is missing."
    [[ "$(docker inspect --format '{{.State.Running}}' "$SOURCE_CONTAINER")" == 'true' ]] \
        || fail "MariaDB container '$SOURCE_CONTAINER' is not running."

    local services
    services="$(compose config --services)"
    for service in db db_pg backend; do
        grep -Fxq "$service" <<< "$services" || fail "Compose service '$service' is missing."
    done

    compose config --format json | python3 -c '
import json, sys
services = json.load(sys.stdin)["services"]
source = services["db"]
target = services["db_pg"]
valid = (
    source.get("container_name") == "portal_db"
    and str(source.get("image", "")).startswith("mariadb:11.4")
    and target.get("container_name") == "portal_db_pg"
    and str(target.get("image", "")).startswith("postgres:16")
    and any(volume.get("source") == "db_data" for volume in source.get("volumes", []))
    and any(volume.get("source") == "db_pg_data" for volume in target.get("volumes", []))
)
raise SystemExit(0 if valid else 1)
' || fail 'Compose database services do not match the MariaDB/PostgreSQL cutover topology.'

    local backend_image
    backend_image="$(compose config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["services"]["backend"]["image"])')"
    docker image inspect "$backend_image" >/dev/null 2>&1 || fail 'The configured backend image is not present locally.'
    compose run --rm --no-deps "$BACKEND_SERVICE" php -r '
        foreach (["pdo_mysql", "pdo_pgsql"] as $extension) {
            if (! extension_loaded($extension)) {
                fwrite(STDERR, "Missing required PHP extension: {$extension}\n");
                exit(1);
            }
        }
        echo "Both database PDO drivers are available.\n";
    ' || fail 'The backend image must contain both pdo_mysql and pdo_pgsql before the cutover.'
    compose run --rm --no-deps "$BACKEND_SERVICE" php artisan list --raw \
        | grep -Eq '^portal:db-copy([[:space:]]|$)' \
        || fail 'The deployed backend code does not register portal:db-copy; deploy the cutover-capable code first.'
    [[ ! -e "$MARKER_FILE" ]] || fail "An unfinished cutover marker exists at '$MARKER_FILE'; run --rollback first."

    local source_host source_port source_connection source_host_origin source_port_origin
    if grep -q '^DB_HOST=' "$ENV_FILE"; then
        source_host="$(read_env_value DB_HOST db)" || fail 'DB_HOST must occur at most once in the host .env.'
        source_host_origin='host .env key DB_HOST'
    else
        source_host="$(read_env_value DB_HOST db)" || fail 'Could not select the historical DB_HOST default.'
        source_host_origin='historical Compose default (DB_HOST absent)'
    fi
    if grep -q '^DB_PORT=' "$ENV_FILE"; then
        source_port="$(read_env_value DB_PORT 3306)" || fail 'DB_PORT must occur at most once in the host .env.'
        source_port_origin='host .env key DB_PORT'
    else
        source_port="$(read_env_value DB_PORT 3306)" || fail 'Could not select the historical DB_PORT default.'
        source_port_origin='historical Compose default (DB_PORT absent)'
    fi
    source_connection="$(read_env_value DB_CONNECTION)" || fail 'DB_CONNECTION must be present exactly once in the host .env.'
    [[ "$source_host" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'DB_HOST must be a plain Docker hostname for the source connection.'
    [[ "$source_port" =~ ^[0-9]+$ ]] || fail 'DB_PORT must be a numeric source port.'
    [[ "$source_connection" == 'mariadb' ]] || fail 'The host .env must still select MariaDB before starting the cutover.'
    printf 'Source host selected from %s.\n' "$source_host_origin"
    printf 'Source port selected from %s.\n' "$source_port_origin"

    local source_migration_status
    source_migration_status="$(compose run --rm --no-deps \
        -e DB_CONNECTION=mariadb -e DB_HOST="$source_host" -e DB_PORT="$source_port" -e DB_URL= \
        "$BACKEND_SERVICE" php artisan migrate:status --database=mariadb --no-ansi)" \
        || fail 'Could not read the MariaDB migration status; deploy the PostgreSQL-capable code and retry.'
    if grep -Eq '(^|[[:space:]])Pending([[:space:]]|$)' <<< "$source_migration_status"; then
        fail 'MariaDB has pending migrations. Complete the preparatory MariaDB deployment before the cutover; the copy requires the source at the current migration frontier.'
    fi
    printf 'MariaDB migration status: no pending migrations.\n'

    local source_running image_data_path data_bytes free_kib backup_free_kib backup_probe
    source_running="$(docker inspect --format '{{.State.Running}}' "$SOURCE_CONTAINER")"
    [[ "$source_running" == 'true' ]] || fail 'MariaDB is not running.'
    image_data_path="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/mysql"}}{{.Source}}{{end}}{{end}}' "$SOURCE_CONTAINER")"
    [[ -n "$image_data_path" && -d "$image_data_path" ]] || fail 'Could not locate the MariaDB data volume on the host.'
    data_bytes="$(du -sb "$image_data_path" | awk '{print $1}')"
    free_kib="$(df -Pk "$image_data_path" | awk 'END {print $4}')"
    backup_probe="$BACKUP_DIR"
    while [[ ! -e "$backup_probe" ]]; do
        backup_probe="$(dirname "$backup_probe")"
    done
    backup_free_kib="$(df -Pk "$backup_probe" | awk 'END {print $4}')"
    [[ "$data_bytes" =~ ^[0-9]+$ && "$free_kib" =~ ^[0-9]+$ && "$backup_free_kib" =~ ^[0-9]+$ ]] \
        || fail 'Could not measure MariaDB data size or free disk space.'
    (( free_kib * 1024 >= data_bytes * 3 )) || fail 'The MariaDB volume filesystem has less than 3x the current MariaDB data size free.'
    (( backup_free_kib * 1024 >= data_bytes * 3 )) || fail 'The backup filesystem has less than 3x the current MariaDB data size free.'

    RUN_TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
    readonly RUN_TIMESTAMP
    local backup_path="$BACKUP_DIR/mariadb-${RUN_TIMESTAMP}.sql.gz"
    local manifest_path="$BACKUP_DIR/mariadb-${RUN_TIMESTAMP}.manifest.tsv"
    local temporary_dump="${backup_path}.tmp"
    local temporary_manifest="${manifest_path}.tmp"

    install -d -o root -g root -m 0700 "$STATE_DIR"
    install -d -o root -g root -m 0700 "$BACKUP_DIR"
    atomic_write_marker ''

    banner BACKUP
    printf 'Write freeze: stopping backend service before backup and copy.\n'
    compose stop "$BACKEND_SERVICE"

    umask 077
    if ! docker exec "$SOURCE_CONTAINER" sh -c \
        'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mariadb-dump --user=root --single-transaction --routines --triggers --events --all-databases --tz-utc' \
        | gzip -c > "$temporary_dump"; then
        rm -f -- "$temporary_dump"
        fail 'MariaDB dump failed.'
    fi
    chmod 0600 "$temporary_dump"
    gzip -t "$temporary_dump" || fail 'MariaDB dump failed gzip integrity verification.'
    local dump_size
    dump_size="$(stat -c '%s' "$temporary_dump")"
    (( dump_size >= 1024 )) || fail 'MariaDB dump is unexpectedly small (less than 1 KiB).'
    mv -- "$temporary_dump" "$backup_path"
    sha256sum "$backup_path" > "${backup_path}.sha256"
    chmod 0600 "${backup_path}.sha256"

    {
        printf 'table\trows\n'
        local tables table count
        tables="$(docker exec "$SOURCE_CONTAINER" sh -c \
            'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mariadb --user=root --batch --skip-column-names --execute "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '\''portal_db'\'' AND TABLE_TYPE = '\''BASE TABLE'\'' ORDER BY TABLE_NAME"')"
        while IFS= read -r table; do
            [[ -n "$table" ]] || continue
            [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || fail 'A source table name is not a simple SQL identifier; refusing to build a manifest query.'
            count="$(docker exec "$SOURCE_CONTAINER" sh -c \
                'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mariadb --user=root --batch --skip-column-names --execute "$1"' \
                _ "SELECT COUNT(*) FROM \`portal_db\`.\`$table\`")"
            [[ "$count" =~ ^[0-9]+$ ]] || fail "Could not record a row count for table '$table'."
            printf '%s\t%s\n' "$table" "$count"
        done <<< "$tables"
    } > "$temporary_manifest"
    chmod 0600 "$temporary_manifest"
    [[ -s "$temporary_manifest" ]] || fail 'The MariaDB row-count manifest is empty.'
    mv -- "$temporary_manifest" "$manifest_path"
    printf 'Verified backup: %s (mode 0600); manifest: %s.\n' "$backup_path" "$manifest_path"

    banner PG
    compose up -d "$TARGET_SERVICE"
    wait_for_postgres

    banner MIGRATE
    compose run --rm --no-deps \
        -e DB_CONNECTION=pgsql -e DB_HOST=db_pg -e DB_PORT=5432 -e DB_URL= \
        "$BACKEND_SERVICE" php artisan migrate --force

    banner COPY
    compose run --rm --no-deps \
        -e DB_CONNECTION=pgsql -e DB_HOST=db_pg -e DB_PORT=5432 -e DB_URL= \
        "$BACKEND_SERVICE" php artisan portal:db-copy \
        --source=mariadb --target=pgsql \
        --source-host="$source_host" --source-port="$source_port" \
        --target-host=db_pg --target-port=5432

    banner VERIFY
    compose run --rm --no-deps \
        -e DB_CONNECTION=pgsql -e DB_HOST=db_pg -e DB_PORT=5432 -e DB_URL= \
        "$BACKEND_SERVICE" php artisan portal:db-copy \
        --source=mariadb --target=pgsql --verify-only \
        --source-host="$source_host" --source-port="$source_port" \
        --target-host=db_pg --target-port=5432
    compose run --rm --no-deps \
        -e DB_CONNECTION=pgsql -e DB_HOST=db_pg -e DB_PORT=5432 -e DB_URL= \
        "$BACKEND_SERVICE" php artisan migrate:status --database=pgsql
    compose run --rm --no-deps \
        -e DB_CONNECTION=pgsql -e DB_HOST=db_pg -e DB_PORT=5432 -e DB_URL= \
        "$BACKEND_SERVICE" php artisan tinker --execute='$db = Illuminate\Support\Facades\DB::connection("pgsql"); $db->select("SELECT 1 AS ready"); foreach (["users", "orders", "settings"] as $table) { $db->table($table)->count(); } echo "Smoke queries passed.\n";'

    banner SWITCH
    local env_backup="$STACK_DIR/.env.${RUN_TIMESTAMP}.bak"
    cp -p -- "$ENV_FILE" "$env_backup"
    chmod 0600 "$env_backup"
    printf '%s\n' "$env_backup" > "$LAST_ENV_BACKUP_FILE"
    chmod 0600 "$LAST_ENV_BACKUP_FILE"
    save_marker_env_backup "$env_backup"
    if ! patch_host_env_for_postgres; then
        rollback_failed_switch "$env_backup"
    fi
    if ! compose up -d --force-recreate "$BACKEND_SERVICE"; then
        rollback_failed_switch "$env_backup"
    fi
    if ! wait_for_backend; then
        rollback_failed_switch "$env_backup"
    fi
    rm -f -- "$MARKER_FILE"
    printf 'PostgreSQL cutover complete. MariaDB remains running and untouched. Saved .env backup: %s\n' "$env_backup"
    printf "Rollback command: ssh root@reisinger.pictures 'bash -s -- --rollback' < deployment/migrate-to-postgres.sh\n"
}

for argument in "$@"; do
    case "$argument" in
        --dry-run) DRY_RUN=true ;;
        --rollback) ROLLBACK=true ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$argument" >&2; usage >&2; exit 2 ;;
    esac
done

if [[ "$DRY_RUN" == true && "$ROLLBACK" == true ]]; then
    fail '--dry-run and --rollback cannot be combined.'
fi

if [[ "$DRY_RUN" == true ]]; then
    plan
    exit 0
fi

trap on_error ERR

if [[ "$ROLLBACK" == true ]]; then
    run_rollback
else
    run_cutover
fi
