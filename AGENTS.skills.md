# Skills-Marker

agents-skills-consumed: 33cbd00
geprüft am: 2026-10-22

> Stand: geprüft bis `33cbd00` (= HEAD des Skills-Repos zum Prüfzeitpunkt, 2026-10-22,
> 06:24 UTC; vollständig `33cbd004bb63c6022118ab39309ab705b382a9eb`). Der Working Tree des
> Skills-Repos war zum Prüfzeitpunkt **sauber**.
> Range `e559a39..33cbd00`: **1 Commit**, davon **1** mit `.agents/`-Bezug; **2** berührte
> Skill-Verzeichnisse (`build-verify`, `agent-config`), davon **0 neu**. Die Zahl der
> **versteckten** Skills (`metadata.opencode/autoinvoke: false`) ist an beiden Revs **17** —
> unverändert.
> **Projektstand der Anker:** gemessen am Vorgänger `c6004d8`, seitdem unverändert (Beleg:
> `git diff --stat c6004d8..HEAD -- AGENTS.md backend/ deployment/ features/ frontend/
> .github/workflows/` → ohne Ausgabe, gemessen vor dem Commit dieses Markers; `c6004d8` ist der
> **Messstand**, nicht zwingend der direkte Vorgänger).
> **Form (a)** — „am committeten Stand dieses Commits" — gilt für die eine Zieldatei, die
> diese Serie selbst schreibt (`AGENTS.skills.md`); die Projekt-Fremddateien tragen Form (b)
> (unverändert seit `c6004d8`); die **zwei** Upstream-Zieldateien, die die Range änderte
> (`build-verify/SKILL.md`, `.agents/rules/build-verify.md`), tragen Form (c) — am Stand
> `33cbd00` **neu gemessen**, ihre Anker lösen mit **denselben** Zeilennummern auf.
> Regel: `skills-marker/SKILL.md` → „Bezugsrahmen-Regel".
> Format und Ablauf: Skill `skills-marker` im Skills-Repo.

Bezugsrahmen — die gemeinsame Regel, **zitiert statt kopiert**: `skills-marker/SKILL.md` →
„Bezugsrahmen-Regel" (Skill-ID + Datei + Anker, **ohne Zeilennummer** — die driftet, der
Anker bleibt); Wortlaut, Belege und Fälle: `skills-marker/references/notes.md`
§ „Der Bezugsrahmen". Diese Liste nennt die **Fälle**, nicht die Fassung — steht eine
Form nicht an der Zitierstelle, ist sie hier falsch:
- (a) was der Commit selbst festschreibt (Marker, geänderte Dateien);
- (b) eine Fremddatei, die der Commit nicht anfasst und die seit dem genannten Stand
  **unverändert** ist;
- (c) eine Datei, die sich seit dem genannten Stand **geändert** hat.

