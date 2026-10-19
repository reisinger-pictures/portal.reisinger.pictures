#!/usr/bin/env bash
#
# Measure the D-40 org-sync lock order against a disposable MariaDB with real
# concurrent connections.
#
# The probe fires two org membership syncs at the same time, over two
# different orgs, whose actors are members of each other's org:
#
#   sync 1: actor A (member of org Y) syncs org X -> detaches actor B
#   sync 2: actor B (member of org X) syncs org Y -> detaches actor A
#
# Both syncs need the row of the other actor. With the pre-D-40 order
# (org -> actor -> members -> sorted union) sync 1 holds A and waits for B
# while sync 2 holds B and waits for A: a lock cycle that MariaDB breaks by
# aborting one transaction (Innodb_deadlocks +1, the request fails with a
# deadlock error). With the D-40 order (org -> one ascending lock set) both
# transactions take the same rows in the same order, so one waits for the other
# and the cycle cannot form.
#
# Usage (from backend/):
#   DOCKER_BIN="$HOME/.rd/bin/docker" ./tests/scripts/org-sync-deadlock-mariadb-probe.sh
#
# Overridable: MARIADB_IMAGE (default mariadb:11.4), SCENARIOS (default 12),
# MIN_ROW_LOCK_WAITS (default 1), DOCKER_BIN.
#
# MIN_ROW_LOCK_WAITS is the positive control: the two syncs of a scenario lock
# the same user rows, so a run in which they never overlapped never produced a
# row-lock wait and proves nothing. A run below that threshold is reported as
# INCONCLUSIVE (exit 1), not as a pass.
#
# CI-only: the script needs Docker. It is not part of the PHPUnit suite and
# not part of a local test run. To see the pre-D-40 behaviour, revert
# app/Http/Controllers/OrgController.php to the previous lock order and run the
# script again: the run must then report a non-zero InnoDB deadlocks delta.
#
# Exit codes: 0 = no deadlock in the contended sample, 1 = harness / usage /
# availability failure — including an INCONCLUSIVE run, i.e. the two syncs never
# contended (fewer InnoDB row-lock waits than MIN_ROW_LOCK_WAITS) — and 2 = a
# deadlock was observed (a measured defect; the raw counters above are the
# evidence). A deadlock aborts one transaction, so it also shows up as a non-200
# response: the defect verdict is therefore evaluated before the harness
# verdict. The disposable container, volume and temp files are removed on every
# exit path.
set -Eeuo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"

if [ -n "${DOCKER_BIN:-}" ]; then
    DOCKER="$DOCKER_BIN"
elif command -v docker >/dev/null 2>&1; then
    DOCKER="$(command -v docker)"
elif [ -x "$HOME/.rd/bin/docker" ]; then
    DOCKER="$HOME/.rd/bin/docker"
else
    echo 'Docker CLI not found (set DOCKER_BIN if needed).' >&2
    exit 1
fi
# Rancher Desktop installs docker-credential-* next to the CLI. Without this,
# a first-time `docker run` pull fails before the image is fetched.
PATH="$(dirname "$DOCKER"):$PATH"
export PATH

"$REPO_DIR/scripts/check-php-version.sh"

MARIADB_IMAGE="${MARIADB_IMAGE:-mariadb:11.4}"
SCENARIOS="${SCENARIOS:-12}"
MIN_ROW_LOCK_WAITS="${MIN_ROW_LOCK_WAITS:-1}"

case "$SCENARIOS" in
    ''|*[!0-9]*) echo 'SCENARIOS must be a positive integer.' >&2; exit 1 ;;
esac
if [ "$SCENARIOS" -lt 1 ]; then
    echo 'SCENARIOS must be a positive integer.' >&2
    exit 1
fi
case "$MIN_ROW_LOCK_WAITS" in
    ''|*[!0-9]*) echo 'MIN_ROW_LOCK_WAITS must be a non-negative integer.' >&2; exit 1 ;;
esac

suffix="$(openssl rand -hex 6)"
container_name="org-sync-deadlock-mariadb-$suffix"
database_name='org_sync_deadlock_probe'
database_user='org_sync_probe_user'
database_root_password="$(openssl rand -hex 24)"
database_password="$(openssl rand -hex 24)"
admin_password="$(openssl rand -hex 24)"
temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/org-sync-deadlock-mariadb.XXXXXX")"
container_started=0
database_volume=''
server_pid=''
client_pids=()

