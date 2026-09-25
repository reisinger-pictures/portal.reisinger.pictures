# 28 — E2E-/Test-Image `portal-e2e` (CI)

**Status:** Implementiert (Dockerfile und Rebuild-Workflow vorhanden; der
E2E-Job in `ci.yml` nutzt das Image). Die hier dokumentierte Digest-Referenz
ist eine Konfigurationsangabe, kein Nachweis, dass das veröffentlichte GHCR-
Image frisch gebaut oder aktuell verfügbar ist; die Release-/Freshness-Prüfung
bleibt unter CR-INF-017/CR-INF-018 offen.

## Problem

Ein direkter Playwright-CI-Lauf lädt Chromium und die zugehörigen
Systemabhängigkeiten erneut herunter. Das ist pro Matrix-Eintrag unnötiger
Overhead, weil die Browser-Version an `@playwright/test` gebunden ist. Das
Test-Image bündelt nur diese Umgebung; Anwendungscode und Abhängigkeiten des
aktuellen Commits werden weiterhin im Job installiert.

## SOLL-Zustand

1. **`deployment/Dockerfile.e2e`** ist ein Derivat von
   `ghcr.io/reisinger-pictures/portal-base:8.5@sha256:d762d47c3434434ea5b0dd1ef213e0fa8f12c80b9c3eed314f6f859500a08736`
   (PHP-8.5-Prod-Runtime inklusive exiftool/ImageMagick/Extensions, Debian
   trixie) und enthält:
   - Composer (Dist-Binary via `COPY --from=composer:2.10.3@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b`)
   - Node.js 26 (offizielles Linux-Binary)
   - die in `frontend/package.json#packageManager` festgelegte pnpm-Version
   - Playwright-Chromium inklusive apt-Abhängigkeiten unter
     `PLAYWRIGHT_BROWSERS_PATH=/ms-playwright`; die Version wird als
     Build-Argument aus `frontend/package.json` extrahiert und muss mit
     `@playwright/test`/Lockfile konsistent sein.
2. **`.github/workflows/e2e-image.yml`** baut das Image bei Änderungen an
   `deployment/Dockerfile.e2e`, dem Workflow selbst oder
   `frontend/pnpm-lock.yaml` auf `main` sowie wöchentlich und manuell. Es
   publiziert `:latest` und einen versionsgebundenen Tag für Debugging/Rollback;
   `ci.yml` pinnt den konsumierten Image-Digest im Job, damit ein Run nicht
   unbemerkt von einem Tag-Wechsel abhängt.
   **Namensraum-Invariante:** `e2e-image.yml` und `base-image.yml` publizieren
   beide über `OWNER: ${{ github.repository_owner }}`, also in den
   **Org-Namespace `ghcr.io/reisinger-pictures/`**. Jeder Konsument
   (`Dockerfile.e2e`, `ci.yml`, `deployment/docker-compose.yml`, `scripts/`,
   Doku) muss denselben Namespace nennen; ein Verweis auf einen persönlichen
   `ghcr.io`-Namespace stillt den Rebuild aus, weil dort kein neues Image
   ankommt.
   **Rebuild-Reihenfolge:** Da `Dockerfile.e2e` per `FROM` auf den
   digest-gepinnten `portal-base`-Digest zeigt, muss `base-image.yml`
   **vorher** laufen. Ein `portal-e2e`-Build gegen einen veralteten
   `portal-base`-Digest erbt dessen Layer und ist damit kein gültiger Rebuild —
   der neue `portal-e2e`-Digest ist erst nach dem nächsten Lauf des
   `e2e-image.yml`-Workflows gültig. `workflow_dispatch` baut gegen den
   committeten Stand des Refs, nicht gegen den Working Tree.
3. Der **`e2e`-Job in `ci.yml`** läuft im Test-Image
   (`ghcr.io/reisinger-pictures/portal-e2e` mit dem in `ci.yml` gepinnten Digest):
   - `composer install` → `php artisan key:generate` →
     `php artisan migrate --force` → `php artisan db:seed --force` →
     `php artisan db:seed --class=E2ELocationSeeder --force` →
     `scout:flush`/`scout:sync-index-settings`/`scout:import` für `Location` →
     `php artisan serve --host=127.0.0.1 --port=8000 --no-reload`.
     Direkt nach dem Checkout wartet der Job mit
     `scripts/wait-for-meilisearch.sh` gegen
     `http://meilisearch:7700/health` auf den Service; der Check ist auf 60
     Sekunden begrenzt und liegt vor jeder Scout-Indexmutation. Dasselbe
     gemeinsame, begrenzte Script verwendet `scripts/e2e-up.sh` für den lokalen
     Compose-Service auf Port 7701. Die Location-Fixture ist versioniert,
     netzwerkfrei und ein expliziter
     Test-Setup-Schritt; `DatabaseSeeder` und der Produktionsstart importieren
     keine GeoNames-Daten. Der wöchentliche, gelockte Produktionsimport bleibt
     davon unabhängig.
   - Datenbank und Such-/Maildienste werden im Container-Modus über ihre
     Service-Namen erreicht (`mariadb`, `meilisearch`, `mailpit`); die
     `.env`-Overrides werden nur im E2E-Job vorgenommen. `backend/.env.ci`
     bleibt für den separaten Backend-/PHPUnit-Job unverändert.
   - Auch der Backend-/PHPUnit-Job ruft nach dem Checkout dasselbe
     `scripts/wait-for-meilisearch.sh` gegen den auf Port 7701 geöffneten
     Service auf. Dadurch beginnen die Live-Scout-Tests nicht vor verfügbarer
     Search-Engine und nutzen denselben begrenzten 60-Sekunden-Contract.
   - `MAILPIT_API_URL=http://mailpit:8025/api/v1` wird als Job-Env gesetzt;
     lokale E2E-Läufe verwenden den Default `localhost:8025` aus
     `scripts/e2e-up.sh`.
   - `npx playwright install chromium` bleibt ein No-Op-Fallback für eine
     kurzzeitige Versionabweichung zwischen Playwright-Lockfile und Image. Es
     wird nicht mit `--with-deps` aufgerufen, weil die Systemabhängigkeiten im
     Image gebacken sind.

