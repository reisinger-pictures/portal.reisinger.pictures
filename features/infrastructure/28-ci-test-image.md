# 28 — E2E-/Test-Image `portal-e2e` (CI)

**Status:** Implementiert (Dockerfile und Rebuild-Workflow vorhanden; der
E2E-Job in `ci.yml` nutzt das Image). Die hier dokumentierte Tag-Referenz
(`portal-e2e:1.64.1`, nie `latest`) ist eine Konfigurationsangabe, kein
Nachweis, dass das veröffentlichte GHCR-Image frisch gebaut oder aktuell
verfügbar ist. Das Alter des Artefakts hinter dem Tag wird maschinell geprüft
(`tests/infrastructure/verify-image-freshness.sh`, INFRA-10); die
Release-/Freshness-Prüfung bleibt unter CR-INF-017/CR-INF-018 offen.

## Problem

Ein direkter Playwright-CI-Lauf lädt Chromium und die zugehörigen
Systemabhängigkeiten erneut herunter. Das ist pro Matrix-Eintrag unnötiger
Overhead, weil die Browser-Version an `@playwright/test` gebunden ist. Das
Test-Image bündelt nur diese Umgebung; Anwendungscode und Abhängigkeiten des
aktuellen Commits werden weiterhin im Job installiert.

## SOLL-Zustand