cleanup() {
    exit_code=$?
    trap - EXIT

    if [ "${#client_pids[@]}" -gt 0 ]; then
        for client_pid in "${client_pids[@]}"; do
            kill "$client_pid" 2>/dev/null || true
        done
    fi

    if [ -n "$server_pid" ]; then
        # Kill the master and any worker by its unique router path: workers
        # can outlive a TERM to the master and would otherwise keep the port.
        if command -v pkill >/dev/null 2>&1; then
            pkill -TERM -P "$server_pid" 2>/dev/null || true
            pkill -TERM -f "$temporary_directory/router.php" 2>/dev/null || true
        fi
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
        server_pid=''
    fi

    if [ "$container_started" -eq 1 ]; then
        "$DOCKER" logs "$container_name" >"$temporary_directory/mariadb.log" 2>&1 || true
        "$DOCKER" rm --force --volumes "$container_name" >/dev/null 2>&1 || true
        if [ -n "$database_volume" ] && "$DOCKER" volume inspect "$database_volume" >/dev/null 2>&1; then
            "$DOCKER" volume rm "$database_volume" >/dev/null 2>&1 || true
        fi
        if "$DOCKER" container inspect "$container_name" >/dev/null 2>&1; then
            echo "Cleanup verification FAILED: container $container_name remains." >&2
            exit_code=1
        else
            echo "Cleanup verified: container $container_name is absent."
        fi
        if [ -n "$database_volume" ] && "$DOCKER" volume inspect "$database_volume" >/dev/null 2>&1; then
            echo "Cleanup verification FAILED: volume $database_volume remains." >&2
            exit_code=1
        elif [ -n "$database_volume" ]; then
            echo "Cleanup verified: volume $database_volume is absent."
        fi
    fi

    rm -rf -- "$temporary_directory"
    if [ -e "$temporary_directory" ]; then
        echo "Cleanup verification FAILED: temporary directory remains: $temporary_directory" >&2
        exit_code=1
    else
        echo 'Cleanup verified: temporary files are absent.'
    fi

    exit "$exit_code"
}
trap cleanup EXIT

if "$DOCKER" container inspect "$container_name" >/dev/null 2>&1; then
    echo "Refusing to touch pre-existing container $container_name." >&2
    exit 1
fi

echo "Starting disposable MariaDB container: $container_name ($MARIADB_IMAGE)"
"$DOCKER" run --detach \
    --name "$container_name" \
    --publish '127.0.0.1::3306' \
    --health-cmd='healthcheck.sh --connect --innodb_initialized' \
    --health-interval=2s \
    --health-timeout=2s \
    --health-retries=30 \
    --env "MARIADB_ROOT_PASSWORD=$database_root_password" \
    --env 'MARIADB_ROOT_HOST=%' \
    --env "MARIADB_DATABASE=$database_name" \
    --env "MARIADB_USER=$database_user" \
    --env "MARIADB_PASSWORD=$database_password" \
    "$MARIADB_IMAGE" >/dev/null
container_started=1
database_volume="$("$DOCKER" inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' "$container_name" | sed -n '1p')"

for _ in $(seq 1 90); do
    health="$("$DOCKER" inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_name")"
    if [ "$health" = 'healthy' ]; then
        break
    fi
    if [ "$health" = 'exited' ] || [ "$health" = 'dead' ]; then
        "$DOCKER" logs "$container_name" >&2 || true
        echo "MariaDB container entered state $health before becoming healthy." >&2
        exit 1
    fi
    sleep 1
done
if [ "${health:-}" != 'healthy' ]; then
    "$DOCKER" logs "$container_name" >&2 || true
    echo 'Timed out waiting for MariaDB health check.' >&2
    exit 1
fi

database_port="$("$DOCKER" port "$container_name" 3306/tcp | awk -F: 'NR == 1 { print $NF }')"
if [ -z "$database_port" ]; then
    echo 'Could not determine the disposable MariaDB host port.' >&2
    exit 1
fi

