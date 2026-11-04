#!/usr/bin/env bash
# Measure the D-40 org-sync lock order against a disposable PostgreSQL server.
# The paired syncs must contend (positive control) but must never deadlock.
# Usage: DOCKER_BIN=... ./tests/scripts/org-sync-deadlock-postgres-probe.sh
# Overrides: POSTGRES_IMAGE (postgres:15), SCENARIOS (12),
# MIN_LOCK_WAIT_SAMPLES (1), SECOND_ACTION (sync|destroy), DOCKER_BIN.
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

POSTGRES_IMAGE="${POSTGRES_IMAGE:-postgres:15}"
SCENARIOS="${SCENARIOS:-12}"
MIN_LOCK_WAIT_SAMPLES="${MIN_LOCK_WAIT_SAMPLES:-1}"
SECOND_ACTION="${SECOND_ACTION:-sync}"
case "$SECOND_ACTION" in sync|destroy) ;; *) echo 'SECOND_ACTION must be sync or destroy.' >&2; exit 1 ;; esac
case "$SCENARIOS" in ''|*[!0-9]*) echo 'SCENARIOS must be a positive integer.' >&2; exit 1 ;; esac
if [ "$SCENARIOS" -lt 1 ]; then echo 'SCENARIOS must be positive.' >&2; exit 1; fi
case "$MIN_LOCK_WAIT_SAMPLES" in ''|*[!0-9]*) echo 'MIN_LOCK_WAIT_SAMPLES must be non-negative.' >&2; exit 1 ;; esac

suffix="$(openssl rand -hex 6)"
container_name="org-sync-deadlock-postgres-$suffix"
database_name=org_sync_deadlock_probe
database_user=org_sync_probe_user
database_password="$(openssl rand -hex 24)"
admin_password="$(openssl rand -hex 24)"
temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/org-sync-deadlock-postgres.XXXXXX")"
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
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [ "$container_started" -eq 1 ]; then
        "$DOCKER" rm --force --volumes "$container_name" >/dev/null 2>&1 || true
        if [ -n "$database_volume" ] && "$DOCKER" volume inspect "$database_volume" >/dev/null 2>&1; then "$DOCKER" volume rm "$database_volume" >/dev/null 2>&1 || true; fi
        if "$DOCKER" container inspect "$container_name" >/dev/null 2>&1; then echo "Cleanup failed: $container_name remains." >&2; exit_code=1; else echo "Cleanup verified: $container_name is absent."; fi
    fi
    rm -rf -- "$temporary_directory"
    if [ -e "$temporary_directory" ]; then echo "Cleanup failed: $temporary_directory remains." >&2; exit_code=1; fi
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
export CACHE_STORE=database CACHE_PREFIX="org_sync_probe_$suffix" DB_CACHE_TABLE=cache DB_CACHE_LOCK_CONNECTION=pgsql
export SESSION_DRIVER=array SCOUT_DRIVER=collection BCRYPT_ROUNDS=4 QUEUE_CONNECTION=sync MAIL_MAILER=log
export STRIPE_SECRET=probe-placeholder STRIPE_KEY=probe-placeholder STRIPE_WEBHOOK_SECRET=probe-placeholder
export JWT_SECRET=disposable-org-sync-postgres-probe-jwt-secret FILE_ENCRYPTION_KEY='base64:VFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFQ='
export ADMIN_EMAIL="org-sync-probe-$suffix@example.test" ADMIN_PASSWORD="$admin_password" LOG_CHANNEL=stderr LOG_LEVEL=debug
mkdir -p "$temporary_directory/bootstrap/cache" "$temporary_directory/storage/framework/cache/data" \
    "$temporary_directory/storage/framework/sessions" "$temporary_directory/storage/logs" "$temporary_directory/storage/app"

