---
domain: infrastructure
topic: deployment
status: active
---

# Technical Concept: Deployment & Production

## 1. Repository-managed Docker Compose Stack
- `deployment/docker-compose.yml` is the source of truth. `deployment/deploy.sh` transfers only that file to `/opt/stacks/portal-reisinger-pictures/compose.yaml` and runs `docker compose -f /opt/stacks/portal-reisinger-pictures/compose.yaml up -d --wait` on the host. Images are pulled from GHCR; this deploy does not build them.
- Keep the host project directory named exactly `portal-reisinger-pictures`; Compose uses it as the project/volume namespace. Watchtower remains global and needs no label changes.
- The real production `.env` is host-only at `/opt/stacks/portal-reisinger-pictures/.env` (`root:root`, mode `0600`). The deploy script never creates or overwrites it and aborts if it is missing. Include it in protected backups. The names-only inventory is [`deployment/production-env.keys`](../../deployment/production-env.keys); the migration plan is [here](../../../strato-vps/docs/plans/portainer-exit.md).
- **Automated Init:** The initialization logic is embedded directly into the `command` block of the `backend` container. It waits for the database and search services to be ready, then performs the fail-closed migration/seed gate below before starting background workers and PHP-FPM. Missing application keys are **not** generated implicitly during production startup; the container refuses to start without them.
- **Database:** PostgreSQL 16 (D-64) as the `db_pg` service and the `portal_db_pg` container; the backend selects it with `DB_CONNECTION=pgsql`, `DB_HOST=db_pg`, `DB_PORT=5432`. The legacy MariaDB service and its `db_data` volume are no longer part of the repository stack; the old container and volume were removed from the production host on 2026-11-23 after the manual gate was measured (D-64). The one-time forward copy and its verification remain a host-executable procedure in [`deployment/migrate-to-postgres.sh`](../../deployment/migrate-to-postgres.sh), contract in [30-postgres-cutover.md](30-postgres-cutover.md). PostgreSQL uses `DB_PASSWORD` only.

## 2. Environment Variables
- Compose reads production values from the host `.env` beside `compose.yaml`; there is no second `env_file` source. Never commit or copy production values into the repository.
- Key variables include database credentials (`DB_PASSWORD`), Meilisearch keys (`MEILI_MASTER_KEY`), and SMTP settings (production sends through the Zoho Mail mailbox via `smtppro.zoho.eu`; the legacy Gmail transport remains only as a code fallback).
- Production queue/scheduler values are explicit: `QUEUE_CONNECTION=database`, `DB_QUEUE_CONNECTION=DB_CONNECTION`, `DB_QUEUE_RETRY_AFTER=90`, `QUEUE_WORKER_TIMEOUT=60`, and a shared `CACHE_STORE=database` with `DB_CACHE_CONNECTION=DB_CONNECTION` and `DB_CACHE_LOCK_CONNECTION=DB_CACHE_CONNECTION`. The values are validated before background processes start; see [29-production-operations-runbook.md](29-production-operations-runbook.md).
- Production SMTP requires `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtp|smtps`, `MAIL_REQUIRE_TLS=true`, a host/port, credentials, and a real `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`. `MAIL_ENCRYPTION` is ignored by Laravel 13/Symfony and is not a TLS control. Local Mailpit and CI fixtures are intentionally exempt because the policy is production-only.

## 3. Frontend & Routing
- The frontend is built statically (`pnpm build`) and served **directly by Caddy** (kein nginx-Container mehr, seit 2026-07-31).
- Caddy (zentraler Reverse Proxy) handles the routing:
  - `/api/*` -> `portal_backend` (PHP-FPM via FastCGI auf Port 9000)
  - `/*` -> statische `dist/` via `spa`-Snippet (Root: `/srv/websites/web-portal.reisinger.pictures/dist`, Symlink auf `/home/webadmin/websites/web-portal.reisinger.pictures`)