export APP_ENV=testing
export APP_DEBUG=false
export APP_URL='http://127.0.0.1'
export APP_KEY='base64:dGVzdC1vbmx5LXBocHVuaXQta2V5LTMyLWJ5dGUhISE='
export APP_CONFIG_CACHE="$temporary_directory/bootstrap/cache/config.php"
export APP_SERVICES_CACHE="$temporary_directory/bootstrap/cache/services.php"
export APP_PACKAGES_CACHE="$temporary_directory/bootstrap/cache/packages.php"
export APP_ROUTES_CACHE="$temporary_directory/bootstrap/cache/routes.php"
export APP_EVENTS_CACHE="$temporary_directory/bootstrap/cache/events.php"
export LARAVEL_STORAGE_PATH="$temporary_directory/storage"
export DB_CONNECTION=mysql
export DB_HOST=127.0.0.1
export DB_PORT="$database_port"
export DB_DATABASE="$database_name"
export DB_USERNAME="$database_user"
export DB_PASSWORD="$database_password"
unset DB_URL
export CACHE_STORE=database
export CACHE_PREFIX="org_sync_probe_$suffix"
export DB_CACHE_TABLE=cache
export DB_CACHE_LOCK_CONNECTION=mysql
export SESSION_DRIVER=array
export SCOUT_DRIVER=collection
export BCRYPT_ROUNDS=4
export QUEUE_CONNECTION=sync
export MAIL_MAILER=log
export STRIPE_SECRET='disposable-probe-placeholder'
export STRIPE_KEY='disposable-probe-placeholder'
export STRIPE_WEBHOOK_SECRET='disposable-probe-placeholder'
export JWT_SECRET='disposable-org-sync-deadlock-probe-jwt-secret'
export FILE_ENCRYPTION_KEY='base64:VFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFQ='
export ADMIN_EMAIL="org-sync-probe-$suffix@example.test"
export ADMIN_PASSWORD="$admin_password"
export LOG_CHANNEL=stderr
export LOG_LEVEL=debug

mkdir -p \
    "$temporary_directory/bootstrap/cache" \
    "$temporary_directory/storage/framework/cache/data" \
    "$temporary_directory/storage/framework/sessions" \
    "$temporary_directory/storage/logs" \
    "$temporary_directory/storage/app"

database_version="$("$DOCKER" exec --env "MYSQL_PWD=$database_root_password" "$container_name" \
    mariadb --batch --skip-column-names --user=root --execute='SELECT VERSION()')"
echo "MariaDB server version: $database_version"
echo "Probe database endpoint: 127.0.0.1:$database_port/$database_name (container only)"
echo "Scenarios: $SCENARIOS (each fires two concurrent syncs over two orgs)"

if ! (cd "$BACKEND_DIR" && php artisan migrate:fresh --seed --force) >"$temporary_directory/migrate.log" 2>&1; then
    cat "$temporary_directory/migrate.log" >&2
    exit 1
fi
echo 'Disposable database migrated and seeded: php artisan migrate:fresh --seed --force'

# Harness precondition: the measurement below is only meaningful if the
# endpoint really is the org membership sync. A renamed or removed route
# would otherwise be reported as a lock-order defect.
if ! (cd "$BACKEND_DIR" && php artisan route:list --path=management/orgs --json) >"$temporary_directory/routes.json" 2>&1; then
    cat "$temporary_directory/routes.json" >&2
    echo 'Could not enumerate the routes of the org management endpoints.' >&2
    exit 1
fi
if ! grep -q 'api.management.orgs.sync-users' "$temporary_directory/routes.json"; then
    echo 'Harness precondition failed: PUT /api/management/orgs/{id}/users is not routed to OrgController::syncUsers.' >&2
    exit 1
fi
echo 'Precondition verified: the org membership sync route is present.'

cat >"$temporary_directory/seed-fixture.php" <<'PHP'
<?php

require getenv('ORG_SYNC_PROBE_AUTOLOAD');
$app = require getenv('ORG_SYNC_PROBE_BOOTSTRAP');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;

BrandRegistry::set(Brand::B2B);

$adminRole = Role::firstOrCreate(['name' => UserRole::ADMIN->value]);

$scenarios = [];

