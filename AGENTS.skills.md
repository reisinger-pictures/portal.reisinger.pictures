# Skills-Marker

agents-skills-consumed: 787670a
geprüft am: 2026-10-19

> Stand: geprüft bis `787670a` (= HEAD des Skills-Repos zum Prüfzeitpunkt, 2026-10-19;
> vollständig `787670a8fc3d22a5ad114bcfd11fab8fb6e9f083`).
> Range `6629ad2..787670a`: 33 Commits, 21 berührte Skill-Pfade — 5 neue Skills
> (`laravel`, `react-i18n`, `security-review`, `web-audit`, `update-models`), 3 entfernte
> (`model-updater`, `update-opencode-models`, `vision-agents`), 12 geänderte und 1
> Pfadumbenennung (`models` → `update-models`).
> **Der Working Tree des Skills-Repos ist nicht sauber:** uncommittete Änderungen an
> `update-models/SKILL.md` und `update-models/references/registry.md` (maschinenweit, ohne
> Projektbezug). Sie gehören zu keinem Commit und sind deshalb **nicht** Teil der geprüften
> Range.
> Geprüft gegen **HEAD `344604e`** dieses Repos (der Stand war gegenüber dem Prüfbeginn
> 78 Commits hinter `origin/main` — vor der Prüfung gezogen).
> Format und Ablauf: Skill `skills-marker` im Skills-Repo.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Setup (globale `opencode.jsonc`, MCP, `skills`-Array, Slash-Command-Templates); das Projekt definiert kein eigenes Agent-Setup — trifft nicht zu |
| `build-verify` | ja | `AGENTS.md` §6 (Z. 46): der gemeinsame pull/verify/commit/push-Prozess ist im Skill `build-verify` definiert, repo-spezifische Kommandos bleiben in dieser Datei | — |
| `codegraph-project-setup` | ja | `AGENTS.md` §11 (Z. 88-91): `.codegraph/` optional und maschinenlokal, `.githooks/pre-commit` fails open, kein Commit-Gate | Lokaler Index ist optional, kein Commit-Gate |
| `docker-test-image` | ja | `deployment/Dockerfile.e2e` (`ARG PLAYWRIGHT_VERSION` Z. 30, Browser-Install Z. 69-70), `.github/workflows/e2e-image.yml` (Versionsextraktion Z. 41) | Die Lockfile-Regel des Skills (Browserversion aus dem Lockfile, nicht aus dem Manifest) ist nicht umgesetzt → Board „SKILLS-STAND", Position 6 |
| `ghcr-visibility` | ja | `.github/workflows/ci.yml` GHCR-Voraussetzung (Z. 50-59) + `container:`-Image (Z. 358) | — |
| `github-ci-filters` | ja | `.github/workflows/ci.yml` `paths:` unter `push` (Z. 31) und `pull_request` (Z. 38) — D-23 | — |
| `laravel` | ja | `backend/AGENTS.md` (Commands Z. 7 mit Pint-Gate Z. 20, Database Setup Policy Z. 22, Migration Policy Z. 31, Parallel Testing Z. 38), `backend/phpunit.xml` (Z. 30-54, hermetische Baseline), `backend/app/Http/Requests/` (22 FormRequests), `backend/app/Policies/` | — |
| `node-deps` | ja | `frontend/package.json` Z. 72 (`"@playwright/test": "^1.64.1"`), `frontend/pnpm-lock.yaml` Z. 768 (`@playwright/test@1.64.1`) | — (die Named-Locks-Position ist am 2026-10-18 geschlossen: Bump auf 1.64.1, committet `e1ed87a`) |
| `permissions` | nein | | Maschinen-Sache (globales `permissions`-Array, V2-Naming); das Projekt hat kein `.opencode/` — die Secret-Regel steht in `features/owner-decisions.md` §D-4 (Z. 19) — trifft nicht zu |
| `playwright-parallel` | ja | `.github/workflows/ci.yml` E2E-Matrix (Z. 391-397), `frontend/playwright.config.ts` (`fullyParallel: true` Z. 19, `workers: isCi ? 4 : 8` Z. 22) | — (die Matrix-Position ist am 2026-10-18 geschlossen) |
| `react-i18n` | ja | `frontend/lingui.config.ts`, `frontend/src/main.tsx` Z. 5 (`import './logic/I18nProvider'` als erster Import), `frontend/scripts/check-i18n.mjs` (extract→compile-Vergleich + AST-Pass), `frontend/AGENTS.md` §„Lingui / i18n" (Z. 106) | — |
| `security-review` | ja | `features/security/2026-10-18-review-closure.md` (14 Befunde SEC-01…SEC-14, alle geschlossen und unabhängig verifiziert, jeder mit Regressionstest), `features/security/accepted-risks.md` | — |
| `skills-marker` | ja | `AGENTS.skills.md` (diese Datei) | — |
| `tailscale-serve` | nein | | Kein Dev-Server-Expose im Projekt definiert — trifft nicht zu |
| `ui-review` | ja | `frontend/tests/screenshots/` (`ui-review.config.ts` Z. 9-10 — Routen-Manifest, zitiert „ui-review skill references/harness.md §5"; dazu `harness.ts`, `ui-screenshots.spec.ts`, `seeds.ts`), `AGENTS.todo.md` §„UI-Review 2026-09-26" | Die Rollenänderung derselben Range trifft `AGENTS.md` Z. 44 (verlangt den abgeschafften `vision`-Subagenten) → Board „SKILLS-STAND", Position 7 |
| `update-models` | nein | | Modell-Auswahl ist Maschinen-/Account-Sache; das Projekt definiert keine Modelle — trifft nicht zu (ersetzt die früheren Skills `model-updater` + `update-opencode-models`) |
| `web-audit` | nein | | Kein Live-Site-Audit (Crawl → Bild-Download → Vision-Fan-out → False-Positive-Gate → ZIP-Archiv) im Projekt definiert; die Sitemaps (`backend/routes/api.php:126-127`) sind Produkt-Feature — trifft nicht zu |

## Offen aus dem Bereich `6629ad2..787670a`

_Range: 33 Commits. Jede berührte Skill-Datei gegen **dieses** Projekt geprüft; die
Entscheidung steht je Zeile. Der Bereich umfasst auch die Regeldatei
`.agents/rules/build-verify.md` (4 Commits) — sie ist die Kernregel, auf die
`AGENTS.md` zeigt._

**Neu**

- `laravel` — **ja.** Das Projekt *ist* ein Laravel-Backend (`laravel/framework` ^13, PHP ^8.5) und wendet die Konventionen bereits an: hermetische `phpunit.xml`-Baseline (APP_ENV/BCRYPT_ROUNDS/CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION/DB `:memory:`), Seed- und Migrations-Policy in `backend/AGENTS.md`, Pint als Formatter **und** Auto-Fix mit CI-Gate, `paratest` ohne Worker-DB-Setup, 22 `FormRequest`-Klassen, `app/Policies/`, JWT im httpOnly-Cookie, Scout + Meilisearch. **Abweichung:** der DB-Standard des Skills (PostgreSQL, MySQL deprecated) ist durch die projekteigene DB-Policy ersetzt — das ist eine dokumentierte Projektentscheidung, keine Lücke; die Datei-Test-Regel nutzt eigene Helfer statt `Storage::fake()`.
- `react-i18n` — **ja.** Das Muster des Skills ist aus genau diesem `frontend/` abgeleitet und vollständig vorhanden: `lingui.config.ts` (de/de, `format-po` ohne Zeilennummern), `src/logic/I18nProvider.tsx` als erster Import in `main.tsx`, `@lingui/core/macro` + `@lingui/react/macro` (die für Lingui v6 korrekten Pfade), `eslint-plugin-lingui`, `lingui:extract`/`lingui:compile`/`check:i18n` und das Gate in `prebuild`. Das Gate macht genau, was der Skill verlangt: extract → msgids gegen die kompilierten Werte vergleichen → stale catalog oder roher Text = Fehler.
- `security-review` — **ja.** Der Ablauf des Skills ist in diesem Projekt **gelaufen und dokumentiert**: `features/security/2026-10-18-review-closure.md` hält die 14 Befunde SEC-01…SEC-14 mit Status, Commit, Regressionstest und Verifikationsrunde fest — genau das Muster des Skills (find → in Wellen schließen → mit Test und Register-Eintrag pinnen). **Population heute gemessen** (HEAD `344604e`, 2026-10-19): `cd backend && php artisan route:list --json` → **189 Routen, 151 mit `auth:`, 38 ohne** — der Referenzlauf des Skills nennt 189/150/39, die Gesamtzahl ist dieselbe, eine Route ist inzwischen hinter `auth:` (die Zahlen beschreiben den Lauf, nicht eine Regel).
- `web-audit` — **nein.** Kein Live-Site-Audit mit Crawl, Bild-Download, Vision-Fan-out, False-Positive-Gate und ZIP-Archiv ist hier definiert; die öffentlichen Sitemaps sind Produkt-Feature, kein Audit-Scope.
- `update-models` — **nein.** Modell-Auswahl ist Maschinen-/Account-Sache.

**Geändert**

- `agent-config` — **nein.** Maschinen-Setup (globale Config, MCP, `skills`-Array, neue Slash-Command-Templates unter `.agents/commands/`). Die §6-Commit-Policy dieser Range gilt dem Skills-Repo selbst, nicht diesem Projekt.
- `build-verify` — **ja** (bisher `nein`). Die Range macht daraus eine Verhaltensänderung: die Regeldatei `.agents/rules/build-verify.md` änderte sich viermal (Rule 3 prüft die *Substanz*, nicht die Gates; frische Session je Runde; K2 „reine Mechanik ist Text"; K4 „begleitende Doku reitet mit"; separate Commits als Default). Das Projekt adoptiert diesen Flow ausdrücklich — `AGENTS.md` §6 (Z. 46) verweist auf den Skill und hält nur repo-spezifische Kommandos fest. Der optionale Meilenstein-Review bleibt offen, weil das Projekt kein `N` und keinen Release-Takt festlegt — kein Regelbedarf, keine Position.
- `codegraph-project-setup` — **ja.** Neu ist nur der Scope-Guard („markdown-only repos gain nothing") — dieses Projekt ist ein Code-Repo mit `.codegraph/` und `.githooks/pre-commit`, der Guard greift also nicht. Die Vision-Eskalationsregel wurde auf „selbst neu lesen" umgeschrieben (siehe `vision-agents`).
- `docker-test-image` — **ja.** Die Description wurde gekürzt, die Substanz unverändert; der Skill ist aus genau diesem `deployment/Dockerfile.e2e` abgeleitet (Environment-only-Invariante, `ARG`/`USER root`/`ENV PLAYWRIGHT_BROWSERS_PATH`/`&& ls -1 /ms-playwright`-Postcondition, Rebuild-Trigger mit Lockfile in `paths`, wöchentlicher `schedule:`). **Neue offene Position (Board Position 6):** die Lockfile-Regel des Skills ist hier nicht umgesetzt.
- `ghcr-visibility` — **ja.** Description gekürzt, sonst unverändert. Die Board-Position 4 („GHCR-Blocker") ist am 2026-10-15 geschlossen und aus dem Board entfernt worden; die Voraussetzung ist im Workflow kommentiert und am 2026-10-15 neu gemessen (beide Pakete `public`). Der alte Verweis auf „Position 4" im Marker war dadurch ein toter Zeiger.
- `github-ci-filters` — **ja.** Description gekürzt; die Filter stehen unverändert (`paths` mit `!`-Rücknahme, D-23).
- `node-deps` — **ja.** Description gekürzt; die Range-Regel ist im `frontend/package.json` angewendet.
- `permissions` — **nein.** V2-Naming (`permissions` als geordnetes Array, `shell`/`subagent` als Aktionen) und die Kommando-Korrektur `opencode2` → `opencode` betreffen die Maschinen-Config; das Projekt hat kein `.opencode/`.
- `playwright-parallel` — **ja.** Description gekürzt; Matrix-Muster und Worker-Zahl stehen unverändert.
- `skills-marker` — **ja.** Die Range verlangt stabile Referenzen (Abschnitt/Überschrift/Suchstring statt reiner Zeilennummer) und das Re-Verifizieren **aller** Anker beim Fortschreiben — beides ist in diesem Durchgang angewendet.
- `tailscale-serve` — **nein.** Description gekürzt; im Projekt ist kein Dev-Server-Expose definiert.
- `ui-review` — **ja** (bisher `nein`). Das Projekt hat ein eigenes Screenshot-Harness mit Routen-Manifest (`frontend/tests/screenshots/ui-review.config.ts`), das im Kommentar ausdrücklich „ui-review skill references/harness.md §5" zitiert, und hat die Schleife am 2026-09-26 angewendet. Die Eskalationsregel der Range („hard calls: re-read the image yourself") ist die projektseitige Folge aus dem `vision-agents`-Entfernen und trifft `AGENTS.md` Z. 44 → **Board Position 7**.

**Entfernt bzw. umbenannt**

- `vision-agents` — **entfernt in `dccdd40`** („single default model, drop custom vision subagent"). Der eigene Vision-Subagent ist gestrichen, die globale Config führt nur `plan`/`general`/`build`/`explore`. Die Zeile verschwindet mit diesem Fortschreiben; die offene Frage — ob das Projekt den Inhalt selbst braucht — ist als **Board Position 7** beantwortet (ja: `AGENTS.md` Z. 44 nennt den Subagenten noch).
- `model-updater` — **entfernt in `1c51237`** (mit `update-opencode-models` in `models` zusammengeführt).
- `update-opencode-models` — **entfernt in `1c51237`** (mit `model-updater` in `models` zusammengeführt).
- `models` — **neu in `1a096ba`, umbenannt in `update-models` (`fc28ea3`)**; der Slash-Command `/update-models` kam dazu. Die Netto-Wirkung ist eine Zeile: `update-models`, weiterhin maschinenweit → **nein**.

**Nicht-Skill-Änderungen der Range (ohne Projektbezug)**

- `.agents/commands/{security-review,update-models}.md` (versionierte Command-Templates), `.agents/rules/build-verify.md`, `AGENTS.md`/`README.md`/`AGENTS.todo.md` des Skills-Repos, `.githooks/pre-commit` (entfernt, das Skills-Repo ist markdown-only), `.github/skill-budget.json` + `.github/workflows/skill-budget.yml` + `scripts/skill-budget.mjs` (Budget für *beworbene* Skills — Kontextkosten des Skills-Repos, nicht des Projekts).

**Anker-Audit (dieser Durchgang, 2026-10-19)**

Alle Belege der Tabelle gegen den aktuellen Stand gelesen — **5 von 9 Zeilenanker-Gruppen waren falsch**, zwei aus demselben Grund (die Zieldatei war gewachsen), drei aus einem zweiten (das Root-`AGENTS.md` ist am 2026-10-18/19 auf 104 Zeilen umgebaut und **englisch** geworden — die Abschnittsnummern haben sich verschoben, D-4 liegt jetzt in `features/owner-decisions.md`):

- `ui-review` — `AGENTS.md:714-715` zeigte auf die D-23-Lücke („80 der 113 Markdown-Dateien"), nicht auf den UI-Review-Hinweis. **Korrigiert** auf `AGENTS.md` §6 (Z. 44) für die Rollenfrage und `frontend/tests/screenshots/` für die Anwendung.
- `permissions` — `AGENTS.md:457-461` überlappte D-3 statt D-4 zu benennen. **Korrigiert** auf `features/owner-decisions.md` §D-4 (Z. 19).
- `ghcr-visibility` — `.github/workflows/ci.yml:335` war eine Kommentarzeile; das `container:`-Image steht jetzt in Z. 358. **Korrigiert**.
- `playwright-parallel` — `frontend/playwright.config.ts:30-31` belegte die *Budgets* (`timeout`, `globalTimeout`), nicht die Worker-Zahl; die Matrix liegt in `ci.yml` jetzt in Z. 391-397, nicht 374-380. **Korrigiert** auf `fullyParallel`/`workers` (Z. 19/22) und die Matrix (Z. 391-397).
- `node-deps` — `frontend/package.json:71` und `frontend/pnpm-lock.yaml:767` zeigten nach dem Playwright-Bump (`e1ed87a`) auf die falschen Zeilen. **Korrigiert** auf Z. 72 und Z. 768.
- Unverändert bestätigt: `AGENTS.md` §11 (Z. 88-91), `deployment/Dockerfile.e2e` Z. 30/69-70, `.github/workflows/e2e-image.yml:41`, `.github/workflows/ci.yml:31,38`, `frontend/playwright.config.ts` Z. 19/22.

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
