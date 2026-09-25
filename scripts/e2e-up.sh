#!/usr/bin/env bash
# ==========================================================================
# e2e-up.sh — Isolierter lokaler E2E-Backend (eigene SQLite-DB + Port 8001)
# --------------------------------------------------------------------------
# Startet einen SEPARATEN Backend-Prozess für lokale Playwright-E2E-Tests,
# damit die lokale Dev-Instanz (portal.test / database/database.sqlite)
# von E2E-Tests unberührt bleibt.
#
# Ablauf (idempotent):
#   1. Test-Services starten  (docker-compose.test.yml: Meili 7701)
#   2. backend/.env.e2e generieren (aus backend/.env, Secrets werden übernommen)
#   3. Eigene SQLite-DB anlegen + migrieren + seeden (env=e2e)
#   4. Deterministische E2E-Location-Fixtures laden + Scout-Index aktualisieren
#   5. php artisan serve auf http://127.0.0.1:8001 (env=e2e, --no-reload)
#
# Mail: natives Homebrew-Mailpit (127.0.0.1:1025 SMTP / 8025 API), KEIN Container.
#
# Frontend (separat, Proxy auf den E2E-Backend):
#   VITE_API_PROXY=http://127.0.0.1:8001 pnpm dev
#
# E2E-Tests (Mailpit-API auf nativer Instanz 8025 — Default des MailpitHelper):
#   pnpm test:e2e
# ==========================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"
E2E_ENV="$BACKEND/.env.e2e"
E2E_DB="$BACKEND/database/database.e2e.sqlite"
PORT="${E2E_PORT:-8001}"
readonly E2E_CHECKOUT_NEW_ACCOUNT_HOURS=0
readonly E2E_CHECKOUT_LIMIT=1000
readonly E2E_TURNSTILE_SITE_KEY="1x00000000000000000000AA"
readonly E2E_TURNSTILE_SECRET="1x0000000000000000000000000000000AA"
readonly E2E_TURNSTILE_USER_THRESHOLD=3
readonly E2E_TURNSTILE_IP_THRESHOLD=1000
readonly E2E_MEILISEARCH_HEALTH_URL="${E2E_MEILISEARCH_HEALTH_URL:-http://127.0.0.1:7701/health}"
readonly E2E_MEILISEARCH_READY_TIMEOUT_SECONDS="${E2E_MEILISEARCH_READY_TIMEOUT_SECONDS:-60}"
readonly E2E_MEILISEARCH_REQUEST_TIMEOUT_SECONDS="${E2E_MEILISEARCH_REQUEST_TIMEOUT_SECONDS:-2}"
readonly E2E_MEILISEARCH_READY_POLL_SECONDS="${E2E_MEILISEARCH_READY_POLL_SECONDS:-1}"

log()  { printf '[e2e-up] %s\n' "$*"; }
fail() { printf '[e2e-up] FEHLER: %s\n' "$*" >&2; exit 1; }

# --- 1. Test-Services (Meili 7701) ------------------------------------------
# Projektname bewusst NICHT gesetzt: docker-compose.test.yml definiert fixe
# container_name (portal_search_test) — ein einziger Compose-Projektname
# (Default) verhindert Container-Name-Conflicts zwischen "Start Docker (Test)"
# und diesem Skript.
# E2E_SKIP_DOCKER_SERVICES=1 überspringt ausschließlich den Compose-Start aus
# Schritt 1. Der Readiness-Check läuft in beiden Fällen unverändert gegen 7701,
# d. h. ein nativ gestarteter Meilisearch mit derselben Version und demselben
# Master-Key aus docker-compose.test.yml ist ein gültiger Ersatz. Default "0"
# = bisheriges Verhalten (Docker).
readonly E2E_SKIP_DOCKER_SERVICES="${E2E_SKIP_DOCKER_SERVICES:-0}"

