#!/usr/bin/env bash
# ==========================================================================
# e2e-up.sh — Isolierter lokaler E2E-Backend (eigene SQLite-DB + Port 8001)
# --------------------------------------------------------------------------
# Startet einen SEPARATEN Backend-Prozess für lokale Playwright-E2E-Tests,
# damit die lokale Dev-Instanz (portal.test / database/database.sqlite)
# von E2E-Tests unberührt bleibt.
#
# Ablauf (idempotent):
#   1. Test-Services starten  (docker-compose.test.yml: Meili 7701, Mailpit 8026/1026)
#   2. backend/.env.e2e generieren (aus backend/.env, Secrets werden übernommen)
#   3. Eigene SQLite-DB anlegen + migrieren + seeden (env=e2e)
#   4. php artisan serve auf http://127.0.0.1:8001 (env=e2e, --no-reload)
#
# Frontend (separat, Proxy auf den E2E-Backend):
#   VITE_API_PROXY=http://127.0.0.1:8001 pnpm dev
#
# E2E-Tests (Mailpit-API auf Test-Instanz):
#   MAILPIT_API_URL=http://127.0.0.1:8026/api/v1 pnpm test:e2e
# ==========================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"
E2E_ENV="$BACKEND/.env.e2e"
E2E_DB="$BACKEND/database/database.e2e.sqlite"
PORT="${E2E_PORT:-8001}"

log()  { printf '[e2e-up] %s\n' "$*"; }
fail() { printf '[e2e-up] FEHLER: %s\n' "$*" >&2; exit 1; }

# --- 1. Test-Services (Meili 7701, Mailpit 8026/1026) ----------------------
# Projektname bewusst NICHT gesetzt: docker-compose.test.yml definiert fixe
# container_name (portal_search_test / portal_mailpit_test) — ein einziger
# Compose-Projektname (Default) verhindert Container-Name-Conflicts zwischen
# "Start Docker (Test)" und diesem Skript.
log "Starte Test-Services (docker-compose.test.yml) ..."
docker compose -f "$ROOT/docker-compose.test.yml" up -d

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
set_env MAIL_PORT "1026"
set_env MAIL_ENCRYPTION ""
set_env MAIL_FROM_ADDRESS "test@reisinger.pictures"
set_env AUTH_THROTTLE_LIMIT "1000"

# --- 3. E2E-SQLite-DB anlegen + migrieren + seeden --------------------------
log "Lege E2E-SQLite-DB an ($E2E_DB) ..."
touch "$E2E_DB"
log "Migriere + seede E2E-DB (env=e2e) ..."
( cd "$BACKEND" && php artisan migrate:fresh --seed --env=e2e )

# --- 4. Backend isoliert starten --------------------------------------------
log "Starte E2E-Backend auf http://127.0.0.1:${PORT} (STRG+C = Stopp)"
log "Frontend (separat):   VITE_API_PROXY=http://127.0.0.1:${PORT} pnpm dev"
log "E2E-Tests:            MAILPIT_API_URL=http://127.0.0.1:8026/api/v1 pnpm test:e2e"
exec php "$BACKEND/artisan" serve --host=127.0.0.1 --port="$PORT" --env=e2e --no-reload