- **CSP & X-Frame-Options** werden im Caddyfile gesetzt (Block `portal.reisinger.pictures`). Das Brand-Favicon-Script ist eine statische Datei unter `/brand-favicon-rewrite.js` (siehe `frontend/public/brand-favicon-rewrite.js`), die über `script-src 'self'` abgedeckt ist — der früher nötige `sha256`-Hash des Inline-Scripts kann aus dem Caddyfile entfernt werden. Der Header wird nur noch geändert, wenn sich die übrige CSP-Policy ändert.

## 4. Caddy + PHP-FPM Architektur (Apache abgelöst)
- **Basis-Image:** `ghcr.io/reisinger-pictures/portal-base:8.5` (Spezial-Image, gebaut per Cron aus diesem Repo — siehe `.github/workflows/base-image.yml`). Der Workflow publiziert über `OWNER: ${{ github.repository_owner }}` in den **Org-Namespace `ghcr.io/reisinger-pictures/`**; alle Verbraucher (Compose, `ci.yml`, `Dockerfile.e2e`, Doku) müssen denselben Namespace pinnen, sonst steht der gepinnte Tag auf einem Image, das nicht mehr neu gebaut wird. Gepinnt wird seit der Owner-Entscheidung 2026-09-26 über einen **konkreten Tag**, nicht über `@sha256` — ein Digest friert ein Image auf einen Build ein und bräuchte für jeden Patch-Release eine manuelle Aktualisierung (siehe `deployment/image-pin-freshness.md`, INFRA-10). Der verbleibende Sicherheitsnetz ist deshalb das Alter des Artefakts: `tests/infrastructure/verify-image-freshness.sh` löst den konsumierten Tag auf und schlägt fehl, sobald das Artefakt dahinter älter als `MAX_IMAGE_PIN_AGE_DAYS` (dokumentiert 14 Tage) ist.
- **GHCR-Paket-ACL:** `packages: write` im Workflow ist nur eine Token-Fähigkeit; zusätzlich muss das jeweilige GHCR-Paket dem Repo unter *Package settings → Manage Actions access* **Write** gewähren. Das gilt für `portal-base` **und** `portal-e2e`. Die paketbezogene Verknüpfung übersteht keine Repository-Neuanlage oder keinen Transfer; fehlt sie, scheitert der Push mit `permission_denied: write_package`, obwohl Build und Registry-Login erfolgreich sind. Der Grant ist nur in der GitHub-Oberfläche setzbar (nicht über die REST-API) und ist nach einer Neuanlage/erneut zu prüfen.
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
  Start `php artisan migrate --force && php artisan app:seed-if-fresh && php artisan admin:update || exit 1`
  aus. Ein Fehler bei Migration, Seed **oder** Admin-Provisionierung beendet den
  Compose-Start, bevor `queue:work`, Scheduler oder PHP-FPM gestartet werden.
- **Credentials:** `ADMIN_EMAIL` und `ADMIN_PASSWORD` müssen gesetzt sein; es
  gibt keinen Runtime-Fallback. `DatabaseSeeder` legt den Bootstrap-Admin mit
  `firstOrCreate` an. `admin:update` legt ihn bei Bedarf an und rotiert das
  Passwort eines bestehenden Accounts aus denselben Env-Werten; ein fehlender
  Wert lässt das Command fehlschlagen.
- **Bedingter Seed (Owner-Entscheidung 2026-09-28):** `app:seed-if-fresh` ruft
  denselben `DatabaseSeeder` auf, aber nur wenn die `users`-Tabelle leer ist —
  die Invariante, auf die der Start angewiesen ist (ohne Seed existiert kein
  Admin, der Login ist tot). Eine leere `settings`-Tabelle taugt nicht als
  Signal, weil Migrationen dort bereits Zeilen anlegen (V004 `base_price`/
  `term_*`, V005 die Bank-Keys); keine Migration legt dagegen einen User an.
  Reihenfolge im Start ist Teil des Vertrags: der Seed läuft **vor**
  `admin:update`, sonst wäre der Admin aus `admin:update` selbst das Signal.
  Bewusster Seed in Produktion bleibt möglich, aber nur manuell:
  `php artisan db:seed --force`, mit der Vorprüfung aus
  `backend/AGENTS.md` (Database Setup Policy).

## 8. Secrets, Environment & Debug Defaults

