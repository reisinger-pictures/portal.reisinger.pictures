---
domain: infrastructure
topic: deployment
status: active
---

# Technical Concept: Deployment & Production

## 1. Portainer & Docker Stack
- The project is deployed as a Docker stack via **Portainer**.
- **Automated Init:** The initialization logic is embedded directly into the `command` block of the `backend` container. It waits for the database and search services to be ready, then performs the fail-closed migration/seed gate below before starting background workers and PHP-FPM. Missing application keys are **not** generated implicitly during production startup; the container refuses to start without them.

## 2. Environment Variables
- All configuration is managed via Portainer environment variables, overriding the `.env` file.
- Key variables include database credentials (`DB_ROOT_PASSWORD`), Meilisearch keys (`MEILI_MASTER_KEY`), and SMTP settings (production currently uses ZeptoMail; the legacy Gmail transport remains only as a code fallback).

## 3. Frontend & Routing
- The frontend is built statically (`pnpm build`) and served **directly by Caddy** (kein nginx-Container mehr, seit 2026-07-31).
- Caddy (zentraler Reverse Proxy) handles the routing:
  - `/api/*` -> `portal_backend` (PHP-FPM via FastCGI auf Port 9000)
  - `/*` -> statische `dist/` via `spa`-Snippet (Root: `/srv/websites/web-portal.reisinger.pictures/dist`, Symlink auf `/home/webadmin/websites/web-portal.reisinger.pictures`)
- **CSP & X-Frame-Options** werden im Caddyfile gesetzt (Block `portal.reisinger.pictures`). Das Brand-Favicon-Script ist eine statische Datei unter `/brand-favicon-rewrite.js` (siehe `frontend/public/brand-favicon-rewrite.js`), die über `script-src 'self'` abgedeckt ist — der früher nötige `sha256`-Hash des Inline-Scripts kann aus dem Caddyfile entfernt werden. Der Header wird nur noch geändert, wenn sich die übrige CSP-Policy ändert.

## 4. Caddy + PHP-FPM Architektur (Apache abgelöst)
- **Basis-Image:** `ghcr.io/reisi007/portal-base:8.5` (Spezial-Image, gebaut per Cron aus diesem Repo — siehe `.github/workflows/base-image.yml`). Der Veröffentlichungs-/Freshness-Status des GHCR-Digests bleibt eine Release-/Betriebsprüfung.
- **Webserver:** PHP-FPM statt Apache – Caddy spricht via FastCGI-Protokoll mit dem Backend
- **File Delivery:** `X-Accel-Redirect` statt `X-Sendfile` – Caddy fängt den Header via `handle_response` ab und serviert Dateien direkt von der Festplatte
- **Pfad-Mapping:** Der `PROXY_DELIVERY_HEADER` ist fest auf `X-Accel-Redirect` gesetzt. Der Pfad `/var/www/photos/...` wird in Caddy via `handle_path` auf das gemountete Volume `/srv/photos` umgeschrieben
- **Sicherheitshinweis (akzeptiert, 2026-08-13):** Der `X-Accel-Redirect`-Header trägt den absoluten Server-Pfad, wird aber ausschließlich von Caddy (`handle_response @accel_header`) konsumiert und erreicht den Client nie — das Backend ist nur intern via Caddy erreichbar (same-origin `/api/*`). Reine interne Optimierung, kein Härtungsbedarf (Review S4).
- **Vorteil:** Kein Apache mehr – einheitliche PHP-FPM-Images für alle Projekte (form2email + portal)

## 5. Deployment-Pfade (Server-Dateisystem)
- **Pfad-Trennung:** Das Backend liegt unter `/home/webadmin/websites/api-portal.reisinger.pictures`, das Frontend unter `/home/webadmin/websites/web-portal.reisinger.pictures`.
- **Hinweis:** `api-portal.reisinger.pictures` ist lediglich ein Deployment-Pfad auf dem Server-Dateisystem und **keine DNS-Subdomain** — die API wird same-origin unter `portal.reisinger.pictures/api/*` ausgeliefert.
- **Caddy Routing:** Caddy routet `/api*` per FastCGI an `portal_backend:9000`