for ($index = 0; $index < (int) getenv('ORG_SYNC_PROBE_SCENARIOS'); $index++) {
    $orgX = Org::factory()->create(['brand' => Brand::B2B, 'invoice_frequency' => 'immediate']);
    $orgY = Org::factory()->create(['brand' => Brand::B2B, 'invoice_frequency' => 'immediate']);

    // Actor A is a member of org Y, actor B is a member of org X. Both are
    // global admins, so each may sync the other org.
    $actorA = User::factory()->create(['brand' => Brand::B2B, 'org_id' => $orgY->id]);
    $actorA->roles()->attach($adminRole);
    $actorB = User::factory()->create(['brand' => Brand::B2B, 'org_id' => $orgX->id]);
    $actorB->roles()->attach($adminRole);

    $scenarios[] = [
        'index' => $index,
        'org_x' => $orgX->id,
        'org_y' => $orgY->id,
        'actor_a' => $actorA->id,
        'actor_b' => $actorB->id,
        'token_a' => auth('api')->login($actorA),
        'token_b' => auth('api')->login($actorB),
    ];
}

echo json_encode(['scenarios' => $scenarios], JSON_THROW_ON_ERROR);
PHP

export ORG_SYNC_PROBE_AUTOLOAD="$BACKEND_DIR/vendor/autoload.php"
export ORG_SYNC_PROBE_BOOTSTRAP="$BACKEND_DIR/bootstrap/app.php"
export ORG_SYNC_PROBE_SCENARIOS="$SCENARIOS"
php "$temporary_directory/seed-fixture.php" >"$temporary_directory/fixture.json"

php -r 'echo json_encode(json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR)["scenarios"], JSON_PRETTY_PRINT), PHP_EOL;' \
    "$temporary_directory/fixture.json" >"$temporary_directory/scenarios.txt"
echo 'Seeded scenarios:'
cat "$temporary_directory/scenarios.txt"

php -r '$socket = stream_socket_server("tcp://127.0.0.1:0", $errorCode, $errorMessage); if ($socket === false) { fwrite(STDERR, "$errorMessage\n"); exit(1); } echo parse_url(stream_socket_get_name($socket, false), PHP_URL_PORT), PHP_EOL; fclose($socket);' >"$temporary_directory/app-port"
app_port="$(cat "$temporary_directory/app-port")"

cat >"$temporary_directory/router.php" <<'PHP'
<?php

$publicDirectory = getenv('ORG_SYNC_PROBE_PUBLIC_DIR');
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if ($uri !== '/' && is_file($publicDirectory.$uri)) {
    return false;
}

require $publicDirectory.'/index.php';
PHP

# Run php -S without a wrapping subshell so $! is the server master itself and
# the worker kill below targets the right process tree.
(
    cd "$BACKEND_DIR" || exit 1
    exec env ORG_SYNC_PROBE_PUBLIC_DIR="$BACKEND_DIR/public" \
        PHP_CLI_SERVER_WORKERS=8 \
        php -S "127.0.0.1:$app_port" -t "$BACKEND_DIR/public" "$temporary_directory/router.php"
) >"$temporary_directory/application.log" 2>&1 &
server_pid=$!

for _ in $(seq 1 60); do
    if ! kill -0 "$server_pid" 2>/dev/null; then
        cat "$temporary_directory/application.log" >&2
        echo 'PHP built-in server exited before becoming ready.' >&2
        exit 1
    fi
    app_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:$app_port/api/settings/license-pricing" || true)"
    if [ "$app_status" = '200' ]; then
        break
    fi
    sleep 1
done
if [ "${app_status:-}" != '200' ]; then
    cat "$temporary_directory/application.log" >&2
    echo "PHP built-in server did not become ready (last HTTP status: ${app_status:-none})." >&2
    exit 1
fi

mariadb_query() {
    "$DOCKER" exec --env "MYSQL_PWD=$database_root_password" "$container_name" \
        mariadb --batch --skip-column-names --user=root --database="$database_name" --execute="$1"
}

# Only inspect lines written after startup so benign boot messages cannot be
# mistaken for lock contention.
"$DOCKER" logs "$container_name" >"$temporary_directory/mariadb-startup.log" 2>&1 || true
mariadb_log_lines_before="$(wc -l <"$temporary_directory/mariadb-startup.log" | tr -d ' ')"