## Aktuelle Shard-Strategie

`strategy.matrix.include` in `ci.yml` enthält aktuell sieben parallele
Matrix-Einträge: drei Desktop-, drei Mobile- und einen dedizierten seriellen
Eintrag.

| Matrix-Eintrag | Projekt | Filter/Args | Workers |
|---|---|---|---:|
| Desktop (1/3) | Desktop Chrome | `--grep-invert "projects-board\|production-board\|project-clear-fields\|brand-settings\|billing-details" --shard=1/3` | 2 |
| Desktop (2/3) | Desktop Chrome | `--grep-invert "projects-board\|production-board\|project-clear-fields\|brand-settings\|billing-details" --shard=2/3` | 1 |
| Desktop (3/3) | Desktop Chrome | `--grep-invert "projects-board\|production-board\|project-clear-fields\|brand-settings\|billing-details" --shard=3/3` | 2 |
| Mobile (1/3) | Mobile Chrome | `--grep-invert "projects-board\|production-board\|project-clear-fields\|brand-settings\|billing-details" --shard=1/3` | 2 |
| Mobile (2/3) | Mobile Chrome | `--grep-invert "projects-board\|production-board\|project-clear-fields\|brand-settings\|billing-details" --shard=2/3` | 1 |
| Mobile (3/3) | Mobile Chrome | `--grep-invert "projects-board\|production-board\|project-clear-fields\|brand-settings\|billing-details" --shard=3/3` | 2 |
| serial (isolated board/settings suites) | beide Projekte | `--grep "projects-board\|production-board\|project-clear-fields\|brand-settings\|billing-details"` | 1 |

`--project` begrenzt die ersten sechs Einträge auf die jeweilige Browser-
Projektkonfiguration. Der serielle Eintrag läuft ohne `--project`, damit die
ausgewählten Specs in Desktop und Mobile angeboten werden; explizite
Projekt-Skips (z. B. der Mobile-Skip in `brand-settings`) bleiben maßgeblich.
Die Auswahl umfasst die beiden Board-Suites, `project-clear-fields`,
`brand-settings` und `billing-details`. Die ersten vier Suites verwenden
Playwright-Serial-Modus; `billing-details` wird wegen der globalen Settings
ebenfalls isoliert. Das ist eine Testplanungs-Ausnahme, keine Erlaubnis für
gemeinsame Fixtures.

Die früheren Zeit- und Shard-Messungen sind historisch und keine aktuelle
Garantie. Der Playwright-Global-Budget beträgt 25 Minuten (`1500000` ms). Im
abgeschlossenen CI-Run `36035927250` brauchte der langsamste parallele Shard
17m09s und erreichte damit den bisherigen 900000-ms-Cap; 26 Tests blieben
nicht ausgeführt. Der 25-Minuten-Budget lässt damit rund 7m51s Puffer. Nach einer Matrix-
Änderung müssen die tatsächlichen Entry-Dauern gemessen und Matrix, Image-Doku
und E2E-Strategie gemeinsam aktualisiert werden.

## Invarianten (nicht regredieren)

- **Nur die Umgebung einbacken** — niemals App-Code, `node_modules/` oder
  `vendor/`. Tests laufen gegen den aktuellen Commit; Dependencies werden pro
  Commit installiert.
- Die Browser-Version muss zu `@playwright/test` passen. Der
  `frontend/pnpm-lock.yaml`-Trigger in `e2e-image.yml` erzwingt den
  Image-Rebuild bei Dependabot-Bumps.
- Im Container-Modus werden Daten-Dienste über Service-Namen erreicht; die
  `127.0.0.1`-Umleitungen und Env-Overrides gehören nur zum separaten
  PHPUnit-Job bzw. zum lokalen Setup.
- **Sicherheitsgrenze:** CI verwendet den Playwright-List-Reporter und lädt
  weder HTML-Reports noch `test-results`, Traces, Screenshots oder Videos hoch.
  Solche Dateien können Storage-State, Cookies, Request-Daten oder PII
  enthalten; lokale Reports bleiben auf dem Entwicklerrechner.

## Fallback (nur falls Container-Modus-Probleme auftreten)

Für eine kontrollierte Rückkehr auf einen Runner-Host kann der E2E-Job
alternative Host-Semantik und den im Image enthaltenen Browser-cache nutzen:

```bash
PORTAL_E2E_IMAGE='ghcr.io/reisinger-pictures/portal-e2e@sha256:<digest-from-ci.yml>'
docker create --name pw-cache "$PORTAL_E2E_IMAGE"
docker cp pw-cache:/ms-playwright "$HOME/ms-playwright"
docker rm pw-cache
echo "PLAYWRIGHT_BROWSERS_PATH=$HOME/ms-playwright" >> "$GITHUB_ENV"
```

Ein solcher Fallback ist eine Betriebs-/Workflow-Änderung und muss vor dem
Einsatz als solche verifiziert werden; er darf die im Image gebackene
Umgebung oder die aktuelle Matrix nicht stillschweigend verändern.