postgres_query() {
    "$DOCKER" exec --env "PGPASSWORD=$database_password" "$container_name" \
        psql --no-psqlrc --set ON_ERROR_STOP=1 --username="$database_user" --dbname="$database_name" --tuples-only --no-align --command="$1"
}
echo "PostgreSQL server: $(postgres_query 'SELECT version()')"
echo "Scenarios: $SCENARIOS; second action: $SECOND_ACTION"
if ! (cd "$BACKEND_DIR" && php artisan migrate:fresh --seed --force) >"$temporary_directory/migrate.log" 2>&1; then cat "$temporary_directory/migrate.log" >&2; exit 1; fi
echo 'Disposable database migrated and seeded.'
if ! (cd "$BACKEND_DIR" && php artisan route:list --path=management/orgs --json) >"$temporary_directory/routes.json" 2>&1; then cat "$temporary_directory/routes.json" >&2; exit 1; fi
if ! grep -q 'api.management.orgs.sync-users' "$temporary_directory/routes.json"; then echo 'Org sync route is absent.' >&2; exit 1; fi
if [ "$SECOND_ACTION" = destroy ] && ! grep -q 'api.management.orgs.destroy' "$temporary_directory/routes.json"; then echo 'Org destroy route is absent.' >&2; exit 1; fi

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
$defaultRole = Role::firstOrCreate(['name' => UserRole::CLIENT->value]);
$scenarios = [];
for ($i = 0; $i < (int) getenv('ORG_SYNC_PROBE_SCENARIOS'); $i++) {
    $orgX = Org::factory()->create(['brand' => Brand::B2B, 'invoice_frequency' => 'immediate', 'default_role_id' => $defaultRole->id]);
    $orgY = Org::factory()->create(['brand' => Brand::B2B, 'invoice_frequency' => 'immediate', 'default_role_id' => $defaultRole->id]);
    $actorA = User::factory()->create(['brand' => Brand::B2B, 'org_id' => $orgY->id]);
    $actorA->roles()->attach([$adminRole->id, $defaultRole->id]);
    $actorB = User::factory()->create(['brand' => Brand::B2B, 'org_id' => $orgX->id]);
    $actorB->roles()->attach([$adminRole->id, $defaultRole->id]);
    $scenarios[] = ['org_x' => $orgX->id, 'org_y' => $orgY->id, 'actor_a' => $actorA->id, 'actor_b' => $actorB->id,
        'token_a' => auth('api')->login($actorA), 'token_b' => auth('api')->login($actorB)];
}
echo json_encode(['scenarios' => $scenarios], JSON_THROW_ON_ERROR);
PHP
export ORG_SYNC_PROBE_AUTOLOAD="$BACKEND_DIR/vendor/autoload.php" ORG_SYNC_PROBE_BOOTSTRAP="$BACKEND_DIR/bootstrap/app.php" ORG_SYNC_PROBE_SCENARIOS="$SCENARIOS"
php "$temporary_directory/seed-fixture.php" >"$temporary_directory/fixture.json"
php -r 'echo json_encode(json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR)["scenarios"],JSON_PRETTY_PRINT),PHP_EOL;' "$temporary_directory/fixture.json"

php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); if (!$s) { fwrite(STDERR,"$m\n"); exit(1); } echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT),PHP_EOL; fclose($s);' >"$temporary_directory/app-port"
app_port="$(cat "$temporary_directory/app-port")"
cat >"$temporary_directory/router.php" <<'PHP'
<?php
$publicDirectory = getenv('ORG_SYNC_PROBE_PUBLIC_DIR');
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if ($uri !== '/' && is_file($publicDirectory.$uri)) return false;
require $publicDirectory.'/index.php';
PHP
(
    cd "$BACKEND_DIR" || exit 1
    exec env ORG_SYNC_PROBE_PUBLIC_DIR="$BACKEND_DIR/public" PHP_CLI_SERVER_WORKERS=8 php -S "127.0.0.1:$app_port" -t "$BACKEND_DIR/public" "$temporary_directory/router.php"
) >"$temporary_directory/application.log" 2>&1 &
server_pid=$!
for _ in $(seq 1 60); do
    app_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:$app_port/api/settings/license-pricing" || true)"
    [ "$app_status" = 200 ] && break
    sleep 1
done
if [ "${app_status:-}" != 200 ]; then cat "$temporary_directory/application.log" >&2; echo 'PHP server did not become ready.' >&2; exit 1; fi