## 6. Externer FTP-Mount & Pfad-Konfiguration
- **Storage:** Der FTP-Ordner liegt außerhalb der App-Verzeichnisse unter `/home/webadmin/websites/ftp`.
- **Integration:** Dieser wird als Volume nach `/var/www/ftp` gemountet. Die Umgebungsvariable `FTP_STORAGE_PATH` weist Laravel an, diesen Pfad für die FTP-Inbox zu nutzen.

## 7. Automatisierte Admin-Provisionierung
- **Kommando:** `php artisan admin:update` ist ein **erforderlicher letzter
  Schritt** im Backend-Start und wird nicht als ungeschützter Nachlauf
  ausgeführt.
- **Fail-closed Sequenz:** Nach den Env-/Identitäts-/Pfad-Guards aus §9 führt der
  Start `php artisan migrate --force && php artisan db:seed --force && php artisan admin:update || exit 1`
  aus. Ein Fehler bei Migration, Seed **oder** Admin-Provisionierung beendet den
  Compose-Start, bevor `queue:work`, Scheduler oder PHP-FPM gestartet werden.
- **Credentials:** `ADMIN_EMAIL` und `ADMIN_PASSWORD` müssen gesetzt sein; es
  gibt keinen Runtime-Fallback. `DatabaseSeeder` legt den Bootstrap-Admin mit
  `firstOrCreate` an. `admin:update` legt ihn bei Bedarf an und rotiert das
  Passwort eines bestehenden Accounts aus denselben Env-Werten; ein fehlender
  Wert lässt das Command fehlschlagen.

## 8. Secrets, Environment & Debug Defaults

All sensitive config is read strictly via `env(...)` with **no hardcoded fallbacks** anywhere under `backend/config/` (Security-Review C1/C2/C3b):

| Config Key | File | Resolution |
|---|---|---|
| `APP_KEY` | `config/app.php` | `env('APP_KEY')` |
| `JWT_SECRET` | `config/jwt.php` | `env('JWT_SECRET')` |
| `FILE_ENCRYPTION_KEY` | File-Encryption config/provider | `env('FILE_ENCRYPTION_KEY')`, separate from `APP_KEY` |
| `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` | `config/services.php` | `env(...)`, no `production ? null :` guard |
| `DB_PASSWORD` | `config/database.php` | `env('DB_PASSWORD')` |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | `config/admin.php` | `env(...)`, required, no fallback |

- **Fail closed:** Missing `APP_KEY`/`JWT_SECRET`/`FILE_ENCRYPTION_KEY`/`ADMIN_EMAIL`/`ADMIN_PASSWORD` aborts the production start (see §9). Blank env values are treated as unset — never silently replaced.
- **Debug mode:** `APP_DEBUG` defaults to **`false`** (`config/app.php`) and MUST stay `false` in production. Verbose error pages are a local-dev-only opt-in (`.env.example` ships `APP_DEBUG=true`); `deployment/docker-compose.yml` defaults to `${APP_DEBUG:-false}`.
- **Production secrets are machine-local:** `backend/.env.production` (and `deployment/.env.production`) is **untracked/gitignored**. Only key names and placeholder templates live in the repo — real values (DB, SMTP/ZeptoMail, Stripe live keys) are provided per host via Portainer env / the local env file. Never commit or quote secret values.

## 9. Produktion-Sicherheits-Gatekeeper
- **Identitäts-Guard:** Der `backend`-Container verweigert den Start, wenn er
  nicht als UID:GID `1000:1000` läuft.
- **Credential-/Pfad-Guard:** Er verweigert den Start bei leerem
  `APP_KEY`, `JWT_SECRET`, `FILE_ENCRYPTION_KEY`, `ADMIN_EMAIL`,
  `ADMIN_PASSWORD` oder `PHOTO_STORAGE_PATH`. `PHOTO_STORAGE_PATH` muss ein
  absoluter Pfad sein. `/var/www/html`, der konfigurierte Storage-Pfad und
  `/var/www/ftp` müssen existieren, schreibbar sein und `stat` muss für sie
  `1000:1000` liefern. Die Prüfung erfolgt generisch ohne hartcodierte
  Schlüsselwerte.
