#!/usr/bin/env bash
# Measure contract-content limiter behaviour under simultaneous PostgreSQL
# connections. Usage: DOCKER_BIN=... ./tests/scripts/contract-content-postgres-probe.sh
# Overrides: POSTGRES_IMAGE (postgres:16), REQUESTS (40), PHP_SERVER_WORKERS,
# DOCKER_BIN. Exit codes: 0 bound held, 1 harness failure, 2 measured overshoot.
set -Eeuo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"
if [ -n "${DOCKER_BIN:-}" ]; then DOCKER="$DOCKER_BIN"
elif command -v docker >/dev/null 2>&1; then DOCKER="$(command -v docker)"
elif [ -x "$HOME/.rd/bin/docker" ]; then DOCKER="$HOME/.rd/bin/docker"
else echo 'Docker CLI not found (set DOCKER_BIN if needed).' >&2; exit 1; fi
PATH="$(dirname "$DOCKER"):$PATH"
export PATH
"$REPO_DIR/scripts/check-php-version.sh"

POSTGRES_IMAGE="${POSTGRES_IMAGE:-postgres:16}"
REQUESTS="${REQUESTS:-40}"
SERVER_WORKERS="${PHP_SERVER_WORKERS:-$REQUESTS}"
LIMIT_PER_MINUTE=30
case "$REQUESTS:$SERVER_WORKERS" in *[!0-9:]*|:*|*:) echo 'REQUESTS and PHP_SERVER_WORKERS must be positive integers.' >&2; exit 1 ;; esac
if [ "$REQUESTS" -le "$LIMIT_PER_MINUTE" ]; then echo "REQUESTS must exceed the limiter budget ($LIMIT_PER_MINUTE)." >&2; exit 1; fi
if [ "$REQUESTS" -lt 2 ] || [ "$SERVER_WORKERS" -lt "$REQUESTS" ]; then echo 'Use at least two requests and at least as many PHP workers.' >&2; exit 1; fi

suffix="$(openssl rand -hex 6)"
container_name="contract-content-postgres-$suffix"
database_name=contract_content_probe
database_user=contract_probe_user
database_password="$(openssl rand -hex 24)"
admin_password="$(openssl rand -hex 24)"
temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/contract-content-postgres.XXXXXX")"
container_started=0
database_volume=''
server_pid=''
monitor_pid=''
client_pids=()

cleanup() {
    exit_code=$?
    trap - EXIT
    if [ -n "$monitor_pid" ]; then kill "$monitor_pid" 2>/dev/null || true; wait "$monitor_pid" 2>/dev/null || true; fi
    for pid in "${client_pids[@]}"; do kill "$pid" 2>/dev/null || true; done
    if [ -n "$server_pid" ]; then
        if command -v pkill >/dev/null 2>&1; then pkill -TERM -f "$temporary_directory/router.php" 2>/dev/null || true; fi
        kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true
    fi
    if [ "$container_started" -eq 1 ]; then
        "$DOCKER" rm --force --volumes "$container_name" >/dev/null 2>&1 || true
        if [ -n "$database_volume" ] && "$DOCKER" volume inspect "$database_volume" >/dev/null 2>&1; then "$DOCKER" volume rm "$database_volume" >/dev/null 2>&1 || true; fi
        if "$DOCKER" container inspect "$container_name" >/dev/null 2>&1; then echo "Cleanup failed: $container_name remains." >&2; exit_code=1; else echo "Cleanup verified: $container_name is absent."; fi
    fi
    rm -rf -- "$temporary_directory"
    if [ -e "$temporary_directory" ]; then echo "Cleanup failed: $temporary_directory remains." >&2; exit_code=1; else echo 'Cleanup verified: temporary files are absent.'; fi
    exit "$exit_code"
}
trap cleanup EXIT

if "$DOCKER" container inspect "$container_name" >/dev/null 2>&1; then echo "Refusing to touch pre-existing container $container_name." >&2; exit 1; fi
echo "Starting disposable PostgreSQL container: $container_name ($POSTGRES_IMAGE)"
"$DOCKER" run --detach --name "$container_name" --publish '127.0.0.1::5432' \
    --health-cmd="pg_isready -U $database_user -d $database_name" --health-interval=2s --health-timeout=2s --health-retries=30 \
    --env "POSTGRES_USER=$database_user" --env "POSTGRES_PASSWORD=$database_password" --env "POSTGRES_DB=$database_name" \
    "$POSTGRES_IMAGE" >/dev/null
container_started=1
database_volume="$("$DOCKER" inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' "$container_name" | sed -n '1p')"
for _ in $(seq 1 90); do
    health="$("$DOCKER" inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_name")"
    [ "$health" = healthy ] && break
    if [ "$health" = exited ] || [ "$health" = dead ]; then "$DOCKER" logs "$container_name" >&2 || true; exit 1; fi
    sleep 1
done
if [ "${health:-}" != healthy ]; then "$DOCKER" logs "$container_name" >&2 || true; echo 'Timed out waiting for PostgreSQL.' >&2; exit 1; fi
database_port="$("$DOCKER" port "$container_name" 5432/tcp | awk -F: 'NR == 1 { print $NF }')"
if [ -z "$database_port" ]; then echo 'Could not determine PostgreSQL host port.' >&2; exit 1; fi