status_value() {
    mariadb_query "SHOW GLOBAL STATUS LIKE '$1'" | awk 'NR == 1 { print $2 }'
}

row_lock_waits_before="$(status_value Innodb_row_lock_waits)"
deadlocks_before="$(status_value Innodb_deadlocks)"
timeouts_before="$(status_value Innodb_row_lock_timeouts)"

results_directory="$temporary_directory/results"
mkdir -p "$results_directory"

for scenario in $(seq 1 "$SCENARIOS"); do
    read -r org_x org_y token_a token_b < <(php -r '
        $scenarios = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR)["scenarios"];
        $scenario = $scenarios[(int) $argv[2] - 1];
        echo $scenario["org_x"], " ", $scenario["org_y"], " ", $scenario["token_a"], " ", $scenario["token_b"], PHP_EOL;
    ' "$temporary_directory/fixture.json" "$scenario")

    # Both clients wait behind the same gate and are released together, so the
    # two transactions overlap. Pre-spawning them keeps the stagger of a plain
    # loop from hiding the overlap.
    start_gate="$temporary_directory/start-gate-$scenario"
    rm -f "$start_gate"

    for side in a b; do
        if [ "$side" = 'a' ]; then
            client_token="$token_a"
            client_org="$org_x"
        else
            client_token="$token_b"
            client_org="$org_y"
        fi
        (
            while [ ! -e "$start_gate" ]; do sleep 0.01; done
            curl --connect-timeout 10 --max-time 60 --silent --show-error \
                --request PUT \
                --header "Authorization: Bearer $client_token" \
                --header 'Content-Type: application/json' \
                --data '{"user_ids":[]}' \
                --output /dev/null --write-out '%{http_code}\n' \
                "http://127.0.0.1:$app_port/api/management/orgs/$client_org/users" \
                >"$results_directory/$scenario-$side"
        ) &
        client_pids+=("$!")
    done

    sleep 0.3
    touch "$start_gate"
done

wait "${client_pids[@]}" 2>/dev/null || true
client_pids=()

row_lock_waits_after="$(status_value Innodb_row_lock_waits)"
deadlocks_after="$(status_value Innodb_deadlocks)"
timeouts_after="$(status_value Innodb_row_lock_timeouts)"

row_lock_waits_delta=$((row_lock_waits_after - row_lock_waits_before))
deadlock_delta=$((deadlocks_after - deadlocks_before))