**Lokale Ergänzung:** (1) Das **dritte Verdikt** `offen` in der Spalte `angewendet?` — die
Upstream-Vorlage kennt nur `ja | nein`; die Upstream-Fälle „trifft zu, ist nicht drin" und
„trifft nicht zu" sind hier an einem Verdiktwert unterscheidbar. (2) `<stand>` = der
committete Stand dieses Commits (die SHA, die erst mit dem Commit existiert) — der
Platzhalter in den Messkommandos dieses Markers und des Boards. (3) Die Fall-Liste oben
(a)/(b)/(c) ist die **projekt-eigene** Zuordnung, welcher Fall wo gilt; der Upstream trägt
die Fälle in `skills-marker/references/notes.md` § „Der Bezugsrahmen". (4) Die
**Zähleinheit** der Anker-Re-Verifizierung (Abschnitt unten) ist projekt-eigen — der
Upstream schreibt keine vor. Alle vier ergänzen die gemeinsame Regel und ersetzen sie
nicht.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Setup (globale `opencode.jsonc`, MCP, `skills`-Array, Slash-Command-Templates) und die Commit-Konvention **des Skills-Repos** — die Range ergänzt §6 um die Kurzformen der neuen Amend-Ausnahme (mit „**This repo does not take it**") und der Pfad-Commit-Klausel, jetzt um den bindenden Satz (die Ausnahme der Repo-Policy greift für die Pfad-Commit-Klausel **nicht**); das Projekt definiert kein eigenes Agent-Setup und ist nicht das Skills-Repo — trifft nicht zu |
| `build-verify` | ja | `AGENTS.md` §6 (Z. 48): der gemeinsame pull/verify/commit/push-Prozess ist im Skill `build-verify` definiert, repo-spezifische Kommandos bleiben in dieser Datei; die Ausnahme-Klausel der Regel 4 (Pfad-Commit-Klausel) ist **nicht** per Repo-Policy abschaltbar — das Projekt trägt keine eigene (gemessen am committeten Stand `d79f404`: **0** Treffer in `AGENTS.md`) | — |
| `codegraph-project-setup` | ja | `AGENTS.md` §11 (Z. 90-93): `.codegraph/` optional und maschinenlokal, `.githooks/pre-commit` fails open, kein Commit-Gate | Lokaler Index ist optional, kein Commit-Gate |
| `docker-test-image` | ja | `deployment/Dockerfile.e2e` (`ARG PLAYWRIGHT_VERSION` Z. 34, Browser-Install Z. 73-74), `.github/workflows/e2e-image.yml` (Versionsextraktion Z. 47) | — (die Lockfile-Regel des Skills — Browserversion aus dem Lockfile — ist am 2026-10-21 umgesetzt: `db13b2c`; die Board-Position 6 ist damit geschlossen und aus dem Board entfernt, `23ca866`) |
| `generated-docs` | offen | `features/infrastructure/29-production-operations-runbook.md` (Z. 48 — „currently `mariadb`" gegen `backend/config/database.php` Z. 20), die Zahlen in der Prosa dieses Markers und des Boards — **ohne Generator und ohne Drift-Gate**; die Regel des Skills (`generated-docs/SKILL.md` → „## Numbers in prose") ist **nicht** umgesetzt | `AGENTS.todo.md` → Abschnitt „SKILLS-STAND (2026-10-19)" — **der Eintrag ist vom Owner anzulegen** (Stand 2026-10-22: keine Zeile im Board) |
| `ghcr-visibility` | ja | `.github/workflows/ci.yml` GHCR-Voraussetzung (Z. 57-66) + `container:`-Image (Z. 588) | — |
| `github-ci-filters` | ja | `.github/workflows/ci.yml` `paths:` unter `push` (Z. 36) und `pull_request` (Z. 44) — D-23; die entscheidende Frage des Skills (`github-ci-filters/SKILL.md` → „does every path match at least one positive pattern?") beantwortet D-23 | — |
| `laravel` | ja | `backend/AGENTS.md` (Commands Z. 7 mit Pint-Gate Z. 20, Database Setup Policy Z. 22, Migration Policy Z. 32, Parallel Testing Z. 39), `backend/phpunit.xml` (Z. 30-54, hermetische Baseline), `backend/app/Http/Requests/` (22 FormRequests), `backend/app/Policies/`; die Regel des Skills für Datei-Tests (`laravel/SKILL.md` → „fake every local disk in `setUp()`") ist im Backend umgesetzt — `Storage::fake(...)` in **51** Testdateien (gemessen am Stand `c6004d8`) | — (die DB-Engine-Policy ist am 2026-10-21 **entschieden**: **D-49** in `features/owner-decisions.md` hält **MariaDB** als Produktions-Engine fest — die Abweichung vom DB-Standard des Skills (PostgreSQL) ist als Entscheidung dokumentiert, mit Begründung und Belegstellen: `backend/config/database.php` Z. 20, `features/infrastructure/29-production-operations-runbook.md` Z. 48, `backend/AGENTS.md` Z. 28/41; die Board-Position 8 ist geschlossen und aus dem Board entfernt) |
| `node-deps` | ja | `frontend/package.json` Z. 72 (`"@playwright/test": "^1.64.1"`), `frontend/pnpm-lock.yaml` Z. 768 (`@playwright/test@1.64.1`) | — (die Named-Locks-Position ist am 2026-10-18 geschlossen: Bump auf 1.64.1, committet `e1ed87a`) |
| `permissions` | nein | | Maschinen-Sache (globales `permissions`-Array, V2-Naming); das Projekt hat kein `.opencode/` — die Secret-Regel steht in `features/owner-decisions.md` §D-4 (Z. 19) — trifft nicht zu |
| `playwright-parallel` | ja | `.github/workflows/ci.yml` E2E-Matrix (Z. 621-627), `frontend/playwright.config.ts` (`fullyParallel: true` Z. 19, `workers: isCi ? 4 : 8` Z. 22) | — (die Matrix-Position ist am 2026-10-18 geschlossen) |
| `ratchet-gates` | ja | `ratchet.config.jsonc` (Gate-Header: `class`, `incident`, `unit`, `direction: ceiling`, `count` mit `registry`/`severities`/`statuses`), `ratchet.baseline.json` (Baseline), `ratchet.findings.json` (Registry als *view*), `.github/workflows/ci.yml` Job „Ratchet gates" (Z. 155-173, `mode: validate`) | — |
| `react-i18n` | ja | `frontend/lingui.config.ts`, `frontend/src/main.tsx` Z. 5 (`import './logic/I18nProvider'` als erster Import), `frontend/scripts/check-i18n.mjs` (extract→compile-Vergleich + AST-Pass), `frontend/AGENTS.md` §„Lingui / i18n" (Z. 106) | — |
| `security-review` | ja | `features/security/2026-10-18-review-closure.md` (14 Befunde SEC-01…SEC-14, alle geschlossen und unabhängig verifiziert, jeder mit Regressionstest), `features/security/accepted-risks.md`, `ratchet.findings.json` (Severity/Status getrennt, kanonische Werte) | — |
| `skills-marker` | ja | `AGENTS.skills.md` (diese Datei) | — |
| `tailscale-serve` | nein | | Kein Dev-Server-Expose im Projekt definiert — trifft nicht zu |
| `ui-review` | ja | `frontend/tests/screenshots/` (`frontend/tests/screenshots/ui-review.config.ts` Z. 9-10 — Routen-Manifest, zitiert „ui-review skill references/harness.md §5"; dazu `frontend/tests/screenshots/harness.ts`, `frontend/tests/screenshots/ui-screenshots.spec.ts`, `frontend/tests/screenshots/seeds.ts`), `AGENTS.todo.md` §„UI-Review 2026-09-26"; die Vorlage des Befundberichts (`ui-review/references/findings-report.md` → „## Template") | Die Rollenänderung einer früheren Range traf `AGENTS.md` §6 (verlangte den abgeschafften `vision`-Subagenten); am 2026-10-20 umgestellt auf den verbleibenden Weg (`1818909`, Ergänzung `b86c15c`) — die Board-Position 7 ist an diesem Tag geschlossen und aus dem Board entfernt (Beleg im Commit). **Aus der Range `4205142..e559a39`:** die neue Befundvorlage (neun Spalten) und das kanonische Schweregrad-Vokabular sind im Board **nicht** abgebildet — es führt die alte Form (Schwere/Stelle/Befund/Vorschlag). Die Form nachzuziehen ist eine **Eigentümerentscheidung**; dieser Lauf legt **keine** Position an und schließt **keine** |
| `update-models` | nein | | Modell-Auswahl ist Maschinen-/Account-Sache; das Projekt definiert keine Modelle — trifft nicht zu (ersetzt die früheren Skills `model-updater` + `update-opencode-models`) |
| `web-audit` | nein | | Kein Live-Site-Audit (Crawl → Bild-Download → Vision-Fan-out → False-Positive-Gate → ZIP-Archiv) im Projekt definiert; die Sitemaps (`backend/routes/api.php:127-128`) sind Produkt-Feature — trifft nicht zu |

## Offen aus dem Bereich `e559a39..33cbd00`

_Range: 1 Commit, davon 1 mit `.agents/`-Bezug — **gemessen am Stand `33cbd00` des Skills-Repos
(2026-10-22, 06:24 UTC); 2 Skill-Verzeichnisse, 0 davon neu. Jede berührte Skill-Datei gegen
**dieses** Projekt geprüft; die Entscheidung steht je Skill. Die Regeldatei
`.agents/rules/build-verify.md` (1 Commit) ist die Kernregel, auf die `AGENTS.md` §6 zeigt;
sie steht am Ende dieses Abschnitts._

**Geändert** (in der Range kam **kein** neuer Skill hinzu —
`git log --diff-filter=A --name-only --format= e559a39..33cbd00 -- .agents/skills` ohne Ausgabe)

- `build-verify` — **ja.** (1 Commit in der Range: `33cbd00`.) Der Commit schließt **zwei**
  Lücken, die der Vorgänger `e559a39` in seinem eigenen Body benannt und offengelassen hatte.
  (a) **Die Ausnahme-Klausel:** `.agents/rules/build-verify.md` (Z. 27) beginnt nun „A repo's
  own documented branch policy can override rules 4–7 where it says so — **except the
  path-commit clause of rule 4**" und trägt unmittelbar danach den bindenden Satz „The
  path-commit clause of rule 4 binds **every** session in a shared tree, and the responsibility
  is the **committer's**, not the neighbour's; no repo's branch policy overrides it." — die
  **Pfad-Commit-Klausel ist damit nicht per Repo-Policy abschaltbar**; dieselbe Aussage steht
  wortgleich im Skill `build-verify` (Schritt 3, Z. 109) und in
  `build-verify/references/notes.md` (Z. 50, Z. 231, Z. 277) — an **6** Stellen, **1** Form,
  bytegleich (gemessen am committeten Stand `33cbd00`, Scope `.agents/`).
  (b) **Die README des Skills-Repos** committet jetzt pfadexplizit (`git add
  .agents/skills/<id>/`, `git show --stat`, `git commit -m "feat(skill): <id>" --
  .agents/skills/<id>/`, `git push`) statt `git add -A && git commit && git push` — genau das
  Muster, das im fremden Baum den fremden Commit erzeugt hatte.
  **Was die Änderung an der Anwendung im Projekt ändert:** **nichts am Code** — das Projekt
  trägt **keine eigene Repo-Policy**, die die Klausel ausschalten könnte; gemessen am
  committeten Stand `d79f404` (2026-10-22, 06:24 UTC):
  `git grep -n -iE 'amend|unpushed|force-with-lease|git add -A' d79f404 -- AGENTS.md` →
  **0 Treffer** (der einzige Fund im Repo, `.agents/skills/metronome/SKILL.md` Z. 55, ist die
  „Amendments"-Route der Stripe-API in einem fremden Skill-Pack und keine Amend-Policy). Die
  Ausnahme-Klausel hat hier also **nichts abzuschalten**: die Pfad-Commit-Klausel gilt in diesem
  Repo **unbedingt**. Praktische Folge: dieses Repo ist ein Baum mit **parallelen Sessions**
  (andere Sessions pushen hier) — jeder Commit entsteht deshalb mit `git commit -- <pfad>` und
  hinterlässt **nichts staged**; der Commit, den dieser Marker festschreibt, ist so gebaut.
  (a) **schärft** eine Klausel, die hier ohnehin galt (keine Repo-Policy, die sie ausschalten
  könnte); (b) ist die Arbeitsanleitung des **Skills-Repos** selbst und für dieses Projekt ohne
  Wirkung — `AGENTS.md` §6 verweist ohnehin auf den Skill `build-verify`.
- `agent-config` — **nein.** (1 Commit in der Range: `33cbd00`.) §6 („Commit convention
  (agents-skills repo)") bekommt den bindenden Satz als Fortsetzung seines letzten Bullets
  (`agent-config/SKILL.md` Z. 187); `agent-config/references/snippets.md` ist in dieser Range
  **bytegleich** (sha256 `b38be3950f3e06fe…` an beiden Revs, gemessen über
  `git show <rev>:<pfad>`) — die Bewertung des Vorgänger-Markers gilt für diese Datei
  unverändert. Die geänderte Passage ist die Commit-Konvention **des
  Skills-Repos** (dessen SHAs sind der `skills-marker`-Range anderer Projekte) — also eine
  Aussage über ein anderes Repo und **keine Pflicht dieses Projekts**. Der Rest von
  `agent-config` (globales Setup, MCP, `skills`-Array, Slash-Command-Templates) bleibt
  Maschinen-Sache, die dieses Projekt nicht definiert; die **bindende** Fassung steht in der
  Regeldatei (Regel 4) und im Skill `build-verify` (Schritt 3), und diese beiden Stellen werden
  hier angewendet (siehe oben).

**Nicht-Skill-Änderungen der Range (ohne Projektbezug)**

- `.agents/rules/build-verify.md` (1 Commit: `33cbd00`) — die Kernregel, auf die `AGENTS.md` §6
  zeigt; sie trägt die Ausnahme-Klausel der Regel 4 und den bindenden Satz jetzt je in **einem**
  Satz (Z. 27).
- Die **README** des Skills-Repos (1 Commit: `33cbd00`) — der Block „Adding skills" committet
  jetzt pfadexplizit; das ist die Arbeitsanleitung des Skills-Repos selbst, kein Projektbezug.

**Anker-Re-Verifizierung (alle Anker; Zähleinheit und Zahl aus dem Vorgänger-Marker `d79f404`
übernommen, Gültigkeit in diesem Lauf neu belegt)**

Alle Anker dieses Markers sind gegen den **committeten** Projektstand aufgelöst — **nicht**
gegen den Arbeitsbaum (`git show <rev>:<datei>`, `git grep <rev> -- <pfad>`) — und gegen den
**committeten** Stand `33cbd00` des Skills-Repos. **Zähleinheit: Anker-Vorkommen** — ein
Vorkommen ist eine Stelle in `AGENTS.skills.md`, die **einen Ort adressiert**:
- jede `Z.`-Zahlengruppe (Form `Z. <n>` / `Z. <n>-<m>`) → **1**;
- jeder Pfad **mit** Zeilennummer (Form `<pfad>:<n>` / `<pfad>:<n>-<m>`) → **1**;
- jeder Pfad **ohne** Zeilennummer → **1** (dieselbe Datei an zwei Stellen zählt zweimal).

**Nicht** gezählt werden Pfadmuster mit Platzhaltern (`*`, `<…>`), Verweise auf die
Maschinen-Config (`opencode.jsonc`, `~/.config/…`) und Verweise auf die **Historie**
(Commit-SHAs; die **Korrekturstabelle** unten nennt die *alten* Anker und zählt deshalb
nicht mit). Aufteilung: **Projekt** (dieses Repo) und **Upstream** (Skills-Repo). **Die Zahl
und die Zähleinheit stammen aus dem Vorgänger-Marker `d79f404`** (daselbst gemessen am
2026-10-22): **197** Vorkommen in **85** Zeilen — **138** Projekt (in **47** Zeilen, **37**
verschiedene Zieldateien, **34** Zeilenanker) und **59** Upstream (in **43** Zeilen, **15**
verschiedene Zieldateien, **14** Zeilenanker). **Unverändert übernommen**, weil sich am
**Ankerbestand** nichts geändert hat: die Range berührt **nur** Dateien im Skills-Repo
(leerer Diff über die Projektpfade, Form (b)), und **jeder** Upstream-Anker wurde am Stand
`33cbd00` **neu gemessen** — alle treffen, mit **denselben** Zeilennummern. Der **Text**
dieses Markers hat den Range-Abschnitt ersetzt und **zwei** Upstream-Dateien benannt, die das
Audit von `d79f404` noch nicht führte (`build-verify/references/notes.md`,
`agent-config/SKILL.md`): die **Upstream-Zieldateien** sind damit **15 → 17**, die
**Upstream-Ziele** **29 → 31**; die **Projekt-Ziele** bleiben **71 unverändert**.

- **Projekt: 71 Ziele (37 Dateien + 34 Zeilenanker) — 67 treffen, 4 sind gedriftet und
  korrigiert, 0 sind tot.** Die Ziele lösen am
  committeten Stand `c6004d8` auf; **1** ist eine **bestätigte Negation** (`.opencode/` — das
  Projekt hat keins). Geprüft wurde gegen `c6004d8` (Form (b) — die Zieldateien sind seither
  unverändert, Beleg: `git diff --stat c6004d8..HEAD -- AGENTS.md backend/ deployment/
  features/ frontend/ .github/workflows/` → ohne Ausgabe) bzw. gegen den committeten Stand
  dieses Commits (Form (a) — die Zieldatei, die diese Serie selbst schreibt: der Marker).
  Die Zeilennummern, die auf **wachsende** Dateien zeigen, sind **neu gemessen** — **4**
  Zeilenanker sind **gedriftet** und korrigiert (siehe unten), dazu **1** inhaltliche Angabe
  ohne Zeilennummer (der Pin der `ratchet-cli`-Action; die Zähleinheit zählt keine Werte).
  Unverändert gemessen und stimmig:
  `AGENTS.md` §11 Z. 90-93 (die Datei ist 109 Zeilen lang), `.github/workflows/ci.yml`
  Z. 36/44/57-66/155-173/24 (978 Zeilen), `deployment/Dockerfile.e2e` Z. 34/73-74,
  `.github/workflows/e2e-image.yml` Z. 47, `backend/AGENTS.md` Z. 7/20/22/28/32/39/41,
  `backend/phpunit.xml` Z. 30-54, `backend/routes/api.php` Z. 127-128,
  `backend/config/database.php` Z. 20, `features/owner-decisions.md` Z. 19 (§D-4),
  `features/infrastructure/29-production-operations-runbook.md` Z. 48,
  `frontend/package.json` Z. 72, `frontend/pnpm-lock.yaml` Z. 768,
  `frontend/playwright.config.ts` Z. 19/22, `frontend/src/main.tsx` Z. 5,
  `frontend/AGENTS.md` Z. 106, `frontend/tests/screenshots/ui-review.config.ts` Z. 9-10.
  Die Datei-Anker ohne Zeilennummer sind auf **Existenz** geprüft (`.codegraph/`,
  `.githooks/pre-commit`, `backend/app/Http/Requests/` mit 22 FormRequests,
  `backend/app/Policies/`, `frontend/lingui.config.ts`, `frontend/scripts/check-i18n.mjs`,
  `features/security/2026-10-18-review-closure.md` mit 14 Befunden SEC-01…SEC-14,
  `features/security/accepted-risks.md`, `frontend/tests/screenshots/` mit
  `frontend/tests/screenshots/harness.ts`,
  `frontend/tests/screenshots/ui-screenshots.spec.ts`, `frontend/tests/screenshots/seeds.ts`,
  `features/owner-decisions.md` mit D-1…D-49,
  `features/tech/07-architectural-decisions.md` mit AD-1…AD-10,
  `backend/tests/Feature/InfrastructureSupplyChainPolicyTest.php`).
- **Upstream: 31 Ziele (17 Dateien + 14 Zeilenanker) — 31 treffen, 0 gedriftet, 0 tot.** Die
  Upstream-Verweise zitieren **ohne** Zeilennummer (die Upstream-Regel verbietet sie im Zitat:
  „Pfad **im Skill** (ID + Datei) und Anker, **ohne** Zeilennummer"); die Zeilennummer in
  Klammern ist **Lesehilfe** zur Messung am Stand `33cbd00`. Je **1** Treffer am Stand
  `33cbd00`: `skills-marker/SKILL.md` → „Bezugsrahmen-Regel" (Z. 51),
  `skills-marker/references/notes.md` → „## Der Bezugsrahmen" (Z. 181),
  `skills-marker/SKILL.md` → „## 5. Nach einem" (Z. 134),
  `github-ci-filters/SKILL.md` → „does every path match at least one positive pattern?" (Z. 57),
  `generated-docs/SKILL.md` → „## Numbers in prose" (Z. 74),
  `build-verify/SKILL.md` → „names what it measures — state and scope" (Z. 102),
  `build-verify/SKILL.md` → „In a tree where other sessions work the paths are not optional"
  (Z. 106),
  `ui-review/references/findings-report.md` → „## Template" (Z. 8),
  `laravel/SKILL.md` → „fake every local disk in `setUp()`" (Z. 41); die Dateiverweise
  `.agents/rules/build-verify.md` (Regel 6, Z. 24; Regel 4, Z. 19),
  `build-verify/references/amend.md` (→ „## The one exception", Z. 18),
  `ratchet-gates/references/vocabulary.md` (→ „## Severity levels", Z. 10); die reinen
  Dateiverweise `skills-marker/templates/AGENTS.skills.md`,
  `agent-config/references/snippets.md`, `build-verify/references/notes.md`,
  `agent-config/SKILL.md` und `.github/skill-budget.json` existieren. Die **zwei** von der
  Range geänderten Zieldateien (`build-verify/SKILL.md`, `.agents/rules/build-verify.md`)
  tragen Form (c): am Stand `33cbd00` neu gemessen lösen ihre Anker (Z. 102/106 bzw.
  Z. 19/24) mit **denselben** Zeilennummern auf.
- **Korrekturen (jede gedriftete Stelle, mit Grund):**

  | Stelle | alt | neu | Grund |
  |---|---|---|---|
  | `AGENTS.md` §6 (Skill-Stand, Zeile `build-verify`) | Z. 47 | Z. 48 | `AGENTS.md` ist um **eine** Zeile **oberhalb** von §6 gewachsen (`bab24a8` fügte die Regel „A statement about how the code looks belongs to a **commit**, not to a working tree" ein) — die `build-verify`-Zeile rutschte von 47 auf 48. |
  | `AGENTS.md` §11 (Skill-Stand, Zeile `codegraph-project-setup`) | Z. 89-92 | Z. 90-93 | dieselbe Verschiebung: die Überschrift §11 von 89 auf 90, die `.codegraph/`-Zeile von 91 auf 92, die `.githooks/pre-commit`-Zeile von 92 auf 93. |
  | `.github/workflows/ci.yml` (Skill-Stand, Zeile `ghcr-visibility`) | Z. 397 | Z. 588 | der Workflow ist von 784 auf **978** Zeilen gewachsen (194 Zeilen im Pull `134ff32..c6004d8`); die `image:`-Zeile des E2E-Containers steht jetzt bei 588 (der `container:`-Schlüssel bei 580). |
  | `.github/workflows/ci.yml` (Skill-Stand, Zeile `playwright-parallel`) | Z. 430-436 | Z. 621-627 | derselbe Wachstumsschritt: die sieben Matrix-Einträge des E2E-Jobs rutschten von 430-436 auf 621-627 (der `matrix:`-Schlüssel bei 608). |
  | `.github/workflows/ci.yml` (Abschnitt „Geändert", Zeile `ratchet-gates`) | `bedc915967faaced135aec231d5d9b056abdc981` | `d45867fe6b92ff1e8bdf6744e1baed3e1c77b721` | der Commit-Pin der `ratchet-cli`-Action wurde im Pull umgeschaltet (`02d130e`, „the action is pinned to the release of 0.3.0"); weiterhin ein Commit-SHA, kein beweglicher Tag — die Abweichung zur Upstream-Angabe `all-the-rest/ratchet-cli@v1` bleibt bestehen. |

- **Rahmen:** jede Aussage dieses Markers trägt jetzt einen Rahmen. **Form (a)** („am
  committeten Stand dieses Commits") für den Anker, dessen Zieldatei der Marker selbst schreibt
  (`AGENTS.skills.md`); **Form (b)** („gemessen am Vorgänger `c6004d8`, seitdem unverändert")
  für die Projekt-Fremddateien, mit dem Diff-Beleg; **Form (c)** („gemessen am Stand
  `33cbd00`") für die **zwei** Upstream-Zieldateien, die die Range geändert hat
  (`build-verify/SKILL.md`, `.agents/rules/build-verify.md`) — neu gemessen, **dieselben**
  Zeilennummern. Gemessen am **normalisierten** Text (Zeilenumbrüche zu einem Leerzeichen)
  steht in diesem Marker „am committeten Stand dieses Commits" **3-mal**, „gemessen am
  Vorgänger" **3-mal**, das Wort „Vorgänger" insgesamt **13-mal**; im **Vorgänger-Marker**
  (Stand `134ff32`) **1-mal** / **3-mal** / **9-mal**, in dessen Vorgänger (`2a2316b`)
  **2-mal** / **3-mal** / **9-mal**. **Korrektur:** der Vorgänger-Marker nannte für seinen
  eigenen Vorgänger „0-mal" / „1-mal" / „4-mal" — das war eine **Rohstring-Zählung ohne
  Normalisierung** (ein Zeilenumbruch im Wortlaut zählte nicht); normalisiert gemessen stimmen
  die Werte nicht. Der Cursor (`agents-skills-consumed`) ist **kein** Rahmen — er zeigt den
  Stand des Skills-Repos.

## Fortschreiben

Der Ablauf steht im Skill `skills-marker` — `skills-marker/SKILL.md` → „## 5. Nach einem
`git pull` im Skills-Repo" (Skill-ID + Datei + Anker, **ohne Zeilennummer** — die driftet,
der Anker bleibt). Der Marker führt **keine Kopie** des Ablaufs: eine Kopie driftet, wie die
Prüfung dieser Range gezeigt hat — der Marker vor `2a2316b` (`4f53806`) führte Schritt 6
**ohne** die Anker-Re-Verifizierung (gemessen am committeten Stand `4f53806`:
`grep -c 'Anker-Re-Verifizierung'` → **0**) und **ohne** die Kennzeichnung der eigenen
Zusatzregeln (`grep -c 'Lokale Ergänzung'` → **0**); die Bezugsrahmen-Regel zitierte er
bereits (Z. 22).