if [ "$E2E_SKIP_DOCKER_SERVICES" = "1" ]; then
    log "E2E_SKIP_DOCKER_SERVICES=1: ueberspringe docker compose (erwartet nativ laufenden Meilisearch auf ${E2E_MEILISEARCH_HEALTH_URL}) ..."
else
    log "Starte Test-Services (docker-compose.test.yml) ..."
    docker compose -f "$ROOT/docker-compose.test.yml" up -d
fi

log "Warte auf Meilisearch (bounded readiness check) ..."
if ! MEILISEARCH_HEALTH_URL="$E2E_MEILISEARCH_HEALTH_URL" \
    MEILISEARCH_READY_TIMEOUT_SECONDS="$E2E_MEILISEARCH_READY_TIMEOUT_SECONDS" \
    MEILISEARCH_REQUEST_TIMEOUT_SECONDS="$E2E_MEILISEARCH_REQUEST_TIMEOUT_SECONDS" \
    MEILISEARCH_READY_POLL_SECONDS="$E2E_MEILISEARCH_READY_POLL_SECONDS" \
    bash "$ROOT/scripts/wait-for-meilisearch.sh"; then
    log "Meilisearch readiness diagnostics:"
    if [ "$E2E_SKIP_DOCKER_SERVICES" != "1" ]; then
        docker compose -f "$ROOT/docker-compose.test.yml" ps search || true
        docker compose -f "$ROOT/docker-compose.test.yml" logs --no-color --tail 50 search || true
    fi
    fail "Meilisearch did not become ready within ${E2E_MEILISEARCH_READY_TIMEOUT_SECONDS}s; aborting E2E setup."
fi

# --- 2. .env.e2e aus .env ableiten (Safe-Patching mit Validierung) ---------
[ -f "$BACKEND/.env" ] || fail "backend/.env fehlt — bitte zuerst lokal einrichten (README Quickstart)."

log "Generiere $E2E_ENV aus backend/.env ..."
cp "$BACKEND/.env" "$E2E_ENV"

set_env() { # key value
    local key="$1" value="$2"
    if grep -qE "^${key}=" "$E2E_ENV"; then
        sed -i.bak -E "s|^${key}=.*|${key}=${value}|" "$E2E_ENV" && rm -f "$E2E_ENV.bak"
    else
        printf '%s=%s\n' "$key" "$value" >> "$E2E_ENV"
    fi
    grep -qE "^${key}=${value}$" "$E2E_ENV" || fail "Konnte ${key} nicht in ${E2E_ENV} setzen."
}

set_env APP_ENV "local"
set_env APP_URL "http://localhost:${PORT}"
set_env FRONTEND_URL "http://localhost:4321"
set_env DB_CONNECTION "sqlite"
set_env DB_DATABASE "database/database.e2e.sqlite"
set_env ADMIN_EMAIL "admin@example.com"
set_env ADMIN_PASSWORD "admin"
set_env MEILISEARCH_HOST "http://127.0.0.1:7701"
set_env MEILISEARCH_KEY "test_meili_secret"
set_env SCOUT_PREFIX "e2e_"
set_env MAIL_HOST "127.0.0.1"
set_env MAIL_PORT "1025"
set_env MAIL_SCHEME "smtp"
set_env MAIL_REQUIRE_TLS "false"
set_env MAIL_FROM_ADDRESS "test@reisinger.pictures"
set_env AUTH_THROTTLE_LIMIT "1000"
set_env MODEL_REGISTRATION_THROTTLE_LIMIT "1000"