1. **`deployment/Dockerfile.e2e`** ist ein Derivat von
   `ghcr.io/reisinger-pictures/portal-base:8.5`
   (PHP-8.5-Prod-Runtime inklusive exiftool/ImageMagick/Extensions, Debian
   trixie) und enthält:
   - Composer (Dist-Binary via `COPY --from=composer:2.10.3`)
   - Node.js 26 (offizielles Linux-Binary)
   - die in `frontend/package.json#packageManager` festgelegte pnpm-Version
   - Playwright-Chromium inklusive apt-Abhängigkeiten unter
     `PLAYWRIGHT_BROWSERS_PATH=/ms-playwright`; die Version wird als
     Build-Argument aus der **aufgelösten Lockfile-Version** extrahiert
     (`e2e-image.yml` liest `frontend/pnpm-lock.yaml`, nicht die Range-Untergrenze
     aus dem Manifest — siehe „Versionsbindung — vier Stellen, eine Zahl"). Die
     vier Stellen, die die Zahl tragen, stehen dort.
2. **`.github/workflows/e2e-image.yml`** baut das Image bei Änderungen an
   `deployment/Dockerfile.e2e`, dem Workflow selbst oder
   `frontend/pnpm-lock.yaml` auf `main` sowie wöchentlich und manuell. Es
   publiziert `:latest` und einen versionsgebundenen Tag (`portal-e2e:1.64.1`,
   aus der aufgelösten Lockfile-Version); `ci.yml` pinnt im `container:`-Feld genau
   diesen versionsgebundenen Tag, nie `latest`. Gepinnt wird nicht mehr über
   `@sha256`: ein Digest-Pin friert ein Image auf einen Build ein und brauchte
   für jeden Patch-Release eine manuelle Aktualisierung, sodass ein Base-Image-
   Security-Fix auf dem Tag blieb, während der Job weiter das alte Artefakt
   bootete (siehe `deployment/image-pin-freshness.md`, INFRA-10).
   **Namensraum-Invariante:** `e2e-image.yml` und `base-image.yml` publizieren
   beide über `OWNER: ${{ github.repository_owner }}`, also in den
   **Org-Namespace `ghcr.io/reisinger-pictures/`**. Jeder Konsument
   (`Dockerfile.e2e`, `ci.yml`, `deployment/docker-compose.yml`, `scripts/`,
   Doku) muss denselben Namespace nennen; ein Verweis auf einen persönlichen
   `ghcr.io`-Namespace stillt den Rebuild aus, weil dort kein neues Image
   ankommt.
   **Rebuild-Reihenfolge:** Da `Dockerfile.e2e` per `FROM` auf den
   `portal-base:8.5`-Tag zeigt, baut jeder `portal-e2e`-Lauf gegen das
   Artefakt, das dieser Tag *zu diesem Zeitpunkt* auflöst. Soll das neue
   `portal-e2e`-Artefakt einen neuen `portal-base` enthalten, muss
   `base-image.yml` **vorher** laufen; ein Build gegen ein veraltetes
   `portal-base`-Artefakt erbt dessen Layer und ist damit kein gültiger
   Rebuild — ein neuer `portal-e2e`-Stand ist erst nach dem nächsten Lauf von
   `e2e-image.yml` gültig. Das ist seit dem Wechsel auf Tags nicht mehr am
   Pin-Wert ablesbar: `verify-image-freshness.sh` misst nur das `created` des
   publizierten `portal-e2e`-Artefakts, nicht dessen geerbte Base. Die
   Reihenfolge bleibt Betreiber-Verantwortung. `workflow_dispatch` baut gegen
   den committeten Stand des Refs, nicht gegen den Working Tree.
3. Der **`e2e`-Job in `ci.yml`** läuft im Test-Image
   (`ghcr.io/reisinger-pictures/portal-e2e:1.64.1`, der in `ci.yml` gepinnte
   versionsgebundene Tag):
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
     Image gebacken sind. **Dauerhaft** ist die Abweichung ausgeschlossen:
     `verify-playwright-version.sh` bricht rot, sobald Pin, `ARG`-Default und
     Lockfile auseinanderlaufen — der Fallback darf nur die kurze Lücke
     schließen, die ein bewusst nachgezogener Pin eröffnet, nicht einen stillen
     Dauerzustand tragen.

## Versionsbindung — vier Stellen, eine Zahl

Die Playwright-Version steht an **vier** Stellen in Code und CI, nicht an drei.
Nur wenn alle vier dieselbe Zahl nennen, fährt der Runner denselben Browser, den
das Image eingebacken hat:

| Stelle | Rolle |
|---|---|
| `frontend/pnpm-lock.yaml`, `importers → devDependencies."@playwright/test"` | die **aufgelöste** Version (`1.64.1`) — die Quelle |
| `frontend/package.json`, `devDependencies."@playwright/test"` | die **Range** (`^1.64.1`) — die Deklaration, kein Pin |
| `deployment/Dockerfile.e2e`, `ARG PLAYWRIGHT_VERSION=1.64.1` | Default für **lokale** Builds; CI überschreibt ihn per `build-args` |
| `.github/workflows/ci.yml`, `container.image` | der **Konsument** — der Teil, den man vergisst |

**Die Regel, die den Wert bestimmt (Stand 2026-10-21):** die aufgelöste
Lockfile-Version ist die Quelle. Der Image-Workflow liest sie aus dem Lockfile,
nicht die Untergrenze der Range aus dem Manifest:

```bash
grep -A2 -E "^[[:space:]]+'@playwright/test':[[:space:]]*$" frontend/pnpm-lock.yaml \
  | sed -n 's/^[[:space:]]*version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' | head -n1
# 1.64.1
```

**Warum die Range-Untergrenze die falsche Quelle war.** Dependabot (npm,
`/frontend`, **täglich**, `minor-and-patch` gruppiert) aktualisiert bei einer
Version **innerhalb** der deklarierten Range **nur das Lockfile** — belegt: von
sechs offenen Dependabot-Branches für `/frontend` änderten vier ausschließlich
`frontend/pnpm-lock.yaml`. Ein solcher Bump trifft `frontend/pnpm-lock.yaml` in
`paths` → der Rebuild läuft, extrahierte aber wieder die Range-Untergrenze und
backte **denselben** Browser unter **demselben** Tag neu ein. Der E2E-Lauf blieb
grün, weil `ci.yml:661-668` („Verify Playwright browsers (fallback install)") die
abweichende Browserversion pro Lauf nachlädt — **damit war die Zusage des
Test-Images still aufgehoben** („kein Browser-Download pro Run",
`deployment/Dockerfile.e2e:12-16`), ohne dass irgendwo ein Fehler erschien. Der
Versionssprung ist real: Chromium 1234 gehört zu Playwright 1.62.1, 1248 zu
1.64.1 (gemessen mit `npx --yes playwright@<version> install --dry-run chromium`).

**Die vier Stellen sind heute gekoppelt, aber nur drei davon sind abgeleitet.**
Der Image-Workflow leitet Build-Argument und publizierten Tag aus dem Lockfile
ab. Der `ARG`-Default im Dockerfile und der `container.image`-Pin in `ci.yml`
sind **Literale** — GitHub löst `container.image` auf, **bevor** die Schritte
laufen, eine Ableitung im Job wäre unmöglich. Sie stehen deshalb **unter
Vertrag**, nicht unter Ableitung: `tests/infrastructure/verify-playwright-version.sh`
prüft fail-closed, dass

- das Lockfile überhaupt eine auflösbare `@playwright/test`-Version trägt,
- die aufgelöste Version **innerhalb** der deklarierten Range liegt,
- der Image-Workflow die Version aus dem **Lockfile** (nicht aus dem Manifest)
  zieht,
- der `container.image`-Pin in `ci.yml` und der `ARG`-Default im Dockerfile
  dieselbe Zahl nennen wie das Lockfile.

Der Pin ist damit **Handarbeit mit Wächter**: ein Lockfile-only-Bump lässt den
Workflow das Image mit der neuen Browserversion unter dem **neuen** Tag
publizieren, und der Vertrag bricht **rot**, bis der Pin in `ci.yml` (und der
`ARG`-Default im Dockerfile) nachgezogen ist. Das ist die gewollte Reihenfolge:
der Bump der Browserversion soll eine **bewusste** Handlung sein, kein
stiller Nebeneffekt eines Dependabot-Branches.

`tests/infrastructure/verify-image-freshness.sh` liest den konsumierten
`portal-e2e`-Tag **ausschließlich aus `ci.yml`** (`resolve_pin`, fail-closed):
bleibt der Pin zurück, prüft der Frische-Gate ein altes Artefakt, während der
E2E-Job einen fremden Browser bootet.

### Warum diese Version: Named Locks

`lock:` existiert ab `@playwright/test >= 1.63.0` (Skill `playwright-parallel`);
Voraussetzung ist `fullyParallel: true` (in `frontend/playwright.config.ts`
gesetzt). Der Bump ist deshalb **Voraussetzung, nicht Kosmetik**: auf `1.62.1`
wird ein deklariertes `lock:` **still ignoriert** — kein Fehler, kein Hinweis,
die betroffenen Tests laufen weiter gleichzeitig. Ein Lock, das nichts tut, sieht
aus wie eine Konfiguration und ist keine; die Fehlerklasse ist „deklariert und
unwirksam", nicht „unbekannte Option".

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
Die Auswahl umfasst die beiden Board-Suites
(`tests/e2e/admin/boards/projects-board.spec.ts` und
`tests/e2e/photographer/production-board.spec.ts`), `project-clear-fields`,
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
- Die Browser-Version muss zu `@playwright/test` passen. Die vier Stellen, die
  die Zahl tragen, und die Regel für die Range-Untergrenze stehen unter
  „Versionsbindung — vier Stellen, eine Zahl". Der
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
PORTAL_E2E_IMAGE='ghcr.io/reisinger-pictures/portal-e2e:1.64.1'  # Tag aus ci.yml `container.image`
docker create --name pw-cache "$PORTAL_E2E_IMAGE"
docker cp pw-cache:/ms-playwright "$HOME/ms-playwright"
docker rm pw-cache
echo "PLAYWRIGHT_BROWSERS_PATH=$HOME/ms-playwright" >> "$GITHUB_ENV"
```

Ein solcher Fallback ist eine Betriebs-/Workflow-Änderung und muss vor dem
Einsatz als solche verifiziert werden; er darf die im Image gebackene
Umgebung oder die aktuelle Matrix nicht stillschweigend verändern.
