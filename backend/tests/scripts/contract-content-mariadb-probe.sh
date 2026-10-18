#!/usr/bin/env bash
#
# Measure the SEC-20261018-12 contract-content limiter and heartbeat lock
# behaviour against a disposable MariaDB with real concurrent connections.
#
# Usage (from backend/):
#   DOCKER_BIN="$HOME/.rd/bin/docker" ./tests/scripts/contract-content-mariadb-probe.sh
#
# Overridable: MARIADB_IMAGE (default mariadb:11.4), REQUESTS (default 40),
# PHP_SERVER_WORKERS (default REQUESTS), DOCKER_BIN.
#
# Exit codes: 0 = strict limiter bound held, 1 = harness/usage/availability
# failure, 2 = limiter overshoot under simultaneous requests (a measured defect;
# the raw counts above are the evidence). The disposable container, volume and
# temp files are removed on every exit path.
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
REQUESTS="${REQUESTS:-40}"
SERVER_WORKERS="${PHP_SERVER_WORKERS:-$REQUESTS}"
# Keep in sync with RateLimiter::for('contract-content') in
# app/Providers/AppServiceProvider.php.
LIMIT_PER_MINUTE=30

case "$REQUESTS:$SERVER_WORKERS" in
    *[!0-9:]*|:*|*:) echo 'REQUESTS and PHP_SERVER_WORKERS must be positive integers.' >&2; exit 1 ;;
esac
if [ "$REQUESTS" -le "$LIMIT_PER_MINUTE" ]; then
    echo "REQUESTS must exceed the contract-content limiter budget ($LIMIT_PER_MINUTE)." >&2
    exit 1
fi
if [ "$REQUESTS" -lt 2 ] || [ "$SERVER_WORKERS" -lt "$REQUESTS" ]; then
    echo 'Use at least two simultaneous requests and at least as many PHP server workers.' >&2
    exit 1
fi

suffix="$(openssl rand -hex 6)"
container_name="contract-content-mariadb-$suffix"
database_name='contract_content_probe'
database_user='contract_probe_user'
database_root_password="$(openssl rand -hex 24)"
database_password="$(openssl rand -hex 24)"
admin_password="$(openssl rand -hex 24)"
temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/contract-content-mariadb.XXXXXX")"
container_started=0
database_volume=''
server_pid=''
monitor_pid=''
client_pids=()

