# Skills-Marker

agents-skills-consumed: 787670a
geprüft am: 2026-10-19

> Stand: geprüft bis `787670a` (= HEAD des Skills-Repos zum Prüfzeitpunkt, 2026-10-19;
> vollständig `787670a8fc3d22a5ad114bcfd11fab8fb6e9f083`).
> Range `6629ad2..787670a`: 37 Commits (33 davon mit `.agents/`-Bezug), 21 berührte Skill-Pfade — 5 neue Skills
> (`laravel`, `react-i18n`, `security-review`, `web-audit`, `update-models`), 3 entfernte
> (`model-updater`, `update-opencode-models`, `vision-agents`), 12 geänderte und 1
> Pfadumbenennung (`models` → `update-models`).
> **Der Working Tree des Skills-Repos war zum Prüfzeitpunkt nicht sauber:** uncommittete
> Änderungen an `update-models/SKILL.md` und `update-models/references/registry.md`
> (maschinenweit, ohne Projektbezug). Sie gehörten zu keinem Commit und waren deshalb **nicht**
> Teil der geprüften Range; sie sind inzwischen als `219171c` committet und werden vom nächsten
> Fortschreiben abgedeckt.
> **Projektstand der Anker:** gemessen am Vorgänger `d8ef650`, seitdem unverändert (Beleg:
> `git diff --stat d8ef650..HEAD -- AGENTS.md backend/ deployment/ features/ frontend/
> .github/workflows/` → ohne Ausgabe). Der **Anker-Audit** unten misst die Anker-Gruppen des
> Vorgänger-Markers gegen dessen Audit-Basis `344604e` (der Vor-Pull-Baum `ee8523f` lag
> 78 Commits hinter `344604e`); die Korrekturziele sind gegen `d8ef650` nachgezogen.
> Regel: `skills-marker/SKILL.md` → „Bezugsrahmen-Regel".
> Format und Ablauf: Skill `skills-marker` im Skills-Repo.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Setup (globale `opencode.jsonc`, MCP, `skills`-Array, Slash-Command-Templates); das Projekt definiert kein eigenes Agent-Setup — trifft nicht zu |
| `build-verify` | ja | `AGENTS.md` §6 (Z. 47): der gemeinsame pull/verify/commit/push-Prozess ist im Skill `build-verify` definiert, repo-spezifische Kommandos bleiben in dieser Datei | — |
| `codegraph-project-setup` | ja | `AGENTS.md` §11 (Z. 89-92): `.codegraph/` optional und maschinenlokal, `.githooks/pre-commit` fails open, kein Commit-Gate | Lokaler Index ist optional, kein Commit-Gate |
| `docker-test-image` | ja | `deployment/Dockerfile.e2e` (`ARG PLAYWRIGHT_VERSION` Z. 34, Browser-Install Z. 73-74), `.github/workflows/e2e-image.yml` (Versionsextraktion Z. 47) | — (die Lockfile-Regel des Skills — Browserversion aus dem Lockfile — ist am 2026-10-21 umgesetzt: `db13b2c`; die Board-Position 6 ist damit geschlossen und aus dem Board entfernt, `23ca866`) |
| `ghcr-visibility` | ja | `.github/workflows/ci.yml` GHCR-Voraussetzung (Z. 52-61) + `container:`-Image (Z. 392) | — |
| `github-ci-filters` | ja | `.github/workflows/ci.yml` `paths:` unter `push` (Z. 31) und `pull_request` (Z. 39) — D-23 | — |
| `laravel` | ja | `backend/AGENTS.md` (Commands Z. 7 mit Pint-Gate Z. 20, Database Setup Policy Z. 22, Migration Policy Z. 32, Parallel Testing Z. 39), `backend/phpunit.xml` (Z. 30-54, hermetische Baseline), `backend/app/Http/Requests/` (22 FormRequests), `backend/app/Policies/` | Die DB-Policy (Produktion `mariadb`, Tests SQLite `:memory:`) weicht vom DB-Standard des Skills (PostgreSQL) ab, und die Abweichung ist **nirgends als Entscheidung festgehalten** → Board „SKILLS-STAND (2026-10-19)", Position 8 |
| `node-deps` | ja | `frontend/package.json` Z. 72 (`"@playwright/test": "^1.64.1"`), `frontend/pnpm-lock.yaml` Z. 768 (`@playwright/test@1.64.1`) | — (die Named-Locks-Position ist am 2026-10-18 geschlossen: Bump auf 1.64.1, committet `e1ed87a`) |
| `permissions` | nein | | Maschinen-Sache (globales `permissions`-Array, V2-Naming); das Projekt hat kein `.opencode/` — die Secret-Regel steht in `features/owner-decisions.md` §D-4 (Z. 19) — trifft nicht zu |
| `playwright-parallel` | ja | `.github/workflows/ci.yml` E2E-Matrix (Z. 425-431), `frontend/playwright.config.ts` (`fullyParallel: true` Z. 19, `workers: isCi ? 4 : 8` Z. 22) | — (die Matrix-Position ist am 2026-10-18 geschlossen) |
| `react-i18n` | ja | `frontend/lingui.config.ts`, `frontend/src/main.tsx` Z. 5 (`import './logic/I18nProvider'` als erster Import), `frontend/scripts/check-i18n.mjs` (extract→compile-Vergleich + AST-Pass), `frontend/AGENTS.md` §„Lingui / i18n" (Z. 106) | — |
| `security-review` | ja | `features/security/2026-10-18-review-closure.md` (14 Befunde SEC-01…SEC-14, alle geschlossen und unabhängig verifiziert, jeder mit Regressionstest), `features/security/accepted-risks.md` | — |
| `skills-marker` | ja | `AGENTS.skills.md` (diese Datei) | — |
| `tailscale-serve` | nein | | Kein Dev-Server-Expose im Projekt definiert — trifft nicht zu |
| `ui-review` | ja | `frontend/tests/screenshots/` (`ui-review.config.ts` Z. 9-10 — Routen-Manifest, zitiert „ui-review skill references/harness.md §5"; dazu `harness.ts`, `ui-screenshots.spec.ts`, `seeds.ts`), `AGENTS.todo.md` §„UI-Review 2026-09-26" | Die Rollenänderung derselben Range traf `AGENTS.md` §6 (verlangte den abgeschafften `vision`-Subagenten); am 2026-10-20 umgestellt auf den verbleibenden Weg (`1818909`, Ergänzung `b86c15c`) — die Board-Position 7 ist an diesem Tag geschlossen und aus dem Board entfernt (Beleg im Commit) |
| `update-models` | nein | | Modell-Auswahl ist Maschinen-/Account-Sache; das Projekt definiert keine Modelle — trifft nicht zu (ersetzt die früheren Skills `model-updater` + `update-opencode-models`) |
| `web-audit` | nein | | Kein Live-Site-Audit (Crawl → Bild-Download → Vision-Fan-out → False-Positive-Gate → ZIP-Archiv) im Projekt definiert; die Sitemaps (`backend/routes/api.php:127-128`) sind Produkt-Feature — trifft nicht zu |

## Offen aus dem Bereich `6629ad2..787670a`

_Range: 37 Commits, davon 33 mit `.agents/`-Bezug. Jede berührte Skill-Datei gegen **dieses** Projekt geprüft; die
Entscheidung steht je Zeile. Der Bereich umfasst auch die Regeldatei
`.agents/rules/build-verify.md` (4 Commits) — sie ist die Kernregel, auf die
`AGENTS.md` zeigt._

**Neu**

- `laravel` — **ja.** Das Projekt *ist* ein Laravel-Backend (`laravel/framework` ^13, PHP ^8.5) und wendet die Konventionen bereits an: hermetische `phpunit.xml`-Baseline (APP_ENV/BCRYPT_ROUNDS/CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION/DB `:memory:`), Seed- und Migrations-Policy in `backend/AGENTS.md`, Pint als Formatter **und** Auto-Fix mit CI-Gate, `paratest` ohne Worker-DB-Setup, 22 `FormRequest`-Klassen, `app/Policies/`, JWT im httpOnly-Cookie, Scout + Meilisearch. **Abweichung:** der DB-Standard des Skills (PostgreSQL, MySQL deprecated) weicht von der projekteigenen DB-Policy ab (Produktion `mariadb`, Tests SQLite `:memory:`) — **der Zustand ist dokumentiert, die Entscheidung nicht**: `backend/config/database.php:20` (Default `mariadb`), `features/infrastructure/29-production-operations-runbook.md:48` („currently `mariadb`"), `backend/AGENTS.md:28` (lokal SQLite) und `backend/AGENTS.md:41` (Test-DB SQLite `:memory:`) beschreiben den Ist-Zustand, aber weder `features/owner-decisions.md` (D-1…D-48) noch `features/tech/07-architectural-decisions.md` (AD-1…AD-10) entscheidet über die DB-Engine (MariaDB fällt dort nur als Messumgebung im D-40-Beweis vor) → das ist eine **offene Lücke, keine beschlossene Abweichung** (offene Position: Board „SKILLS-STAND (2026-10-19)", Position 8). Die Datei-Test-Regel des Skills (`laravel/SKILL.md:41`: „fake every local disk in `setUp()`") ist ebenfalls nicht eingehalten: `Storage::fake()` deckt 64 Verwendungen ab (photos 32, local 21, ftp_inbox 10, public 1), aber **keine Testdatei faket alle vier lokalen Disks** (`photos`, `ftp_inbox`, `local`, `public`) — 40 Testdateien faken genau eine, 10 genau zwei; der eigene Helfer `UsesIsolatedTempDirectory` (5 Testdateien plus Trait) betrifft `filesystems.temp_dir`, nicht das Faking. (Gemessen am Vorgänger-Stand `d8ef650`, seitdem unverändert — `git diff --stat d8ef650..HEAD -- backend/ features/` → ohne Ausgabe.) `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID).
- `react-i18n` — **ja.** Das Muster des Skills ist aus genau diesem `frontend/` abgeleitet und vollständig vorhanden: `lingui.config.ts` (de/de, `format-po` ohne Zeilennummern), `src/logic/I18nProvider.tsx` als erster Import in `main.tsx`, `@lingui/core/macro` + `@lingui/react/macro` (die für Lingui v6 korrekten Pfade), `eslint-plugin-lingui`, `lingui:extract`/`lingui:compile`/`check:i18n` und das Gate in `prebuild`. Das Gate macht genau, was der Skill verlangt: extract → msgids gegen die kompilierten Werte vergleichen → stale catalog oder roher Text = Fehler. `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID).
- `security-review` — **ja.** Der Ablauf des Skills ist in diesem Projekt **gelaufen und dokumentiert**: `features/security/2026-10-18-review-closure.md` hält die 14 Befunde SEC-01…SEC-14 mit Status, Commit, Regressionstest und Verifikationsrunde fest — genau das Muster des Skills (find → in Wellen schließen → mit Test und Register-Eintrag pinnen). **Population heute gemessen** (Stand `d8ef650`, 2026-10-21): `cd backend && php artisan route:list --json` → **192 Routen, 153 mit `auth:`, 39 ohne** — der Referenzlauf des Skills nennt 189/150/39, die Gesamtzahl ist dieselbe, eine Route ist inzwischen hinter `auth:` (die Zahlen beschreiben den Lauf, nicht eine Regel). `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID).
- `web-audit` — **nein.** Kein Live-Site-Audit mit Crawl, Bild-Download, Vision-Fan-out, False-Positive-Gate und ZIP-Archiv ist hier definiert; die öffentlichen Sitemaps sind Produkt-Feature, kein Audit-Scope. `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID).
- `update-models` — **nein.** Modell-Auswahl ist Maschinen-/Account-Sache.

**Geändert**

- `agent-config` — **nein.** Maschinen-Setup (globale Config, MCP, `skills`-Array, neue Slash-Command-Templates unter `.agents/commands/`). Die §6-Commit-Policy dieser Range gilt dem Skills-Repo selbst, nicht diesem Projekt. `980514a` ergänzte den §7-Index der 11 verdeckten Skills (ID + Auslöser + Command) und die Zeile `~/.config/opencode/commands/` (ein Slash-Command pro verdecktem Skill).
- `build-verify` — **ja** (bisher `nein`). Die Range macht daraus eine Verhaltensänderung: die Regeldatei `.agents/rules/build-verify.md` änderte sich viermal (`1a8a9a5`: der „always on"-Anspruch der Regel fällt — sie ist nicht über die `instructions`-Verdrahtung geladen, sondern das Minimum, auf das die Projekte zeigen; `86d677e`: Rule 3 prüft die *Substanz*, nicht die Gates; `aa4e009`: K2 „reine Mechanik ist Text", K4 „begleitende Doku reitet mit"; `18623c5`: separate Commits als Default). Die **frische Session je Runde** stammt dagegen aus `424a7c3` und steht in der **Skill-Datei** `.agents/skills/build-verify/SKILL.md`, nicht in der Regeldatei. Das Projekt adoptiert diesen Flow ausdrücklich — `AGENTS.md` §6 (Z. 47) verweist auf den Skill und hält nur repo-spezifische Kommandos fest. Der optionale Meilenstein-Review bleibt offen, weil das Projekt kein `N` und keinen Release-Takt festlegt — kein Regelbedarf, keine Position. `980514a` ergänzte den CI-Routing-Block (welcher verdeckte Skill bei welchem CI-Symptom geladen wird); der Skill selbst bleibt beworben.
- `codegraph-project-setup` — **ja.** Neu ist der Scope-Guard („markdown-only repos gain nothing") — dieses Projekt ist ein Code-Repo mit `.codegraph/` und `.githooks/pre-commit`, der Guard greift also nicht. Die Vision-Eskalationsregel wurde auf „selbst neu lesen" umgeschrieben (siehe `vision-agents`). `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID).
- `docker-test-image` — **ja.** Die Description wurde gekürzt, die Substanz unverändert; `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID). Der Skill ist aus genau diesem `deployment/Dockerfile.e2e` abgeleitet (Environment-only-Invariante, `ARG`/`USER root`/`ENV PLAYWRIGHT_BROWSERS_PATH`/`&& ls -1 /ms-playwright`-Postcondition, Rebuild-Trigger mit Lockfile in `paths`, wöchentlicher `schedule:`). **Board-Position 6 ist am 2026-10-21 geschlossen und aus dem Board entfernt** (`23ca866`): die Lockfile-Regel des Skills ist jetzt umgesetzt — die Versionsextraktion liest das aufgelöste Lockfile statt des Manifests (`db13b2c`, mit Fail-closed-Gate).
- `ghcr-visibility` — **ja.** Description gekürzt; `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID) — die Regeln selbst blieben unverändert. Die Board-Position 4 („GHCR-Blocker") ist am 2026-10-15 geschlossen und aus dem Board entfernt worden; die Voraussetzung ist im Workflow kommentiert und am 2026-10-15 neu gemessen (beide Pakete `public`). Der alte Verweis auf „Position 4" im Marker war dadurch ein toter Zeiger.
- `github-ci-filters` — **ja.** Description gekürzt; die Filter stehen unverändert (`paths` mit `!`-Rücknahme, D-23).
- `node-deps` — **ja.** Description gekürzt; die Range-Regel ist im `frontend/package.json` angewendet. `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID).
- `permissions` — **nein.** V2-Naming (`permissions` als geordnetes Array, `shell`/`subagent` als Aktionen) und die Kommando-Korrektur `opencode2` → `opencode` betreffen die Maschinen-Config; das Projekt hat kein `.opencode/`. Die Kommando-Korrektur stammt aus `980514a`; der Commit setzte dort zugleich den Metadaten-Block `metadata.opencode/autoinvoke: false` (Ladung nur per exakter ID).
- `playwright-parallel` — **ja.** Description gekürzt; Matrix-Muster und Worker-Zahl stehen unverändert. `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID).
- `skills-marker` — **ja.** Die Range verlangt stabile Referenzen (Abschnitt/Überschrift/Suchstring statt reiner Zeilennummer) und das Re-Verifizieren **aller** Anker beim Fortschreiben — beides ist in diesem Durchgang angewendet. `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID).
- `tailscale-serve` — **nein.** Description gekürzt; im Projekt ist kein Dev-Server-Expose definiert. `980514a` fügte den Metadaten-Block `metadata.opencode/autoinvoke: false` hinzu (Ladung nur per exakter ID).
- `ui-review` — **ja** (bisher `nein`). Das Projekt hat ein eigenes Screenshot-Harness mit Routen-Manifest (`frontend/tests/screenshots/ui-review.config.ts`), das im Kommentar ausdrücklich „ui-review skill references/harness.md §5" zitiert, und hat die Schleife am 2026-09-26 angewendet. Die Eskalationsregel der Range („hard calls: re-read the image yourself") ist die projektseitige Folge aus dem `vision-agents`-Entfernen und traf `AGENTS.md` §6 → **Board-Position 7** (am 2026-10-20 geschlossen und aus dem Board entfernt, `1818909`: §6 nennt jetzt „visual review is done by the agent itself (method and checklist: the `ui-review` skill) — every configured model is vision-capable, so there is no vision subagent in the loop; on hard visual calls re-read the image yourself instead of guessing"). `980514a` ergänzte den Verweis auf den verdeckten `web-audit`-Skill (Live-Audit statt App-Screenshot) und den Model-Skill-Verweis (in dieser Range `update-models`).

**Entfernt bzw. umbenannt**

- `vision-agents` — **entfernt in `dccdd40`** („single default model, drop custom vision subagent"). Der eigene Vision-Subagent ist gestrichen, die globale Config führt nur `plan`/`general`/`build`/`explore`. Die Zeile verschwindet mit diesem Fortschreiben; die offene Frage — ob das Projekt den Inhalt selbst braucht — ist als **Board-Position 7** beantwortet (ja: `AGENTS.md` §6 nannte den Subagenten; am 2026-10-20 auf „visual review is done by the agent itself (method and checklist: the `ui-review` skill) — every configured model is vision-capable, so there is no vision subagent in the loop; on hard visual calls re-read the image yourself instead of guessing" umgestellt (`1818909`, Satzergänzung `b86c15c`), Position danach geschlossen und aus dem Board entfernt (`1818909`).
- `model-updater` — **entfernt in `1c51237`** (mit `update-opencode-models` in `models` zusammengeführt).
- `update-opencode-models` — **entfernt in `1c51237`** (mit `model-updater` in `models` zusammengeführt).
- `models` — **neu in `1a096ba`, umbenannt in `update-models` (`fc28ea3`)**; der Slash-Command `/update-models` kam dazu. Die Netto-Wirkung ist eine Zeile: `update-models`, weiterhin maschinenweit → **nein**.

**Nicht-Skill-Änderungen der Range (ohne Projektbezug)**

- `.agents/commands/{security-review,update-models}.md` (versionierte Command-Templates), `.agents/rules/build-verify.md`, `AGENTS.md`/`README.md`/`AGENTS.todo.md` des Skills-Repos, `.githooks/pre-commit` (entfernt, das Skills-Repo ist markdown-only), `.github/skill-budget.json` + `.github/workflows/skill-budget.yml` + `scripts/skill-budget.mjs` (Budget für *beworbene* Skills — Kontextkosten des Skills-Repos, nicht des Projekts).

**Anker-Audit (Basis `344604e` — gezählt am 2026-10-19, nachgemessen am 2026-10-21)**

Alle neun Anker-Gruppen des Vorgänger-Markers — der Stand `344604e`, den der Marker als Audit-Basis nennt — am 2026-10-21 gegen diesen Stand gelesen: **8 von 9 Zeilenanker-Gruppen** waren dort falsch — **4 × Root-`AGENTS.md` umgebaut** (die Datei ist am 2026-10-18/19 auf 104 Zeilen umgebaut und **englisch** geworden — die Abschnittsnummern haben sich verschoben, D-4 liegt jetzt in `features/owner-decisions.md`; die alten `AGENTS.md`-Anker zeigen ins Leere) und **4 × Zieldatei gewachsen** (`ci.yml`, `frontend/package.json`, `frontend/pnpm-lock.yaml`, `frontend/playwright.config.ts` — der Anker zeigte noch auf die Datei, aber auf den falschen Inhalt). Die „5 von 9" des Audit-Texts nennt fünf Gruppen **und** zugleich den Anker-Stand — beides trifft an keinem Stand zusammen: wo alle fünf genannten Gruppen falsch waren (`e1ed87a`, `91ae163`), waren es 6 von 9; wo genau fünf falsch waren, war `node-deps` noch in Ordnung und `github-ci-filters` dafür falsch. Die acht oben sind am Basiscommit `344604e` gemessen, nicht aus dem Audit-Text übernommen. Fünf der acht nennt der Audit-Text:

- `ui-review` — `AGENTS.md:714-715` zeigte am Vor-Pull-Stand `ee8523f` (773 Zeilen) auf die D-23-Lücke („80 der 113 Markdown-Dateien"), nicht auf den UI-Review-Hinweis; am Basiscommit `344604e` (104 Zeilen) zeigt der Anker ins Leere. **Korrigiert** auf `AGENTS.md` §6 für die Rollenfrage und `frontend/tests/screenshots/` für die Anwendung.
- `permissions` — `AGENTS.md:457-461` überlappte am Vor-Pull-Stand `ee8523f` D-3 statt D-4 zu benennen; am Basiscommit `344604e` (104 Zeilen) zeigt der Anker ins Leere. **Korrigiert** auf `features/owner-decisions.md` §D-4 (Z. 19).
- `ghcr-visibility` — `.github/workflows/ci.yml:335` war eine Kommentarzeile; das `container:`-Image steht jetzt in Z. 392 (Stand `d8ef650`). **Korrigiert**.
- `playwright-parallel` — `frontend/playwright.config.ts:30-31` belegte die *Budgets* (`timeout`, `globalTimeout`), nicht die Worker-Zahl; die Matrix liegt in `ci.yml` jetzt in Z. 425-431, nicht 374-380. **Korrigiert** auf `fullyParallel`/`workers` (Z. 19/22) und die Matrix (Z. 425-431).
- `node-deps` — `frontend/package.json:71` und `frontend/pnpm-lock.yaml:767` zeigten nach dem Playwright-Bump (`e1ed87a`) auf die falschen Zeilen. **Korrigiert** auf Z. 72 und Z. 768.
Drei weitere Gruppen waren ebenso falsch und wurden **ohne Befund umgepunktet** — sie gehören zu den acht, stehen aber nicht in der Aufzählung oben:

- `build-verify` — der alte Anker `AGENTS.md:109-122` zeigte nach dem Umbau der Datei ins Leere; **umgepunktet** auf `AGENTS.md` §6 (Z. 47).
- `codegraph-project-setup` — `AGENTS.md:266-273` zeigte ins Leere; **umgepunktet** auf `AGENTS.md` §11 (Z. 89-92).
- `github-ci-filters` — `ci.yml:24` war nach dem Wachstum der Datei eine Kommentarzeile statt des `paths:`-Filters; **umgepunktet** auf Z. 31/39 (`push` und `pull_request`).

- Unverändert bestätigt — und deshalb *nicht* umgepunktet — ist nur `docker-test-image`: `deployment/Dockerfile.e2e` (`ARG PLAYWRIGHT_VERSION`, Browser-Install) und `.github/workflows/e2e-image.yml` (Versionsextraktion) — am Basiscommit `344604e` noch Z. 30/69-70 bzw. `:41`, am Stand `d8ef650` Z. 34/73-74 bzw. Z. 47 (die Regel selbst ist inzwischen umgesetzt, `db13b2c`).

## Fortschreiben

1. `agents-skills-consumed` ablesen → `<alt>`.
2. **Erst pullen**, dann im Skills-Repo die Range bilden:
   `git -C <skills-repo> log --oneline <alt>..HEAD`
   `git -C <skills-repo> log --name-only --format= <alt>..HEAD -- .agents/skills | sort -u`
   (Ein lokaler Klon, der nicht gepullt hat, prüft eine Range, die es so nicht gibt — und der
   eigene Projekt-Stand muss ebenfalls aktuell sein, sonst sind die Anker gegen einen
   veralteten Baum gemessen.)
3. Jeden betroffenen Skill gegen **dieses** Projekt prüfen. Trifft eine Änderung zu, wenn
   sie eine Pflicht, Schwelle, Ausnahme, Konvention oder ein Kommando betrifft, das
   **hier** gilt:
   - trifft zu und ist drin → `angewendet: ja` + stabile Referenz (Abschnitt/Überschrift
     oder Suchstring; eine Zeilennummer nur als Lesehilfe)
   - trifft zu, ist nicht drin → offene Position: Eintrag in `AGENTS.todo.md` anlegen
   - trifft nicht zu (das Projekt hat das nicht) → `angewendet: nein` + kurzer Grund
4. Offene Positionen nach `AGENTS.todo.md`; **alle** bestehenden Anker re-verifizieren —
   Advancing ist kein Updating, der Marker akkumuliert, und Akkumulation verrottet.
5. Entfernte Skills nicht stillschweigend streichen: im Bereichs-Abschnitt
   „`<skill>` — entfernt in `<sha>`" notieren, dann verschwindet die Zeile mit dem
   Fortschreiben.
6. Erst wenn für **jeden** Skill aus der Range eine Zeile steht:
   `agents-skills-consumed: $(git -C <skills-repo> rev-parse --short HEAD)`,
   `geprüft am:` auf heute, Abschnitte ersetzen.
7. Falls Regeln oder Skills betroffen waren: die eingebetteten Regeln in der
   globalen `~/.config/opencode/AGENTS.md` gegen ihre Quelle in `.agents/rules/*` prüfen
   und bei geänderter **Pflicht** sinngemäß nachziehen, dann
   `touch ~/.config/opencode/opencode.jsonc`.
