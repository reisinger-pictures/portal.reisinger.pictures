---
domain: infrastructure
topic: deployment
status: active
---

# Technical Concept: Deployment & Production

## 1. Portainer & Docker Stack
- The project is deployed as a Docker stack via **Portainer**.
- **Automated Init:** The initialization logic is embedded directly into the `command` block of the `backend` container. It waits for the database to be ready, generates missing application keys (`APP_KEY`, `JWT_SECRET`), and runs `php artisan migrate --force` before starting the background workers and PHP-FPM. 

## 2. Environment Variables
- All configuration is managed via Portainer environment variables, overriding the `.env` file.
- Key variables include database credentials (`DB_ROOT_PASSWORD`), Meilisearch keys (`MEILI_MASTER_KEY`), and SMTP settings (e.g., Gmail App Passwords).

## 3. Frontend & Routing
- The frontend is built statically (`pnpm build`) and served **directly by Caddy** (kein nginx-Container mehr, seit 2026-07-31).
- Caddy (zentraler Reverse Proxy) handles the routing:
  - `/api/*` -> `portal_backend` (PHP-FPM via FastCGI auf Port 9000)
  - `/*` -> statische `dist/` via `spa`-Snippet (Root: `/srv/websites/web-portal.reisinger.pictures/dist`, Symlink auf `/home/webadmin/websites/web-portal.reisinger.pictures`)
- **CSP & X-Frame-Options** werden im Caddyfile gesetzt (Block `portal.reisinger.pictures`). Achtung: Der `Content-Security-Policy`-Header enthält einen `sha256`-Hash des Inline-Brand-Scripts aus `frontend/index.html`. Wird dieses Script geändert, muss der Hash im Caddyfile neu berechnet werden (`openssl dgst -sha256 -binary | base64`).

## 4. Caddy + PHP-FPM Architektur (Apache abgelöst)
- **Basis-Image:** `ghcr.io/reisi007/php-base:8.5` (ersetzt `portal-base`)
- **Webserver:** PHP-FPM statt Apache – Caddy spricht via FastCGI-Protokoll mit dem Backend
- **File Delivery:** `X-Accel-Redirect` statt `X-Sendfile` – Caddy fängt den Header via `handle_response` ab und serviert Dateien direkt von der Festplatte
- **Pfad-Mapping:** Der `PROXY_DELIVERY_HEADER` ist fest auf `X-Accel-Redirect` gesetzt. Der Pfad `/var/www/photos/...` wird in Caddy via `handle_path` auf das gemountete Volume `/srv/photos` umgeschrieben
- **Vorteil:** Kein Apache mehr – einheitliche PHP-FPM-Images für alle Projekte (form2email + portal)

## 5. Deployment-Pfade (Server-Dateisystem)
- **Pfad-Trennung:** Das Backend liegt unter `/home/webadmin/websites/api-portal.reisinger.pictures`, das Frontend unter `/home/webadmin/websites/web-portal.reisinger.pictures`.
- **Hinweis:** `api-portal.reisinger.pictures` ist lediglich ein Deployment-Pfad auf dem Server-Dateisystem und **keine DNS-Subdomain** — die API wird same-origin unter `portal.reisinger.pictures/api/*` ausgeliefert.
- **Caddy Routing:** Caddy routet `/api*` per FastCGI an `portal_backend:9000`

## 6. Externer FTP-Mount & Pfad-Konfiguration
- **Storage:** Der FTP-Ordner liegt außerhalb der App-Verzeichnisse unter `/home/webadmin/websites/ftp`.
- **Integration:** Dieser wird als Volume nach `/var/www/ftp` gemountet. Die Umgebungsvariable `FTP_STORAGE_PATH` weist Laravel an, diesen Pfad für die FTP-Inbox zu nutzen.

## 7. Automatisierte Admin-Provisionierung
- **Kommando:** `php artisan admin:update`
- **Logik:** Synchronisiert beim Start des `backend-init`-Containers den Admin-Nutzer basierend auf den ENV-Variablen `ADMIN_EMAIL` und `ADMIN_PASSWORD`. Dies stellt sicher, dass ein Login auch ohne initiale Datenbank-Seeds sofort möglich ist.

## 8. Local Development Defaults (Intentional)

Several config files ship with hardcoded fallback values for **zero-config local development**:

| Config Key | File | Default Value |
|---|---|---|
| `APP_KEY` | `config/app.php` | `base64:jQkBT0mi24S6wKESY5wMW44SUl2HO5Mu+JwzPK/pnHQ=` |
| `JWT_SECRET` | `config/jwt.php` | `rC2PoXX5CAbw8Ubbr5E58BPsxVo5WZQSaytUuo7vhaQ=` |
| `STRIPE_KEY` | `config/services.php` | `pk_test_...` (dev-mode only) |
| `STRIPE_SECRET` | `config/services.php` | `sk_test_...` (dev-mode only) |
| `DB_PASSWORD` | `config/database.php` | `admin` |

**Policy:** These are **intentionally weak/public defaults** so that `git clone && ./vendor/bin/sail up` (or equivalent) works without any manual `.env` setup. They are **never used in production** because:

- The `backend-init` container explicitly rejects them in production (see §9).
- Production environments set real values via Portainer environment variables, which override all fallbacks.
- `config/services.php` has a `production ? null :` guard for Stripe keys.

> **Rule for contributors:** Never rely on these defaults for security. Always set real secrets in `.env` for staging/production. The defaults are for local dev only.

## 9. Produktion-Sicherheits-Gatekeeper
- **Validierung:** Der `backend`-Container verweigert den Start in 'production', wenn `APP_KEY` oder `JWT_SECRET` nicht gesetzt sind (leer). Die Prüfung erfolgt generisch ohne hartcodierte Schlüsselwerte, um eine erneute Exposition über das Repository zu vermeiden.


## 10. Meilisearch Upgrade — Deployment Procedure

Wenn das `search`-Image in `docker-compose.yml` auf eine neue Major/Minor-Version gehoben wird (z.B. v1.42 → v1.48):

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