cleanup() {
    exit_code=$?
    trap - EXIT

    if [ -n "$monitor_pid" ]; then
        kill "$monitor_pid" 2>/dev/null || true
        wait "$monitor_pid" 2>/dev/null || true
    fi

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
export CACHE_PREFIX="contract_probe_$suffix"
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
export JWT_SECRET='disposable-contract-content-probe-jwt-secret'
export FILE_ENCRYPTION_KEY='base64:VFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFQ='
export ADMIN_EMAIL="contract-probe-$suffix@example.test"
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
echo "Simultaneous requests: $REQUESTS; PHP server workers: $SERVER_WORKERS"
echo "Expected named limiter budget from AppServiceProvider: $LIMIT_PER_MINUTE per minute per personal token"
echo 'Cache store: database (same MariaDB; matches the production default in config/cache.php)'

if ! (cd "$BACKEND_DIR" && php artisan migrate:fresh --seed --force) >"$temporary_directory/migrate.log" 2>&1; then
    cat "$temporary_directory/migrate.log" >&2
    exit 1
fi
echo 'Disposable database migrated and seeded: php artisan migrate:fresh --seed --force'

# Harness precondition: the measurement below is only meaningful if the content
# route really carries the count-first middleware. A reverted route would
# otherwise be reported as a limiter defect.
if ! (cd "$BACKEND_DIR" && php artisan route:list --path=contracts/sign --json) >"$temporary_directory/routes.json" 2>&1; then
    cat "$temporary_directory/routes.json" >&2
    echo 'Could not enumerate the routes of the contract-content endpoint.' >&2
    exit 1
fi
if ! grep -q 'AtomicThrottleRequests:contract-content' "$temporary_directory/routes.json"; then
    echo 'Harness precondition failed: GET /api/contracts/sign/{personalToken} is not throttled by App\Http\Middleware\AtomicThrottleRequests:contract-content.' >&2
    exit 1
fi
echo 'Precondition verified: the content route carries App\Http\Middleware\AtomicThrottleRequests:contract-content.'

cat >"$temporary_directory/seed-fixture.php" <<'PHP'
<?php

require getenv('CONTRACT_PROBE_AUTOLOAD');
$app = require getenv('CONTRACT_PROBE_BOOTSTRAP');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\Brand;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Support\Str;

BrandRegistry::set(Brand::B2B);
$user = User::factory()->create(['brand' => Brand::B2B]);
$template = Contract::factory()->template()->create([
    'status' => 'active',
    'brand' => Brand::B2B,
    'items' => [],
    'discounts' => [],
    'terms_html' => '<p>Reusable template terms</p>',
    'available_roles' => ['Model'],
    'expires_at' => now()->addDays(30),
]);
$contract = Contract::factory()->active()->create([
    'status' => 'active',
    'brand' => Brand::B2B,
    'template_id' => $template->id,
    'items' => [[
        'type' => 'item',
        'description' => 'Digital contract probe item',
        'qty' => 1,
        'price' => 10000,
    ]],
    'discounts' => [],
    'terms_html' => '<p>Active gallery-less contract terms</p>',
    'available_roles' => ['Model'],
    'expires_at' => now()->addDays(20),
    'closes_at' => now()->addDays(10),
]);
$signer = ContractSigner::factory()->create([
    'contract_id' => $contract->id,
    'personal_token' => Str::random(64),
    'status' => 'joined',
    'roles' => ['Model'],
]);

echo json_encode([
    'user_id' => $user->id,
    'template_id' => $template->id,
    'contract_id' => $contract->id,
    'signer_id' => $signer->id,
    'personal_token' => $signer->personal_token,
], JSON_THROW_ON_ERROR);
PHP

export CONTRACT_PROBE_AUTOLOAD="$BACKEND_DIR/vendor/autoload.php"
export CONTRACT_PROBE_BOOTSTRAP="$BACKEND_DIR/bootstrap/app.php"
php "$temporary_directory/seed-fixture.php" >"$temporary_directory/fixture.json"
personal_token="$(php -r '$fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo $fixture["personal_token"];' "$temporary_directory/fixture.json")"
contract_signer_id="$(php -r '$fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo $fixture["signer_id"];' "$temporary_directory/fixture.json")"

php -r '$socket = stream_socket_server("tcp://127.0.0.1:0", $errorCode, $errorMessage); if ($socket === false) { fwrite(STDERR, "$errorMessage\n"); exit(1); } echo parse_url(stream_socket_get_name($socket, false), PHP_URL_PORT), PHP_EOL; fclose($socket);' >"$temporary_directory/app-port"
app_port="$(cat "$temporary_directory/app-port")"

cat >"$temporary_directory/router.php" <<'PHP'
<?php

$publicDirectory = getenv('CONTRACT_PROBE_PUBLIC_DIR');
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
    exec env CONTRACT_PROBE_PUBLIC_DIR="$BACKEND_DIR/public" \
        PHP_CLI_SERVER_WORKERS="$SERVER_WORKERS" \
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
max_used_connections_before="$(status_value Max_used_connections)"
connections_total_before="$(status_value Connections)"
results_directory="$temporary_directory/results"
mkdir -p "$results_directory"
touch "$temporary_directory/monitor.run"

(
    while [ -e "$temporary_directory/monitor.run" ]; do
        connection_counts="$(mariadb_query "SELECT COUNT(*), COALESCE(SUM(COMMAND <> 'Sleep'), 0) FROM information_schema.PROCESSLIST WHERE USER = '$database_user' AND DB = '$database_name'" 2>/dev/null || true)"
        if [ -n "$connection_counts" ]; then
            printf '%s\n' "$connection_counts" >>"$temporary_directory/connections.tsv"
        fi
        sleep 0.05
    done
) &
monitor_pid=$!

content_url="http://127.0.0.1:$app_port/api/contracts/sign/$personal_token"
start_gate="$temporary_directory/start-gate"

# Pre-spawn every client and hold it behind a file gate, then release all at
# once. Spawning 40 processes inside xargs would stagger their first byte
# enough to hide the burst (measured: only ~10 overlapping DB connections).
client_pids=()
for client in $(seq 1 "$REQUESTS"); do
    (
        while [ ! -e "$start_gate" ]; do sleep 0.01; done
        curl --connect-timeout 10 --max-time 90 --silent --show-error \
            --output /dev/null --write-out '%{http_code} %{time_total}\n' "$content_url" \
            >"$results_directory/$client"
    ) &
    client_pids+=("$!")
done
# Give every gated client time to reach its wait loop before the gate opens.
sleep 0.3

probe_started="$(php -r 'echo microtime(true);')"
touch "$start_gate"
wait "${client_pids[@]}" || true
probe_finished="$(php -r 'echo microtime(true);')"
rm -f "$temporary_directory/monitor.run"
wait "$monitor_pid"
monitor_pid=''

heartbeat_rows="$(mariadb_query "SELECT COUNT(*) FROM contract_audit_logs WHERE contract_signer_id = '$contract_signer_id' AND action = 'heartbeat'")"
row_lock_waits_after="$(status_value Innodb_row_lock_waits)"
deadlocks_after="$(status_value Innodb_deadlocks)"
timeouts_after="$(status_value Innodb_row_lock_timeouts)"
max_used_connections_after="$(status_value Max_used_connections)"
connections_total_after="$(status_value Connections)"
connections_peak="$(awk 'BEGIN { max = 0 } { if ($1 > max) max = $1 } END { print max }' "$temporary_directory/connections.tsv" 2>/dev/null || echo 0)"
active_connections_peak="$(awk 'BEGIN { max = 0 } { if ($2 > max) max = $2 } END { print max }' "$temporary_directory/connections.tsv" 2>/dev/null || echo 0)"

php -r 'printf("Burst wall time: %.3fs for %d simultaneously gated requests\n", $argv[3] - $argv[1], $argv[2]);' \
    "$probe_started" "$REQUESTS" "$probe_finished"
echo 'HTTP status histogram:'
awk '{ count[$1]++ } END { for (status in count) printf "%s %d\n", status, count[status] }' "$results_directory"/* | sort -n
awk '{ print $2 * 1000 }' "$results_directory"/* | sort -n >"$temporary_directory/latencies-ms"
awk '
    { values[NR] = $1; total += $1 }
    END {
        if (NR == 0) { print "Latency summary: no completed requests"; exit }
        p50 = int((NR * 50 + 99) / 100)
        p95 = int((NR * 95 + 99) / 100)
        printf "Latency ms: min=%.1f p50=%.1f p95=%.1f max=%.1f mean=%.1f n=%d\n", values[1], values[p50], values[p95], values[NR], total / NR, NR
    }
' "$temporary_directory/latencies-ms"
echo "Heartbeat audit rows: $heartbeat_rows (limiter budget: $LIMIT_PER_MINUTE/minute/token; requests: $REQUESTS)"
echo "MariaDB app connections observed: peak=$connections_peak; active SQL peak=$active_connections_peak"
echo "MariaDB Max_used_connections: before=$max_used_connections_before after=$max_used_connections_after (server-side peak since start)"
echo "MariaDB Connections total delta: $((connections_total_after - connections_total_before)) new client connections during the probe window"
echo "InnoDB row-lock waits delta: $((row_lock_waits_after - row_lock_waits_before))"
if [ -n "$deadlocks_before" ] && [ -n "$deadlocks_after" ]; then
    echo "InnoDB deadlocks delta: $((deadlocks_after - deadlocks_before))"
else
    echo 'InnoDB deadlocks counter: unavailable from this MariaDB build'
fi
if [ -n "$timeouts_before" ] && [ -n "$timeouts_after" ]; then
    echo "InnoDB row-lock timeouts delta: $((timeouts_after - timeouts_before))"
else
    echo 'InnoDB row-lock timeout counter: unavailable from this MariaDB build'
fi

if [ -f "$temporary_directory/connections.tsv" ]; then
    echo 'MariaDB connection monitor samples (app connections, active SQL):'
    awk 'BEGIN { max = 0; active = 0 } { print; if ($1 > max) max = $1; if ($2 > active) active = $2 } END { printf "sampled max=%d active-max=%d\n", max, active }' "$temporary_directory/connections.tsv"
fi

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
echo "MariaDB runtime log lines during probe: $(wc -l <"$temporary_directory/mariadb-runtime.log" | tr -d ' ')"
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

unexpected_statuses="$(awk '$1 != 200 && $1 != 429 { count++ } END { print count + 0 }' "$results_directory"/*)"
observed_200="$(awk '$1 == 200 { count++ } END { print count + 0 }' "$results_directory"/*)"
observed_429="$(awk '$1 == 429 { count++ } END { print count + 0 }' "$results_directory"/*)"

if [ "$unexpected_statuses" -ne 0 ] || [ "$observed_200" -eq 0 ] || [ "$heartbeat_rows" -eq 0 ] \
    || [ "$active_connections_peak" -lt 2 ] || [ "$application_errors" -ne 0 ] || [ "$mariadb_errors" -ne 0 ] \
    || [ "$server_workers_remaining" -ne 0 ]; then
    echo 'Probe harness/availability failure; inspect the preceding raw measurements and logs.' >&2
    exit 1
fi

# A defect wins over the heartbeat/200 mismatch check below: a run that
# overshoots the budget *and* has a mismatch is still a limiter defect, not a
# harness failure.
if [ "$observed_200" -gt "$LIMIT_PER_MINUTE" ] || [ "$heartbeat_rows" -gt "$LIMIT_PER_MINUTE" ]; then
    echo "DEFECT: strict contract-content budget exceeded under simultaneous requests: limit=$LIMIT_PER_MINUTE/minute, HTTP 200=$observed_200, heartbeats=$heartbeat_rows, excess=$((heartbeat_rows - LIMIT_PER_MINUTE))." >&2
    echo 'Cause: the content route is throttled by App\Http\Middleware\AtomicThrottleRequests, which counts before it decides. An excess therefore means that middleware is not in the route chain, or its counter was rolled back — a blind write on the counter key, or a cache store whose increment() is not atomic across processes.' >&2
    if [ "$heartbeat_rows" -ne "$observed_200" ]; then
        echo "Probe anomaly (reported alongside the defect): HTTP 200=$observed_200 but heartbeat rows=$heartbeat_rows (expected one heartbeat per successful content read)." >&2
    fi
    exit 2
fi

if [ "$heartbeat_rows" -ne "$observed_200" ]; then
    echo "Probe anomaly: HTTP 200=$observed_200 but heartbeat rows=$heartbeat_rows (expected one heartbeat per successful content read)." >&2
    exit 1
fi

echo "Limiter verdict: strict bound held ($heartbeat_rows heartbeat rows, limit $LIMIT_PER_MINUTE/minute, $observed_429 requests throttled)."
echo "Probe completed: $observed_200 HTTP 200, $observed_429 HTTP 429, $heartbeat_rows heartbeat rows, at least two simultaneously active MariaDB app connections, no matching database/application errors."
exit 0