echo 'HTTP status histogram (one pair of syncs per scenario):'
awk '{ count[$1]++ } END { for (status in count) printf "%s %d\n", status, count[status] }' "$results_directory"/* | sort -n
echo "InnoDB row-lock waits delta: $row_lock_waits_delta (positive control: at least $MIN_ROW_LOCK_WAITS required, $SCENARIOS scenarios)"
if [ -n "$deadlocks_before" ] && [ -n "$deadlocks_after" ]; then
    echo "InnoDB deadlocks delta: $deadlock_delta"
    deadlock_counter_available=1
else
    echo 'InnoDB deadlocks counter: unavailable from this MariaDB build'
    deadlock_counter_available=0
fi
if [ -n "$timeouts_before" ] && [ -n "$timeouts_after" ]; then
    echo "InnoDB row-lock timeouts delta: $((timeouts_after - timeouts_before))"
else
    echo 'InnoDB row-lock timeout counter: unavailable from this MariaDB build'
fi

# The syncs must also have had their effect: both actors are detached from
# the org they no longer belong to.
actor_ids="$(php -r '
    $scenarios = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR)["scenarios"];
    $ids = [];
    foreach ($scenarios as $scenario) {
        $ids[] = $scenario["actor_a"];
        $ids[] = $scenario["actor_b"];
    }
    echo implode(",", $ids);
' "$temporary_directory/fixture.json")"
remaining_actors="$(mariadb_query "SELECT COUNT(*) FROM users WHERE org_id IS NOT NULL AND id IN ($actor_ids)")"
echo "Probe actors still assigned to an org (expect 0): $remaining_actors"

stop_server() {
    if [ -n "$server_pid" ]; then
        if command -v pkill >/dev/null 2>&1; then
            pkill -TERM -P "$server_pid" 2>/dev/null || true
            pkill -TERM -f "$temporary_directory/router.php" 2>/dev/null || true
        fi
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
        server_pid=''
    fi
}
stop_server

server_workers_remaining=0
if command -v pgrep >/dev/null 2>&1 && pgrep -f "$temporary_directory/router.php" >/dev/null 2>&1; then
    pkill -KILL -f "$temporary_directory/router.php" 2>/dev/null || true
    sleep 0.5
    if pgrep -f "$temporary_directory/router.php" >/dev/null 2>&1; then
        server_workers_remaining=1
        echo "Cleanup verification FAILED: PHP server workers for $temporary_directory remain." >&2
    fi
fi

"$DOCKER" logs "$container_name" >"$temporary_directory/mariadb.log" 2>&1 || true
tail -n "+$((mariadb_log_lines_before + 1))" "$temporary_directory/mariadb.log" >"$temporary_directory/mariadb-runtime.log"
echo 'Application/server error scan (SQLSTATE, lock timeout, deadlock, InnoDB, 5xx exception):'
if grep -Ei 'SQLSTATE|lock wait timeout|deadlock|InnoDB: Error|\bERROR\b|\bexception\b' "$temporary_directory/application.log"; then
    application_errors=1
else
    echo 'none'
    application_errors=0
fi
echo 'MariaDB error scan (lock timeout, deadlock, InnoDB error; runtime lines only):'
if grep -Ei 'lock wait timeout|deadlock|InnoDB: Error|ERROR [0-9]+' "$temporary_directory/mariadb-runtime.log"; then
    mariadb_errors=1
else
    echo 'none'
    mariadb_errors=0
fi

unexpected_statuses="$(awk '$1 != 200 { count++ } END { print count + 0 }' "$results_directory"/*)"
observed_200="$(awk '$1 == 200 { count++ } END { print count + 0 }' "$results_directory"/*)"
expected_200="$((SCENARIOS * 2))"

# A deadlock is a measured defect, not a harness failure: it means the lock
# order still allows a cycle. It is evaluated FIRST because a deadlock aborts
# one transaction, so it also shows up as a non-200 response and would
# otherwise be mislabelled as a harness failure.
if [ "$deadlock_counter_available" -ne 1 ]; then
    echo 'Harness failure: this MariaDB build does not expose the InnoDB deadlocks counter, so a deadlock could not be detected.' >&2
    exit 1
fi

if [ "$deadlock_delta" -ne 0 ]; then
    echo "DEFECT: $deadlock_delta InnoDB deadlock(s) under the D-40 lock order. Two concurrent org syncs whose actors are members of each other's org still form a lock cycle." >&2
    exit 2
fi

# Positive control: without a row-lock wait the two syncs of a scenario never
# overlapped, so the run observed nothing and proves nothing.
if [ "$row_lock_waits_delta" -lt "$MIN_ROW_LOCK_WAITS" ]; then
    echo "INCONCLUSIVE: $row_lock_waits_delta InnoDB row-lock wait(s), at least $MIN_ROW_LOCK_WAITS required. The two syncs of a scenario did not contend, so this run proves nothing about the lock order." >&2
    exit 1
fi

if [ "$unexpected_statuses" -ne 0 ] || [ "$observed_200" -ne "$expected_200" ] \
    || [ "$remaining_actors" -ne 0 ] || [ "$application_errors" -ne 0 ] \
    || [ "$server_workers_remaining" -ne 0 ]; then
    echo 'Probe harness/availability failure; inspect the preceding raw measurements and logs.' >&2
    exit 1
fi

if [ "$mariadb_errors" -ne 0 ]; then
    echo 'MariaDB reported an error during the probe; inspect the runtime log above.' >&2
    exit 1
fi

echo "Lock-order verdict: no deadlock in $SCENARIOS scenarios ($expected_200 concurrent syncs, all HTTP 200, $row_lock_waits_delta InnoDB row-lock wait(s) recorded as the positive control)."
echo 'This is a sample, not a proof of absence: a run can only show that no cycle appeared in the contended scenarios it executed.'
echo 'Probe completed: the actor, the current members and the incoming members are locked in one ascending set, so two concurrent syncs serialise instead of deadlocking.'
exit 0