deadlock_count() { postgres_query "SELECT COALESCE(deadlocks,0) FROM pg_stat_database WHERE datname='$database_name'"; }
deadlocks_before="$(deadlock_count)"
touch "$temporary_directory/monitor.run"
(
    while [ -e "$temporary_directory/monitor.run" ]; do
        postgres_query "SELECT COUNT(*) FROM pg_stat_activity WHERE datname='$database_name' AND state='active' AND wait_event_type='Lock'" >>"$temporary_directory/lock-waits" 2>/dev/null || true
        sleep 0.01
    done
) &
monitor_pid=$!
results_directory="$temporary_directory/results"
mkdir -p "$results_directory"
for scenario in $(seq 1 "$SCENARIOS"); do
    read -r org_x org_y token_a token_b < <(php -r '$s=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR)["scenarios"][(int)$argv[2]-1]; echo $s["org_x"]," ",$s["org_y"]," ",$s["token_a"]," ",$s["token_b"],PHP_EOL;' "$temporary_directory/fixture.json" "$scenario")
    gate="$temporary_directory/gate-$scenario"
    for side in a b; do
        if [ "$side" = a ]; then token="$token_a"; org="$org_x"; else token="$token_b"; org="$org_y"; fi
        if [ "$SECOND_ACTION" = destroy ] && [ "$side" = b ]; then method=DELETE; url="http://127.0.0.1:$app_port/api/management/orgs/$org"; data=''
        else method=PUT; url="http://127.0.0.1:$app_port/api/management/orgs/$org/users"; data='{"user_ids":[]}'; fi
        (while [ ! -e "$gate" ]; do sleep 0.01; done; curl --connect-timeout 10 --max-time 60 --silent --show-error --request "$method" --header "Authorization: Bearer $token" --header 'Content-Type: application/json' --data "$data" --output /dev/null --write-out '%{http_code}\n' "$url" >"$results_directory/$scenario-$side") &
        client_pids+=("$!")
    done
    sleep 0.3
    touch "$gate"
done
wait "${client_pids[@]}" 2>/dev/null || true
client_pids=()
rm -f "$temporary_directory/monitor.run"
wait "$monitor_pid" 2>/dev/null || true
monitor_pid=''
deadlocks_after="$(deadlock_count)"
wait_samples="$(awk '$1>0 {n++} END {print n+0}' "$temporary_directory/lock-waits")"
deadlock_delta=$((deadlocks_after-deadlocks_before))
echo 'HTTP status histogram:'
awk '{count[$1]++} END {for (s in count) printf "%s %d\n",s,count[s]}' "$results_directory"/* | sort -n
echo "PostgreSQL lock-wait samples: $wait_samples (minimum required: $MIN_LOCK_WAIT_SAMPLES)"
echo "PostgreSQL deadlocks delta: $deadlock_delta"
actor_ids="$(php -r '$s=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR)["scenarios"]; $a=[]; foreach($s as $r){$a[]=$r["actor_a"];$a[]=$r["actor_b"];} echo implode("','",$a);' "$temporary_directory/fixture.json")"
remaining_actors="$(postgres_query "SELECT COUNT(*) FROM users WHERE org_id IS NOT NULL AND id::text IN ('$actor_ids')")"
echo "Probe actors still assigned to an org (expect 0): $remaining_actors"
if [ -n "$server_pid" ]; then
    if command -v pkill >/dev/null 2>&1; then pkill -TERM -f "$temporary_directory/router.php" 2>/dev/null || true; fi
    kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; server_pid=''
fi
echo 'Application error scan:'
if grep -Ei 'SQLSTATE|lock timeout|deadlock|\bERROR\b|\bexception\b' "$temporary_directory/application.log"; then errors=1; else echo none; errors=0; fi
unexpected="$(awk '$1!=200 {n++} END {print n+0}' "$results_directory"/*)"
observed="$(awk '$1==200 {n++} END {print n+0}' "$results_directory"/*)"
expected=$((SCENARIOS*2))
if [ "$deadlock_delta" -ne 0 ]; then echo "DEFECT: PostgreSQL recorded $deadlock_delta deadlock(s)." >&2; exit 2; fi
if [ "$wait_samples" -lt "$MIN_LOCK_WAIT_SAMPLES" ]; then echo "INCONCLUSIVE: only $wait_samples PostgreSQL lock-wait samples." >&2; exit 1; fi
if [ "$unexpected" -ne 0 ] || [ "$observed" -ne "$expected" ] || [ "$remaining_actors" -ne 0 ] || [ "$errors" -ne 0 ]; then echo 'Probe harness/availability failure.' >&2; exit 1; fi
echo "Lock-order verdict: no PostgreSQL deadlock across $SCENARIOS contended scenarios ($expected HTTP 200 actions)."