All sensitive config is read strictly via `env(...)` with **no hardcoded fallbacks** anywhere under `backend/config/` (Security-Review C1/C2/C3b):

| Config Key | File | Resolution |
|---|---|---|
| `APP_KEY` | `config/app.php` | `env('APP_KEY')` |
| Signed-Link-Signing-Key | `config/services.php` (`services.signed_links.secret`) | `env('SIGNED_LINK_SECRET') ?? env('JWT_SECRET') ?? env('APP_KEY')`; `JWT_SECRET` bleibt als Fallback für bereits ausgestellte Signed Links erhalten (D-77), `config/jwt.php` wurde entfernt |
| `FILE_ENCRYPTION_KEY` | File-Encryption config/provider | `env('FILE_ENCRYPTION_KEY')`, separate from `APP_KEY` |
| `MOLLIE_KEY` / `MOLLIE_PAYMENT_METHODS` / `MOLLIE_WEBHOOK_URL` (`MOLLIE_PAYMENT_METHOD` is the deprecated single-value fallback) | `config/services.php` | `env(...)`, server-only, list in display order; payment creation needs the key, a non-empty allowlist and a usable HTTPS webhook URL, otherwise it fails closed. Mollie is the only payment provider (D-63); there is no Stripe config key and no Stripe credential |
| `CHECKOUT_ENABLED` / `CHECKOUT_NEW_ACCOUNT_HOURS` | `config/checkout.php` | `env(...)` with the defaults `true` / `24`; provider-neutral checkout kill switch and minimum account age, no credential |
| `DB_PASSWORD` | `config/database.php` | `env('DB_PASSWORD')` |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | `config/admin.php` | `env(...)`, required, no fallback |

- **Fail closed:** Missing `APP_KEY`/`JWT_SECRET`/`FILE_ENCRYPTION_KEY`/`ADMIN_EMAIL`/`ADMIN_PASSWORD` aborts the production start (see §9). Blank env values are treated as unset — never silently replaced.
- **Debug mode:** `APP_DEBUG` defaults to **`false`** (`config/app.php`) and MUST stay `false` in production. Verbose error pages are a local-dev-only opt-in (`.env.example` ships `APP_DEBUG=true`); `deployment/docker-compose.yml` defaults to `${APP_DEBUG:-false}`.
- **Production secrets are machine-local:** `backend/.env.production` (and `deployment/.env.production`) is **untracked/gitignored**. The repo contains development/CI fixtures and production key names only; real production values (DB, SMTP app password, Mollie live key, `FTP_EVENT_SECRET`) are provided through `/opt/stacks/portal-reisinger-pictures/.env` on the host. Never commit or quote secret values. The provider-neutral checkout switches `CHECKOUT_ENABLED` and `CHECKOUT_NEW_ACCOUNT_HOURS` are names in the same inventory (`deployment/production-env.keys`), not secrets: they carry a default and block nothing at start.

## 9. Produktion-Sicherheits-Gatekeeper
- **Identitäts-Guard:** Der `backend`-Container verweigert den Start, wenn er
  nicht als UID:GID `1002:82` läuft — der Eigentümer des Website-Baums und
  seine Gruppe (D-1, Owner-Entscheidung 2026-10-25, OPS-3).
- **Credential-/Pfad-Guard:** Er verweigert den Start bei leerem
  `APP_KEY`, `JWT_SECRET`, `FILE_ENCRYPTION_KEY`, `ADMIN_EMAIL`,
  `ADMIN_PASSWORD` oder `PHOTO_STORAGE_PATH`. `PHOTO_STORAGE_PATH` muss ein
  absoluter Pfad sein. `/var/www/html`, der konfigurierte Storage-Pfad und
  `/var/www/ftp` müssen als Verzeichnis existieren und für die laufende UID
  schreibbar sein. Die Prüfung erfolgt generisch ohne hartcodierte
  Schlüsselwerte.