export APP_ENV=testing APP_DEBUG=false APP_URL='http://127.0.0.1'
export APP_KEY='base64:dGVzdC1vbmx5LXBocHVuaXQta2V5LTMyLWJ5dGUhISE='
export APP_CONFIG_CACHE="$temporary_directory/bootstrap/cache/config.php" APP_SERVICES_CACHE="$temporary_directory/bootstrap/cache/services.php"
export APP_PACKAGES_CACHE="$temporary_directory/bootstrap/cache/packages.php" APP_ROUTES_CACHE="$temporary_directory/bootstrap/cache/routes.php"
export APP_EVENTS_CACHE="$temporary_directory/bootstrap/cache/events.php" LARAVEL_STORAGE_PATH="$temporary_directory/storage"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="$database_port" DB_DATABASE="$database_name" DB_USERNAME="$database_user" DB_PASSWORD="$database_password"
unset DB_URL
export CACHE_STORE=database CACHE_PREFIX="contract_probe_$suffix" DB_CACHE_TABLE=cache DB_CACHE_LOCK_CONNECTION=pgsql
export SESSION_DRIVER=array SCOUT_DRIVER=collection BCRYPT_ROUNDS=4 QUEUE_CONNECTION=sync MAIL_MAILER=log
export STRIPE_SECRET=probe-placeholder STRIPE_KEY=probe-placeholder STRIPE_WEBHOOK_SECRET=probe-placeholder
export JWT_SECRET=disposable-contract-content-postgres-probe-jwt-secret FILE_ENCRYPTION_KEY='base64:VFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFQ='
export ADMIN_EMAIL="contract-probe-$suffix@example.test" ADMIN_PASSWORD="$admin_password" LOG_CHANNEL=stderr LOG_LEVEL=debug
mkdir -p "$temporary_directory/bootstrap/cache" "$temporary_directory/storage/framework/cache/data" \
    "$temporary_directory/storage/framework/sessions" "$temporary_directory/storage/logs" "$temporary_directory/storage/app"

postgres_query() {
    "$DOCKER" exec --env "PGPASSWORD=$database_password" "$container_name" \
        psql --no-psqlrc --set ON_ERROR_STOP=1 --username="$database_user" --dbname="$database_name" --tuples-only --no-align --command="$1"
}
echo "PostgreSQL server: $(postgres_query 'SELECT version()')"
echo "Simultaneous requests: $REQUESTS; PHP workers: $SERVER_WORKERS; limiter: $LIMIT_PER_MINUTE/minute"
if ! (cd "$BACKEND_DIR" && php artisan migrate:fresh --seed --force) >"$temporary_directory/migrate.log" 2>&1; then cat "$temporary_directory/migrate.log" >&2; exit 1; fi
if ! (cd "$BACKEND_DIR" && php artisan route:list --path=contracts/sign --json) >"$temporary_directory/routes.json" 2>&1; then cat "$temporary_directory/routes.json" >&2; exit 1; fi
if ! grep -q 'AtomicThrottleRequests:contract-content' "$temporary_directory/routes.json"; then echo 'Contract content route lacks the count-first limiter.' >&2; exit 1; fi
echo 'Disposable database migrated; limiter route precondition verified.'

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
$template = Contract::factory()->template()->create(['status' => 'active', 'brand' => Brand::B2B, 'items' => [], 'discounts' => [],
    'terms_html' => '<p>Reusable template terms</p>', 'available_roles' => ['Model'], 'expires_at' => now()->addDays(30)]);
$contract = Contract::factory()->active()->create(['status' => 'active', 'brand' => Brand::B2B, 'template_id' => $template->id,
    'items' => [['type' => 'item', 'description' => 'Digital contract probe item', 'qty' => 1, 'price' => 10000]],
    'discounts' => [], 'terms_html' => '<p>Active gallery-less contract terms</p>', 'available_roles' => ['Model'],
    'expires_at' => now()->addDays(20), 'closes_at' => now()->addDays(10)]);
$signer = ContractSigner::factory()->create(['contract_id' => $contract->id, 'personal_token' => Str::random(64), 'status' => 'joined', 'roles' => ['Model']]);
echo json_encode(['signer_id' => $signer->id, 'personal_token' => $signer->personal_token], JSON_THROW_ON_ERROR);
PHP
export CONTRACT_PROBE_AUTOLOAD="$BACKEND_DIR/vendor/autoload.php" CONTRACT_PROBE_BOOTSTRAP="$BACKEND_DIR/bootstrap/app.php"
php "$temporary_directory/seed-fixture.php" >"$temporary_directory/fixture.json"
personal_token="$(php -r '$f=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $f["personal_token"];' "$temporary_directory/fixture.json")"
signer_id="$(php -r '$f=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $f["signer_id"];' "$temporary_directory/fixture.json")"
php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); if (!$s) { fwrite(STDERR,"$m\n"); exit(1); } echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT),PHP_EOL; fclose($s);' >"$temporary_directory/app-port"
app_port="$(cat "$temporary_directory/app-port")"
cat >"$temporary_directory/router.php" <<'PHP'
<?php
$publicDirectory = getenv('CONTRACT_PROBE_PUBLIC_DIR');
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if ($uri !== '/' && is_file($publicDirectory.$uri)) return false;
require $publicDirectory.'/index.php';
PHP
(
    cd "$BACKEND_DIR" || exit 1
    exec env CONTRACT_PROBE_PUBLIC_DIR="$BACKEND_DIR/public" PHP_CLI_SERVER_WORKERS="$SERVER_WORKERS" php -S "127.0.0.1:$app_port" -t "$BACKEND_DIR/public" "$temporary_directory/router.php"
) >"$temporary_directory/application.log" 2>&1 &
server_pid=$!
for _ in $(seq 1 60); do
    app_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:$app_port/api/settings/license-pricing" || true)"
    [ "$app_status" = 200 ] && break
    sleep 1
