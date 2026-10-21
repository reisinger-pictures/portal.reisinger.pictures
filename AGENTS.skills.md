# Skills-Marker

agents-skills-consumed: 4205142
geprüft am: 2026-10-21

> Stand: geprüft bis `4205142` (= HEAD des Skills-Repos zum Prüfzeitpunkt, 2026-10-21,
> 21:19 UTC; vollständig `4205142828e2f29d9cffdbf481680d678665f0c1`). Der Working Tree des
> Skills-Repos war zum Prüfzeitpunkt **sauber**.
> Range `787670a..4205142`: **26 Commits**, davon **24** mit `.agents/`-Bezug; **9** berührte
> Skill-Verzeichnisse, davon **2 neu** (`ratchet-gates`, `generated-docs`).
> **Projektstand der Anker:** gemessen am Vorgänger `d8ef650`, seitdem unverändert (Beleg:
> `git diff --stat d8ef650..HEAD -- AGENTS.md backend/ deployment/ features/ frontend/
> .github/workflows/` → ohne Ausgabe; `d8ef650` ist der **Messstand**, nicht zwingend der
> direkte Vorgänger). Die **zwei** Anker, deren Zieldatei dieser Commit selbst schreibt
> (`AGENTS.skills.md`, `AGENTS.todo.md`), gelten **am committeten Stand dieses Commits**.
> Regel: `skills-marker/SKILL.md` → „Bezugsrahmen-Regel".
> Format und Ablauf: Skill `skills-marker` im Skills-Repo.

Bezugsrahmen — die gemeinsame Regel, **zitiert statt kopiert**: `skills-marker/SKILL.md` →
„Bezugsrahmen-Regel" (Skill-ID + Datei + Anker, **ohne Zeilennummer** — die driftet, der
Anker bleibt); Wortlaut, Belege und Fälle: `skills-marker/references/notes.md`
§ „Der Bezugsrahmen". Diese Liste nennt die **Fälle**, nicht die Fassung — steht eine
Form nicht an der Zitierstelle, ist sie hier falsch:
- (a) was der Commit selbst festschreibt (Marker, Board, geänderte Dateien);
- (b) eine Fremddatei, die der Commit nicht anfasst und die seit dem genannten Stand
  **unverändert** ist;
- (c) eine Datei, die sich seit dem genannten Stand **geändert** hat.