- **Migrations-/Seed-/Admin-Gate:** Erst nach diesen Guards führt der Start
  `php artisan migrate --force && php artisan db:seed --force && php artisan admin:update || exit 1`
  aus. Ein Fehler in **einem** dieser Schritte verhindert `queue:work`, den
  Scheduler und PHP-FPM. `admin:update` ist damit Teil desselben
  fail-closed Gates; ein fehlgeschlagener Admin-Schritt darf nicht als
  erfolgreicher Deployment-Start durchrutschen.


## 10. Meilisearch Upgrade — Deployment Procedure

Wenn das `search`-Image in `deployment/docker-compose.yml` auf eine neue Major/Minor-Version gehoben wird (z.B. v1.42 → v1.48):

```bash
# 1. Vor dem Deploy: Alte Suchdaten löschen (optional, aber empfohlen)
docker volume rm portal_search_data

# 2. Stack über Portainer neu deployen (oder docker stack deploy)
#    → scout:sync-index-settings + queue:restart laufen automatisch beim Container-Start

# 3. Nach erfolgreichem Start: Suchindex komplett neu aufbauen
docker exec portal_backend php artisan app:search-rebuild
```

Das `app:search-rebuild`-Command flushed alle 5 Indizes (Photo, Gallery, Location, Customer, TextSnippet), synchronisiert die Index-Settings aus `config/scout.php`, importiert alle Daten neu und restartet die Queue-Worker.

**Ohne Volume-Löschung:** Meilisearch migriert bestehende Daten automatisch — in der Praxis stabil, aber bei Major-Upgrades nicht garantiert. Bei Problemen: Volume löschen und rebuild laufen lassen.

**Normale Code-Deploys (kein Meilisearch-Upgrade):** `scout:sync-index-settings` + `queue:restart` laufen bei jedem Container-Start automatisch. Kein manuelles Eingreifen nötig.

## 11. OPcache & Live-Updates (Rclone Sync)
- **Die Falle:** Wenn PHP-Dateien im laufenden Betrieb über `rclone` (z.B. durch die `sync.bat`) auf den Produktionsserver synchronisiert werden, greifen Backend-Änderungen unter Umständen nicht sofort.
- **Der Grund:** In Produktionsumgebungen ist der PHP OPcache aus Performancegründen scharf geschaltet (meist `opcache.validate_timestamps=0`). PHP liest geänderte Dateien nicht neu von der Festplatte ein, sondern nutzt den alten Bytecode aus dem RAM.
- **Die Lösung:** Nach einem Rclone-Sync von Backend-Dateien muss der PHP-Container (PHP-FPM) zwingend neu gestartet werden, um den Cache zu leeren:
  `docker restart portal_backend`
- **App-Cache (2026-08-17):** Das `command`-Block des Backend-Containers führt bei jedem Start `php artisan cache:clear` aus. Damit werden forever-gecachte Werte wie `laravel_build_time` (System-Info-Seite) bei jedem Deploy/Neustart frisch berechnet — der Timestamp ist die neueste mtime **aller** PHP-Dateien (exkl. `vendor/`, `storage/`, `bootstrap/cache/`), nicht nur einzelner Verzeichnisse.

## 12. Frontend E2E-Artefakte (Playwright, CI)
- **Traces werden im CI nie aufgezeichnet oder hochgeladen.** `frontend/playwright.config.ts` setzt `trace` unter `CI` auf `off`; Grund: Ein Trace serialisiert den Browser-Storage inkl. httpOnly-Auth-Cookies, und dieses Repository ist öffentlich — CI-Artefakte wären weltlesbar. Lokales Tracing ist explizit per `PW_TRACE=1` opt-in (`on-first-retry`).
- **Video ist global deaktiviert** (`video: 'off'`).