- **Ownership wird bewusst *nicht* geprüft:** Seit 2026-10-25 ist der Prozess
  selbst der Eigentümer des Baums, ein `stat`-Vergleich wäre also erfüllbar.
  Er bleibt draußen, weil 19-ftp 7.9 ein `chown -R` über
  `/home/webadmin/websites` verbietet (Vorfall 2026-09-26: 38.969 Dateien) und
  eine Reparatur damit nicht in Reichweite des Containers liegt; maßgeblich ist
  die Schreibbarkeit der laufenden UID. Die Identität selbst bleibt über den
  Identitäts-Guard und `user: "1002:82"` erzwungen. Der Fotospeicher
  `/home/webadmin/portal/images` liegt außerhalb des `websites`-Baums und wird
  einmalig auf `1002:82` mit `2775` gesetzt (deployment/README.md).
- **Modi allein genügen auf diesem Host nicht:** `storage/` und
  `bootstrap/cache/` tragen eine Default-ACL mit `default:other::rwx`. Sie
  überschreibt `umask 0022`, jede neu geschriebene Datei ist danach wieder
  `0666` — gemessen 2026-10-25: `bootstrap/cache/config.php` war nach dem
  Recreate erneut welt-schreibbar, obwohl alle Dateien vorher per `chmod`
  geschlossen worden waren. Der Rollout setzt deshalb zusätzlich
  `o::r-x`/`d:o::r-x` (Verzeichnisse, durchquerbar für die Auslieferung) und
  `o::r--` (Dateien); das ist Teil von Schritt 0 in `deployment/README.md`, und
  eine Schreibprobe nach dem Recreate muss sie bestätigen.
- **Keine Shell-Expansion im Compose-`command:`:** Ob `$$` zu `$` wird, hängt
  am **Feld**, nicht an der Schreibweise. Im `healthcheck` wird es reduziert:
  `Config.Healthcheck.Test` trägt `"$(cat …)"` (am laufenden Container gemessen
  2026-11-25), der Healthcheck ist gesund. Im `command:`-Block wird es in keiner
  YAML-Form reduziert — die Autorenform `$$(id -u)` erreichte die Shell am
  2026-09-26 als `$$` (`[ "1(id -u)" ... ]`, `Illegal number`, Start verweigert),
  und `docker compose config` gibt für beide Quellformen `$$(id -u)` aus, sodass
  die Ausgabe die Herkunft nicht unterscheidet. Der Block verzichtet deshalb auf
  jede Expansion: kein `$(...)`, kein `$$`, kein `${VAR}` und kein blankes
  `$VAR`, das Compose wie `${VAR}` interpolieren würde. Ein `$` als Regex-Anker
  (`'^[A-Za-z0-9-]*$'`) ist erlaubt: ihm folgt kein Name, das Gate prüft genau
  `\$[A-Za-z_]`. Ebenfalls verboten sind `#`-Kommentare im Block (`command: >`
  faltet alle Zeilen zu einer, und `sh` beginnt ab einem mitten liegenden `#`
  den Kommentar) und `${VAR}` (Compose würde Secrets zur Interpolationszeit
  in `docker inspect Cmd` schreiben). Der Guard
  liest daher über `printenv | grep` und `xargs -I{}`. Gate:
  `tests/infrastructure/compose-entrypoint-contract.sh`.
  Eine stack-weite Zählung ist dafür **kein** Maßstab: der Backend-Healthcheck
  verwendet bewusst drei `"$$(cat …)"`-Aufrufe in einer gefalteten `>-`-Zeile,
  und `grep -c '\$\$('` zählt deshalb **1** Zeile (gemessen 2026-11-25 auf dem
  Produktionshost), während der `command:`-Block für dieses Muster **0** ergibt
  (die beiden `$`-Regex-Anker erscheinen in der `config`-Ausgabe als `$$`, sind
  aber kein `$$(`).
- **Keine `ipv4_address` auf `portal_internal`:** Das Netz existiert bereits aus
  dem Stack und hat `172.21.0.0/16`; eine dort gepinnte Adresse bricht den Deploy
  mit `no configured subnet contains IP address`. Gepinnt wird nur `webnet`, weil
  Caddy auf die Backend-Adresse routet.