# Checkout defense overrides for the isolated E2E backend. The account-age
# gate and dedicated checkout limiters stay test-only; the risk thresholds
# still allow the Turnstile checkout spec to exercise its third-attempt path.
set_env STRIPE_CHECKOUT_NEW_ACCOUNT_HOURS "$E2E_CHECKOUT_NEW_ACCOUNT_HOURS"
set_env CHECKOUT_THROTTLE_USER_PER_HOUR "$E2E_CHECKOUT_LIMIT"
set_env CHECKOUT_THROTTLE_IP_PER_HOUR "$E2E_CHECKOUT_LIMIT"
set_env CHECKOUT_THROTTLE_IP_PER_DAY "$E2E_CHECKOUT_LIMIT"
set_env TURNSTILE_SITE_KEY "$E2E_TURNSTILE_SITE_KEY"
set_env TURNSTILE_SECRET "$E2E_TURNSTILE_SECRET"
set_env TURNSTILE_ALLOWED_HOSTNAMES "localhost"
set_env TURNSTILE_USER_THRESHOLD_PER_HOUR "$E2E_TURNSTILE_USER_THRESHOLD"
set_env TURNSTILE_IP_THRESHOLD_PER_HOUR "$E2E_TURNSTILE_IP_THRESHOLD"
set_env TURNSTILE_ALLOW_DUMMY_TEST_KEYS "true"
set_env CI "true"

# --- 3. E2E-SQLite-DB anlegen + migrieren + seeden --------------------------
log "Lege E2E-SQLite-DB an ($E2E_DB) ..."
touch "$E2E_DB"
log "Migriere + seede E2E-DB (env=e2e) ..."
( cd "$BACKEND" && env APP_ENV=local CI=true php artisan migrate:fresh --seed --env=e2e )

# --- 4. Deterministische Location-Fixtures + Suchindex ----------------------
# Der Basiseeder bleibt offline. Nur die explizite E2E-DB erhält die kleine,
# versionierte Fixture; der Produktionsimport bleibt im woechentlichen Scheduler.
log "Lade E2E-Location-Fixtures und aktualisiere den Test-Suchindex ..."
(
  cd "$BACKEND"
  php artisan db:seed --class=E2ELocationSeeder --force --env=e2e
  php artisan scout:flush 'App\Models\Location' --env=e2e
  php artisan scout:sync-index-settings --env=e2e
  php artisan scout:import 'App\Models\Location' --env=e2e
)

# --- 5. Backend isoliert starten --------------------------------------------
log "Starte E2E-Backend auf http://127.0.0.1:${PORT} (STRG+C = Stopp)"
log "Frontend (separat):   VITE_API_PROXY=http://127.0.0.1:${PORT} pnpm dev"
log "E2E-Tests:            pnpm test:e2e (Mailpit 8025 = Helper-Default)"
# Keep the isolated backend authoritative even if the invoking IDE exports
# production or CI values. The official dummy-key compatibility path is CI-only.
exec env \
    APP_ENV=local \
    CI=true \
    STRIPE_CHECKOUT_NEW_ACCOUNT_HOURS="$E2E_CHECKOUT_NEW_ACCOUNT_HOURS" \
    CHECKOUT_THROTTLE_USER_PER_HOUR="$E2E_CHECKOUT_LIMIT" \
    CHECKOUT_THROTTLE_IP_PER_HOUR="$E2E_CHECKOUT_LIMIT" \
    CHECKOUT_THROTTLE_IP_PER_DAY="$E2E_CHECKOUT_LIMIT" \
    TURNSTILE_SITE_KEY="$E2E_TURNSTILE_SITE_KEY" \
    TURNSTILE_SECRET="$E2E_TURNSTILE_SECRET" \
    TURNSTILE_ALLOWED_HOSTNAMES="localhost" \
    TURNSTILE_USER_THRESHOLD_PER_HOUR="$E2E_TURNSTILE_USER_THRESHOLD" \
    TURNSTILE_IP_THRESHOLD_PER_HOUR="$E2E_TURNSTILE_IP_THRESHOLD" \
    TURNSTILE_ALLOW_DUMMY_TEST_KEYS="true" \
    php "$BACKEND/artisan" serve --host=127.0.0.1 --port="$PORT" --env=e2e --no-reload