**Lokale Ergänzung:** (1) Das **dritte Verdikt** `offen` in der Spalte `angewendet?` — die
Upstream-Vorlage kennt nur `ja | nein`; die Upstream-Fälle „trifft zu, ist nicht drin" und
„trifft nicht zu" sind hier an einem Verdiktwert unterscheidbar. (2) `<stand>` = der
committete Stand dieses Commits (die SHA, die erst mit dem Commit existiert) — der
Platzhalter in den Messkommandos dieses Markers und des Boards. Beide ergänzen die
gemeinsame Regel und ersetzen sie nicht.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Setup (globale `opencode.jsonc`, MCP, `skills`-Array, Slash-Command-Templates); das Projekt definiert kein eigenes Agent-Setup — trifft nicht zu |
| `build-verify` | ja | `AGENTS.md` §6 (Z. 47): der gemeinsame pull/verify/commit/push-Prozess ist im Skill `build-verify` definiert, repo-spezifische Kommandos bleiben in dieser Datei | — |
| `codegraph-project-setup` | ja | `AGENTS.md` §11 (Z. 89-92): `.codegraph/` optional und maschinenlokal, `.githooks/pre-commit` fails open, kein Commit-Gate | Lokaler Index ist optional, kein Commit-Gate |
| `docker-test-image` | ja | `deployment/Dockerfile.e2e` (`ARG PLAYWRIGHT_VERSION` Z. 34, Browser-Install Z. 73-74), `.github/workflows/e2e-image.yml` (Versionsextraktion Z. 47) | — (die Lockfile-Regel des Skills — Browserversion aus dem Lockfile — ist am 2026-10-21 umgesetzt: `db13b2c`; die Board-Position 6 ist damit geschlossen und aus dem Board entfernt, `23ca866`) |
| `generated-docs` | offen | `features/infrastructure/29-production-operations-runbook.md` (Z. 48 — „currently `mariadb`" gegen `backend/config/database.php` Z. 20), die Zahlen in der Prosa dieses Markers und des Boards — **ohne Generator und ohne Drift-Gate** | `AGENTS.todo.md` → Abschnitt „SKILLS-STAND (2026-10-19)" — **der Eintrag ist vom Owner anzulegen** (Stand 2026-10-21: keine Zeile im Board) |
| `ghcr-visibility` | ja | `.github/workflows/ci.yml` GHCR-Voraussetzung (Z. 52-61) + `container:`-Image (Z. 392) | — |
| `github-ci-filters` | ja | `.github/workflows/ci.yml` `paths:` unter `push` (Z. 31) und `pull_request` (Z. 39) — D-23 | — |
| `laravel` | ja | `backend/AGENTS.md` (Commands Z. 7 mit Pint-Gate Z. 20, Database Setup Policy Z. 22, Migration Policy Z. 32, Parallel Testing Z. 39), `backend/phpunit.xml` (Z. 30-54, hermetische Baseline), `backend/app/Http/Requests/` (22 FormRequests), `backend/app/Policies/` | Die DB-Policy (Produktion `mariadb`, Tests SQLite `:memory:`) weicht vom DB-Standard des Skills (PostgreSQL) ab, und die Abweichung ist **nirgends als Entscheidung festgehalten** → Board „SKILLS-STAND (2026-10-19)", Position 8 |
| `node-deps` | ja | `frontend/package.json` Z. 72 (`"@playwright/test": "^1.64.1"`), `frontend/pnpm-lock.yaml` Z. 768 (`@playwright/test@1.64.1`) | — (die Named-Locks-Position ist am 2026-10-18 geschlossen: Bump auf 1.64.1, committet `e1ed87a`) |
| `permissions` | nein | | Maschinen-Sache (globales `permissions`-Array, V2-Naming); das Projekt hat kein `.opencode/` — die Secret-Regel steht in `features/owner-decisions.md` §D-4 (Z. 19) — trifft nicht zu |
| `playwright-parallel` | ja | `.github/workflows/ci.yml` E2E-Matrix (Z. 425-431), `frontend/playwright.config.ts` (`fullyParallel: true` Z. 19, `workers: isCi ? 4 : 8` Z. 22) | — (die Matrix-Position ist am 2026-10-18 geschlossen) |
| `ratchet-gates` | ja | `ratchet.config.jsonc` (Gate-Header: `class`, `incident`, `unit`, `direction: ceiling`, `count` mit `registry`/`severities`/`statuses`), `ratchet.baseline.json` (Baseline), `ratchet.findings.json` (Registry als *view*), `.github/workflows/ci.yml` Job „Ratchet gates" (Z. 150-168, `mode: validate`) | — |
| `react-i18n` | ja | `frontend/lingui.config.ts`, `frontend/src/main.tsx` Z. 5 (`import './logic/I18nProvider'` als erster Import), `frontend/scripts/check-i18n.mjs` (extract→compile-Vergleich + AST-Pass), `frontend/AGENTS.md` §„Lingui / i18n" (Z. 106) | — |
| `security-review` | ja | `features/security/2026-10-18-review-closure.md` (14 Befunde SEC-01…SEC-14, alle geschlossen und unabhängig verifiziert, jeder mit Regressionstest), `features/security/accepted-risks.md`, `ratchet.findings.json` (Severity/Status getrennt, kanonische Werte) | — |
| `skills-marker` | ja | `AGENTS.skills.md` (diese Datei) | — |
| `tailscale-serve` | nein | | Kein Dev-Server-Expose im Projekt definiert — trifft nicht zu |
| `ui-review` | ja | `frontend/tests/screenshots/` (`ui-review.config.ts` Z. 9-10 — Routen-Manifest, zitiert „ui-review skill references/harness.md §5"; dazu `harness.ts`, `ui-screenshots.spec.ts`, `seeds.ts`), `AGENTS.todo.md` §„UI-Review 2026-09-26" | Die Rollenänderung derselben Range traf `AGENTS.md` §6 (verlangte den abgeschafften `vision`-Subagenten); am 2026-10-20 umgestellt auf den verbleibenden Weg (`1818909`, Ergänzung `b86c15c`) — die Board-Position 7 ist an diesem Tag geschlossen und aus dem Board entfernt (Beleg im Commit) |
| `update-models` | nein | | Modell-Auswahl ist Maschinen-/Account-Sache; das Projekt definiert keine Modelle — trifft nicht zu (ersetzt die früheren Skills `model-updater` + `update-opencode-models`) |
| `web-audit` | nein | | Kein Live-Site-Audit (Crawl → Bild-Download → Vision-Fan-out → False-Positive-Gate → ZIP-Archiv) im Projekt definiert; die Sitemaps (`backend/routes/api.php:127-128`) sind Produkt-Feature — trifft nicht zu |

## Offen aus dem Bereich `787670a..4205142`

_Range: 26 Commits, davon 24 mit `.agents/`-Bezug — **gemessen am Stand `4205142` des Skills-Repos**
(2026-10-21, 21:19 UTC); 9 Skill-Verzeichnisse, 2 davon neu. Jede
berührte Skill-Datei gegen **dieses** Projekt geprüft; die Entscheidung steht je Skill. Die
Regeldatei `.agents/rules/build-verify.md` (2 Commits) ist die Kernregel, auf die
`AGENTS.md` §6 zeigt; sie steht am Ende dieses Abschnitts._

**Neu**

- `ratchet-gates` — **ja.** (5 Commits in der Range.) Neu (`70d49e0`), ergänzt um das gemeinsame Vokabular
  (`b0d1b51`), die CLI-Map (`d7888cd`) und den Release-Stand (`d5dc52f`). Das Projekt fährt
  den Mechanismus bereits, ohne den Skill zu nennen: `ratchet.config.jsonc` trägt den
  Gate-Header der Regel (Feld `class` = Defektklasse, `incident` = der konkrete Anlass,
  `unit` = Zähleinheit, `direction: ceiling`, `count` mit `registry`/`severities`/`statuses`),
  `ratchet.baseline.json` trägt die Baseline, `ratchet.findings.json` trägt die Registry —
  die Prosa `features/security/2026-10-18-review-closure.md` bleibt der menschliche Record,
  die Registry ist die maschinenlesbare *Sicht*. Die CI fährt den Job „Ratchet gates"
  (`.github/workflows/ci.yml` Z. 150-168) mit `mode: validate`. **Die kanonischen Namen
  trägt das Projekt wörtlich:** die Stufen `critical`/`high`/`medium`/`low`/`info`, die
  Status `open`/`in-progress`/`fixed`/`accepted`/`wontfix`, die Zählregel („`accepted` und
  `wontfix` zählen nicht, bleiben aber sichtbar — eine Entscheidung ist keine Löschung") und
  die Trennung von Severity und Status; die Registry bildet die Prosa-Werte `P1`/`P2`/`closed`
  genau ab (SEC-01…SEC-07 → `critical`, SEC-08…SEC-14 → `high`, alle `fixed`).
  **Eine bewusste Abweichung:** die Regel nennt die Action `all-the-rest/ratchet-cli@v1`,
  das Projekt pinnt sie auf einen Commit-SHA (`bedc915967faaced135aec231d5d9b056abdc981`) —
  die eigene Supply-Chain-Policy verbietet bewegliche Tags (Kommentar in
  `.github/workflows/ci.yml`).
- `generated-docs` — **offen.** (1 Commit in der Range.) Neu (`00dbd22`). Das Projekt hat die **Erscheinung**: Zahlen
  und Fakten in der Prosa, die in Code stehen — die Zählungen dieses Markers und des Boards,
  `features/infrastructure/29-production-operations-runbook.md` Z. 48 („currently `mariadb`")
  gegen `backend/config/database.php` Z. 20. Es hat aber **weder einen Generator noch ein
  Drift-Gate**: im Repo steht kein generierter Block (kein `BEGIN GENERATED`-Marker), und
  die Regel der Range (`generated-docs/SKILL.md` → „## Numbers in prose") ist nicht umgesetzt.
  Was das Projekt stattdessen hat, ist eine **eigene** Disziplin (`AGENTS.md` §4: „give a
  reproducible command for any reported number") — das ist die lokale Antwort, nicht die des
  Skills, und sie erfüllt die Regel der Range nicht. → **offene Position:** Board
  „SKILLS-STAND (2026-10-19)" — der Eintrag ist vom Owner anzulegen (dieser Lauf legt
  **keine** Position an).

**Geändert**

- `agent-config` — **nein.** (4 Commits in der Range.) Maschinen-Setup (globale Config, MCP, `skills`-Array,
  Slash-Command-Templates). Die Range macht den Skill **versteckt** (`817c01e`: nur per
  exakter ID ladbar), lässt den §7-Index der verdeckten Skills wachsen (`70d49e0`,
  `00dbd22`) und schließt die Drift um den ungenutzten `instructions`-Key (`0024d7e`: die
  Always-on-Regeln stecken in einer `AGENTS.md`, nicht in einem Config-Key). Alles
  Maschinenkonfiguration; das Projekt definiert kein eigenes Agent-Setup — **die
  Anwendbarkeit ändert sich nicht**.
- `build-verify` — **ja.** (4 Commits in der Range.) Die Range macht daraus eine Verhaltensänderung: die Regeldatei
  `.agents/rules/build-verify.md` und die Skill-Datei tragen jetzt die Pflicht, dass jede
  Zahl, die etwas über den Commit behauptet, ihren Bezugsrahmen nennt — Zustand **und**
  Umfang (`build-verify/SKILL.md` → „names what it measures — state and scope"; `0024d7e`
  präzisiert: die Einbettung in die globale `AGENTS.md` ist die **Kurzform** der Pflicht,
  Drift ist eine **geänderte Pflicht**, kein abweichender Wortlaut). Das Projekt adoptiert
  den Flow ausdrücklich — `AGENTS.md` §6 (Z. 47) verweist auf den Skill und hält nur
  repo-spezifische Kommandos fest; die `Verify:`-Footer der Commit-Bodies tragen Stand und
  Umfang. `817c01e` kürzt nur die Trigger-Beschreibung des Skills (die Pflichten bleiben).
- `github-ci-filters` — **ja.** (2 Commits in der Range.) Die Regel der Range wurde korrigiert (`7328fc3`): Schritt 2
  verbietet nicht mehr die `paths`-Form, sondern nur **auswählende** Include-Listen; die
  entscheidende Frage lautet jetzt „does every path match at least one positive pattern?"
  (`github-ci-filters/SKILL.md` → „One question decides"). Das Portal nutzt `paths` mit
  `'**'` als **einzigem breiten** positivem Muster, der Negation `'!**/*.md'` und vier
  Re-Includes — das ist die **abziehende** Form, also zulässig. **Schritt 1 (Klassifikation):**
  kein Check liest eine Markdown-Datei **als Eingabe** — die Sweeps der Regel treffen nur
  die vier `paths:`-Zeilen der Workflow-Datei und **Erwähnungen** in Kommentaren und
  Docblocks; der Test, den der Workflow-Kommentar nennt, sagt in seinem Docblock das
  Gegenteil (`backend/tests/Feature/InfrastructureSupplyChainPolicyTest.php` → „Prose is
  deliberately not scanned", seit 2026-10-18). **Owner-Entscheidung:** D-23
  (`features/owner-decisions.md` → „### D-23: Documentation-only CI filtering") hält die
  `paths`-Form mit `'**'` fest. **Zwei Befunde sind zu melden, nicht zu ändern:** (a) D-23
  nennt **drei** Re-Includes (`features/infrastructure/**`, `features/ai/**`,
  `features/e2e-test-strategy.md`), der Workflow hat **vier** — `features/security/**` kam
  später hinzu; die Entscheidung nachzuziehen ist Sache des Owners. (b) der Kommentar in
  `.github/workflows/ci.yml` (Z. 20) behauptet „InfrastructureSupplyChainPolicyTest scans
  .md" — das widerspricht dem Docblock des Tests; die Korrektur gehört in einen eigenen
  Commit.
- `security-review` — **ja.** (2 Commits in der Range.) Die Range nimmt die Severity-Namen aus dem kanonischen
  Vokabular (`b0d1b51`: Stage 2 nennt die Stufen aus `ratchet-gates`, Stage 4 ordnet `info`
  zuletzt, ein wieder auftauchender Befund ist ein **neuer** Befund und `open`, nicht
  `fixed`; `b9f140b`: die Namen und ihre Bedeutung sind kanonisch, die Matrix ist die
  Methode). Das Projekt trennt Severity und Status in der Closure-Tabelle
  (`features/security/2026-10-18-review-closure.md`, Spalten „Severity"/„Status"), führt
  alle 14 Befunde als `fixed` — die Regel „ein Befund braucht Owner und Ticket, außer er ist
  `fixed`" bindet also nichts — und hält in `ratchet.findings.json` die kanonischen Werte
  wörtlich. **Die Anwendung ändert sich dadurch nicht**; die kanonische Namensliste ist
  der Grund, warum die Projektwerte kein zweites Vokabular brauchen.
- `skills-marker` — **ja.** (12 Commits in der Range.) Die Range ist die Kernregel, auf die dieser Marker zeigt: der
  **Bezugsrahmen** (`e523df9` — die drei Formen (a)/(b)/(c), jede Aussage nennt den Stand,
  in dem sie gemessen wurde), die **Zitierform** statt Kopie (`65fbecc`, `f7b8f9c`), die
  Klausel, dass eine Messung, deren Umfang der Commit selbst schreibt, **keinen**
  Vorgänger-Rahmen trägt (`820c140`), die Klausel „eine Aufzählung trägt zwei Behauptungen"
  (`37cefe2`) und die Klausel, dass eine Aussage über **wann** etwas galt, den Stand **und**
  den Messzeitpunkt nennt (`c056e75`; `857070a` und `4205142` berichtigen die Zitate in der
  Referenz).
  Beides ist in diesem Durchgang angewendet: die Rahmen sind umgestellt (Form (a) für die
  beiden Dateien, die der Marker selbst schreibt, Form (b) für die übrigen), die Regel wird
  **zitiert** statt kopiert, der Ablauf-Abschnitt unten verweist auf den Skill statt eine
  veraltete Kopie zu führen, und die projekt-eigenen Regeln sind als **lokale Ergänzung**
  gekennzeichnet.
- `ui-review` — **ja.** (3 Commits in der Range.) `fa2268f` nimmt den „vision-agent"-Wortlaut aus der Findings-Vorlage
  — das Projekt hatte den Subagenten bereits abgeschafft (`AGENTS.md` §6: „visual review is
  done by the agent itself"). `b0d1b51`/`b9f140b` geben der Vorlage die kanonischen Stufen
  und die Spaltenform Id / Severity / Status / Owner / Ticket / File:Line / Screenshot /
  Finding / Suggested fix (`ui-review/references/findings-report.md` → „## Template") und
  verschieben die Verdiktregel auf „unresolved (`open` oder `in-progress`)". **Anwendung im
  Projekt:** das Harness ist drin (`frontend/tests/screenshots/`: `ui-review.config.ts`
  Z. 9-10, `harness.ts`, `ui-screenshots.spec.ts`, `seeds.ts`), die Befunde der Runde vom
  2026-09-26 stehen im Board mit den **kanonischen** Stufen `medium`/`low` — aber in der
  **alten** Spaltenform (Schwere/Stelle/Befund/Vorschlag): **keine** Id, **kein** Status,
  **kein** Owner, **kein** Ticket. Die bindende Regel der neuen Vorlage greift heute
  **nicht**, weil das Projekt **keinen offenen** UI-Befund führt (alle vier sind umgesetzt);
  für die nächste Runde ist die Form nachzuziehen.
- `update-models` — **nein.** (3 Commits in der Range.) `219171c` (Namespace-Regel und Snapshot am Config), `8c6b9af`
  (die `$0`-Aussage auf das konfigurierte Modell eingegrenzt), `817c01e` (versteckt).
  Modell-Auswahl und -Registry sind Maschinen-/Account-Sache; das Projekt schreibt keine
  Modell-ID in eine Config — **die Anwendbarkeit ändert sich nicht**.

**Nicht-Skill-Änderungen der Range (ohne Projektbezug)**

- `.agents/rules/build-verify.md` (2 Commits: `0449b05`, `0024d7e`) — die Kernregel, auf die
  `AGENTS.md` §6 zeigt; `.agents/commands/{security-review,update-models}.md` sind nicht in
  dieser Range enthalten. `.github/skill-budget.json` (Budget für *beworbene* Skills) und
  die Arbeitsdateien des Skills-Repos (README, Task-Liste, eigene Regeln) betreffen das
  Skills-Repo selbst, nicht das Projekt.

**Anker-Re-Verifizierung (alle Anker, gemessen am 2026-10-21)**

Alle Anker dieses Markers sind gegen den committeten Projektstand aufgelöst — **nicht** gegen
den Arbeitsbaum (`git show <stand>:<datei>`, `git grep <stand> -- <pfad>`). **Zähleinheit:
Vorkommen** — eine Zählung je Fundstelle, die einen **Ort** adressiert: eine **Zeile** (eine
Zahl nach „Z."), ein **Bereich** (zwei Zahlen mit Bindestrich), ein **Pfad mit
Zeilennummer** oder ein **Pfad ohne Zeilennummer**. Ein Pfad mit Zeilennummer zählt
**einmal** (Zeile und Datei zusammen); dieselbe Datei an zwei Stellen zählt zweimal. Nicht
mitgezählt werden Pfadmuster mit Platzhalter (sie nennen eine Form, keinen Ort), Verweise auf
die Maschinen-Config und Verweise auf die **Historie** (alte Anker, die dieser Lauf korrigiert
hat). Aufteilung: **Projekt** (dieses Repo) und **Upstream** (Skills-Repo). Gemessen:
**175 Vorkommen** in **82 Zeilen** — **153 Projekt** (in **61 Zeilen**, **67 verschiedene
Ziele**) und **22 Upstream** (in **21 Zeilen**, **9 verschiedene Ziele**).

- **Projekt: 67 verschiedene Ziele, 67 Treffer, 0 gedriftet, 0 tot.** 66 Ziele lösen am
  committeten Stand auf; **1** ist eine **bestätigte Negation** (`.opencode/` — das Projekt
  hat keins). Geprüft wurde gegen `d8ef650` (Form (b) — die Zieldateien sind seither
  unverändert, Beleg: `git diff --stat d8ef650..HEAD -- AGENTS.md backend/ deployment/
  features/ frontend/ .github/workflows/` → ohne Ausgabe) bzw. gegen den committeten Stand
  dieses Commits (Form (a) — die beiden Anker, deren Zieldatei der Marker selbst schreibt).
  Die Zeilennummern, die auf **wachsende** Dateien zeigen, sind gemessen und stimmen:
  `AGENTS.md` §6
  Z. 47 und §11 Z. 89-92 (die Datei ist am 2026-10-18/19 auf 104 Zeilen umgebaut und
  englisch geworden — die **alten** `AGENTS.md`-Anker zeigten ins Leere; ihre Zahlen
  stehen im Vorgänger-Marker und in dessen Commit), `.github/workflows/ci.yml`
  Z. 31/39/52-61/392/425-431 (779 Zeilen), `deployment/Dockerfile.e2e` Z. 34/73-74,
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
  `features/security/accepted-risks.md`, `frontend/tests/screenshots/` mit `harness.ts`,
  `ui-screenshots.spec.ts`, `seeds.ts`, `features/owner-decisions.md` mit D-1…D-48,
  `features/tech/07-architectural-decisions.md` mit AD-1…AD-10,
  `backend/tests/Feature/InfrastructureSupplyChainPolicyTest.php`).
- **Upstream: 9 verschiedene Ziele, 9 Treffer, 0 gedriftet, 0 tot — eine Form korrigiert.**
  Der Upstream-Anker auf `laravel/SKILL.md` trug als Ort eine **Zeilennummer**;
  die Upstream-Regel verbietet sie im Zitat („Pfad **im Skill** (ID + Datei) und Anker,
  **ohne** Zeilennummer"). Ersetzt durch den wörtlichen Anker: `laravel/SKILL.md` → „fake every
  local disk in `setUp()`" (1 Treffer am Stand `4205142`). Die übrigen Upstream-Verweise sind
  Zitate **ohne** Zeilennummer und haben je **1** Treffer am Stand `4205142`:
  `skills-marker/SKILL.md` → „Bezugsrahmen-Regel", `skills-marker/references/notes.md`
  → „## Der Bezugsrahmen", `github-ci-filters/SKILL.md` → „does every path match at least one
  positive pattern?", `generated-docs/SKILL.md` → „## Numbers in prose",
  `build-verify/SKILL.md` → „names what it measures — state and scope",
  `ui-review/references/findings-report.md`
  → „## Template"; die zwei Dateiverweise `.agents/rules/build-verify.md` und
  `.github/skill-budget.json` existieren.
- **Rahmen:** jede Aussage dieses Markers trägt jetzt einen Rahmen. **Form (a)** („am
  committeten Stand dieses Commits") für die beiden Anker, deren Zieldatei der Marker selbst
  schreibt (`AGENTS.skills.md`, `AGENTS.todo.md`); **Form (b)** („gemessen am Vorgänger
  `d8ef650`, seitdem unverändert") für die übrigen, mit dem Diff-Beleg. Im Vorgänger-Marker
  stand Form (a) **0-mal** (gemessen: 0 Treffer), die Formel „gemessen am Vorgänger"
  **1-mal** (das Wort „Vorgänger" insgesamt **4-mal**). Der Cursor
  (`agents-skills-consumed`) ist **kein** Rahmen — er zeigt den Stand des Skills-Repos.

## Fortschreiben

Der Ablauf steht im Skill `skills-marker` — `skills-marker/SKILL.md` → „## 5. Nach einem
`git pull` im Skills-Repo" (Skill-ID + Datei + Anker, **ohne Zeilennummer** — die driftet,
der Anker bleibt). Der Marker führt **keine Kopie** des Ablaufs: eine Kopie driftet, wie die
Prüfung dieser Range gezeigt hat (der Vorgänger-Marker führte Schritt 6 ohne die
Anker-Re-Verifizierung und ohne die Bezugsrahmen-Regel).