done
if [ "${app_status:-}" != 200 ]; then cat "$temporary_directory/application.log" >&2; echo 'PHP server did not become ready.' >&2; exit 1; fi

results="$temporary_directory/results"
mkdir -p "$results"
touch "$temporary_directory/monitor.run"
(
    while [ -e "$temporary_directory/monitor.run" ]; do
        postgres_query "SELECT COUNT(*) FILTER (WHERE pid<>pg_backend_pid()), COUNT(*) FILTER (WHERE pid<>pg_backend_pid() AND state='active') FROM pg_stat_activity WHERE datname='$database_name'" \
            >>"$temporary_directory/connections.tsv" 2>/dev/null || true
        sleep 0.05
    done
) &
monitor_pid=$!
content_url="http://127.0.0.1:$app_port/api/contracts/sign/$personal_token"
gate="$temporary_directory/start-gate"
for client in $(seq 1 "$REQUESTS"); do
    (while [ ! -e "$gate" ]; do sleep 0.01; done; curl --connect-timeout 10 --max-time 90 --silent --show-error \
        --output /dev/null --write-out '%{http_code} %{time_total}\n' "$content_url" >"$results/$client") &
    client_pids+=("$!")
done
sleep 0.3
started="$(php -r 'echo microtime(true);')"
touch "$gate"
wait "${client_pids[@]}" || true
client_pids=()
finished="$(php -r 'echo microtime(true);')"
rm -f "$temporary_directory/monitor.run"
wait "$monitor_pid" 2>/dev/null || true
monitor_pid=''
heartbeat_rows="$(postgres_query "SELECT COUNT(*) FROM contract_audit_logs WHERE contract_signer_id='$signer_id' AND action='heartbeat'")"
connections_peak="$(awk 'BEGIN{m=0} $1>m{m=$1} END{print m}' "$temporary_directory/connections.tsv")"
active_peak="$(awk 'BEGIN{m=0} $2>m{m=$2} END{print m}' "$temporary_directory/connections.tsv")"
php -r 'printf("Burst wall time: %.3fs for %d simultaneously gated requests\n",$argv[2]-$argv[1],$argv[3]);' "$started" "$finished" "$REQUESTS"
echo 'HTTP status histogram:'
awk '{count[$1]++} END{for(s in count)printf "%s %d\n",s,count[s]}' "$results"/* | sort -n
echo "Heartbeat rows: $heartbeat_rows (limit $LIMIT_PER_MINUTE/minute)"
echo "PostgreSQL app connections: peak=$connections_peak active-SQL-peak=$active_peak"
if [ -n "$server_pid" ]; then
    if command -v pkill >/dev/null 2>&1; then pkill -TERM -f "$temporary_directory/router.php" 2>/dev/null || true; fi
    kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; server_pid=''
fi
echo 'Application error scan:'
if grep -Ei 'SQLSTATE|lock timeout|deadlock|\bERROR\b|\bexception\b' "$temporary_directory/application.log"; then errors=1; else echo none; errors=0; fi
unexpected="$(awk '$1!=200 && $1!=429{n++} END{print n+0}' "$results"/*)"
ok="$(awk '$1==200{n++} END{print n+0}' "$results"/*)"
throttled="$(awk '$1==429{n++} END{print n+0}' "$results"/*)"
if [ "$unexpected" -ne 0 ] || [ "$ok" -eq 0 ] || [ "$heartbeat_rows" -eq 0 ] || [ "$active_peak" -lt 2 ] || [ "$errors" -ne 0 ]; then echo 'Probe harness/availability failure.' >&2; exit 1; fi
if [ "$ok" -gt "$LIMIT_PER_MINUTE" ] || [ "$heartbeat_rows" -gt "$LIMIT_PER_MINUTE" ]; then echo "DEFECT: limiter exceeded its bound: HTTP 200=$ok, heartbeats=$heartbeat_rows." >&2; exit 2; fi
if [ "$heartbeat_rows" -ne "$ok" ]; then echo "Probe anomaly: HTTP 200=$ok but heartbeat rows=$heartbeat_rows." >&2; exit 1; fi
echo "Limiter verdict: strict bound held ($heartbeat_rows heartbeats, $throttled requests throttled)."