- **AI-Session-Config-Guard:** Nichtleere `AI_SESSION_HEADER` dürfen nur
  Buchstaben, Ziffern und Bindestriche enthalten; `AI_SESSION_PREFIX` darf leer
  sein oder nur Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich
  enthalten. CRLF und andere Zeichen verweigern den Produktionsstart. Fehlende
  Host-Overrides erhalten über die Compose-Interpolation dieselben Defaults wie
  `backend/config/services.php`; explizit leere Werte bleiben leer. Insbesondere
  bleibt ein leerer Header-Name die dokumentierte Session-Deaktivierung.
- **Migrations-/Seed-/Admin-Gate:** Erst nach diesen Guards führt der Start
  `php artisan migrate --force && php artisan app:seed-if-fresh && php artisan admin:update || exit 1`
  aus. Ein Fehler in **einem** dieser Schritte verhindert `queue:work`, den
  Scheduler und PHP-FPM. `admin:update` ist damit Teil desselben
  fail-closed Gates; ein fehlgeschlagener Admin-Schritt darf nicht als
  erfolgreicher Deployment-Start durchrutschen.
- **Operations-Policy-Gate:** Vor **jedem** Laravel-Kommando prüft der Start
  zuerst die Verfügbarkeit von `validate-production-env` und
  `portal-backend-supervisor` sowie die rohen Umgebungswerte. Whitespace,
  fraktionale Zahlen und ungültige Topologie werden thus nicht durch
  Casts/Normalisierung verdeckt und erreichen keine Migration/Seed. Nach
  `optimize` und vor Migration/Worker-Start führt der Start
  `php artisan ops:validate-production || exit 1` aus. Im Produktionsmodus
  müssen die Datenbank-Queue und ihre Failed-Job-Verbindung dieselbe
  DB-Verbindung wie die App nutzen, `QUEUE_WORKER_TIMEOUT` kleiner als
  `DB_QUEUE_RETRY_AFTER` sein, `MAIL_SCHEME`/`MAIL_REQUIRE_TLS` einen
  tatsächlich TLS-geschützten Symfony-Transport ergeben und für
  `onOneServer()` ein geteilter Cache-Store gesetzt sein. Fehlende, leere oder
  ungültige Werte beenden den Stack fail-closed. Lokale/CI-Fixtures mit
  `sync`/`array`/Mailpit werden nicht durch diese Produktionsregel
  fälschlich blockiert.
- **Image-Gate:** `deployment/docker-compose.yml` pinnt heute
  `ghcr.io/reisinger-pictures/portal-base:8.5` — **keinen** `@sha256`-Digest
  mehr (Owner-Entscheidung 2026-09-26, siehe
  `deployment/image-pin-freshness.md`). *Historie:* der bis dahin gepinnte
  Digest `…portal-base:8.5@sha256:d762d47c…` stammte aus dem
  `base-image.yml`-Lauf vom 2026-09-25, dessen Build-Log die Kopier-Schritte
  für `backend-supervisor.sh` und `validate-production-env.sh` ausweist; der
  frühere, auf den persönlichen Namespace zeigende Digest enthielt diese Skripte
  nicht. Beide Digests sind Build-Log-Evidenz für genau dieses eine
  Artefakt und sagen nichts über das, was der Tag heute auflöst. Compose
  verweigert den Start weiterhin fail-closed, wenn das aufgelöste Image die
  beiden Binaries nicht enthält (Preflight mit explizitem
  Rebuild-/GHCR-Push-/Tag-Hinweis), bevor `cache:clear`, Migration oder Seed
  laufen. Dass das Image die Binaries enthält, bleibt damit **kein**
  Live-Nachweis: ein tatsächlicher Start des Stacks ist ein externer
  Release-/Betriebsschritt, und Readiness wird daraus nicht abgeleitet. Für die
  Freshness des Artefakts ist das 14-Tage-Alter-Gate zuständig, nicht der
  Pin-Wert.
- **Worker-/Health-Vertrag:** `deployment/backend-supervisor.sh` überwacht den
  `queue:work`-Prozess im bestehenden Backend-Container, startet ihn nach
  einem Exit neu und schreibt PID-Marker. Der Compose-Healthcheck verlangt
  PHP-FPM, Supervisor, Worker und Scheduler als laufende Prozesse. Das ist ein
  statisch definierter Vertrag; Live-Health-, Queue- und SMTP-Nachweise bleiben
  eine Betreiberprüfung.


## 10. Meilisearch Upgrade — Deployment Procedure

Wenn das `search`-Image in `deployment/docker-compose.yml` auf eine neue Major/Minor-Version gehoben wird (z.B. v1.42 → v1.48):

```bash
# 1. Vor dem Deploy: Alte Suchdaten löschen (optional, aber empfohlen)
docker volume rm portal-reisinger-pictures_search_data

# 2. Compose-Datei aus dem Repo übertragen und den Stack aktualisieren
./deployment/deploy.sh
#    → scout:sync-index-settings + queue:restart laufen automatisch beim Container-Start

# 3. Nach erfolgreichem Start: Suchindex komplett neu aufbauen
docker exec portal_backend php artisan app:search-rebuild
```

Das `app:search-rebuild`-Command flushed alle 5 Indizes (Photo, Gallery, Location, Customer, TextSnippet), synchronisiert die Index-Settings aus `config/scout.php`, importiert alle Daten neu und restartet die Queue-Worker.

**Ohne Volume-Löschung:** Meilisearch migriert bestehende Daten automatisch — in der Praxis stabil, aber bei Major-Upgrades nicht garantiert. Bei Problemen: das zum Projekt `portal-reisinger-pictures` gehörende Search-Volume nur nach bestätigtem Backup gezielt löschen und den Index rebuilden lassen. Produktions-Stack-Befehle verwenden `/opt/stacks/portal-reisinger-pictures/compose.yaml`; niemals einen anderen Projektnamen verwenden.

**Normale Code-Deploys (kein Meilisearch-Upgrade):** `scout:sync-index-settings` + `queue:restart` laufen bei jedem Container-Start automatisch. Kein manuelles Eingreifen nötig.

## 11. OPcache & Live-Updates (rsync-Sync)
- **Die Falle:** Wenn PHP-Dateien im laufenden Betrieb über `rsync` (durch `sync.sh`) auf den Produktionsserver synchronisiert werden, greifen Backend-Änderungen unter Umständen nicht sofort.
- **Der Grund:** In Produktionsumgebungen ist der PHP OPcache aus Performancegründen scharf geschaltet (meist `opcache.validate_timestamps=0`). PHP liest geänderte Dateien nicht neu von der Festplatte ein, sondern nutzt den alten Bytecode aus dem RAM.
- **Die Lösung:** Nach einem rsync-Sync von Backend-Dateien muss der PHP-Container (PHP-FPM) zwingend neu gestartet werden, um den Cache zu leeren:
  `docker restart portal_backend`
- **Offene Lücke (Stand 2026-09-26):** `sync.sh` führt diesen Restart **nicht** aus. Das Verhalten ist seit dem Wechsel von rclone auf rsync unverändert, der Schritt bleibt aber manuell. Nach jedem Backend-Deploy ist `docker restart portal_backend` erforderlich, sonst liefert die Live-Instanz veralteten Bytecode.
- **App-Cache (2026-08-17):** Das `command`-Block des Backend-Containers führt bei jedem Start `php artisan cache:clear` aus. Damit werden forever-gecachte Werte wie `laravel_build_time` (System-Info-Seite) bei jedem Deploy/Neustart frisch berechnet — der Timestamp ist die neueste mtime **aller** PHP-Dateien (exkl. `vendor/`, `storage/`, `bootstrap/cache/`), nicht nur einzelner Verzeichnisse.

## 12. Frontend E2E-Artefakte (Playwright, CI)
- **Traces werden im CI nie aufgezeichnet oder hochgeladen.** `frontend/playwright.config.ts` setzt `trace` unter `CI` auf `off`; Grund: Ein Trace serialisiert den Browser-Storage inkl. httpOnly-Auth-Cookies, und dieses Repository ist öffentlich — CI-Artefakte wären weltlesbar. Lokales Tracing ist explizit per `PW_TRACE=1` opt-in (`on-first-retry`).
- **Video ist global deaktiviert** (`video: 'off'`).
