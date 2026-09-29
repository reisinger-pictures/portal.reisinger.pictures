# AI Operating Guidelines & Doc-as-Code Policy

**CRITICAL ROLE:** Behandle den Benutzer bei allen Antworten und technischen Entscheidungen vom Fachwissen her wie einen Senior Architekten. Die direkte Anrede "Senior Architekt" ist jedoch untersagt.

## 1. Sprachregeln

- **Language Policy:** Code & Docs: English. UI: German.
- **Gemischte Sprache in Doku/Configs erlaubt** (deutsche Kategorien, Begriffe wie „generieren" bleiben).

## 2. Definition of Done (DoD)

**Die DoD selbst steht in [`DoD.md`](DoD.md)** — menschenlesbar, bewusst kurz, ohne
Kommandos. Ein Task gilt als **abgeschlossen**, wenn die beiden Kriterien dort erfüllt
sind: **Tests existieren** und **`features/` ist aktuell**.

Dieser Abschnitt wiederholt die Definition nicht. Er sagt, **wie** ein Agent das erste
Kriterium erfüllt — die Testart folgt aus der Art der Änderung — und ergänzt **ein
drittes Gate**, das nur für Agenten gilt und nicht Teil der Definition für Menschen ist.

Wer die Definition selbst ändert, ändert `DoD.md`, nicht diesen Abschnitt. Sonst
beschreiben zwei Dateien eine Regel und driften auseinander.

**Kriterium 1 — Tests existieren** (die Testart folgt aus der Art der Änderung):

- Backend-Änderungen (Controller, Services, Modelle, Gates, Middleware): → **PHPUnit Feature/Unit Tests**
- Frontend-Logik (Hooks, Utils, API-Layer): → **Vitest Unit Tests**
- Frontend-UI/Komponenten (Views, Modals, Formulare): → **Playwright E2E Tests**
- Bug-Fixes: → **mindestens ein Regression-Test**, der den Bug reproduziert (PHPUnit oder E2E)
- Refactoring / Dead-Code-Removal: → kann ohne Tests auskommen, muss im Commit begründet werden

**Drittes Gate (nur Agenten) — Codequalität ist gut:**

- Frontend: `pnpm lint:fix && pnpm build` läuft fehlerfrei; `pnpm build` enthält bereits TypeScript- und i18n-Prüfungen. `tsc -b` ist nur eine optionale Zusatzdiagnose und kein Ersatz.
- Backend: `php artisan test` (alle bestehenden Tests grün)
- Keine `eslint-disable`, `@ts-ignore` oder `any`
- Keine blinden `.replace()`-Patches (Safe-Patching-Policy, §4)

## 3. Dokumentation & Task-Management

- **`features/`** = **dauerhafter SOLL-Zustand** des Systems. Hier landen nur Architekturentscheidungen, Datenmodelle, API-Verträge und Feature-Spezifikationen, die langfristig gültig sind.
- **`AGENTS.todo.md`** = **temporäre Task-Liste** + Code-Review-Notizen + Bug-Analysen + Session-Tracking. Alles, was nur für die aktuelle Session oder den nächsten PR relevant ist, gehört hierher, **nicht** in `features/`.
- Code-Reviews, temporäre Analysen und Diskussionen → `AGENTS.todo.md`. Nur wenn ein neuer SOLL-Zustand definiert wird → `features/`.
- **Task & Test Tracking:** Every feature requires actionable TODOs in `AGENTS.todo.md`. You MUST explicitly include TODOs for writing test cases (PHPUnit for backend, Playwright for E2E).
- **Board-Hygiene (STRICT):** `AGENTS.todo.md` ist eine **Arbeitsliste, kein Archiv**. Es wird bei der Übergabe einer Session bzw. unmittelbar nach dem Abschluss eines Themenblocks bereinigt, nicht erst wenn es unlesbar wird.
  - **1. Abgeschlossene Einträge werden entfernt, nicht abgehakt.** Ein `[x]`-Eintrag ist ein Signal, dass hier noch etwas zu tun ist — er bleibt nur für den Commit, der ihn geschlossen hat. Wer nach einem Befund sucht, findet sonst abgeschlossene Arbeit und hält sie für offen (in der Runde vom 2026-09-27 standen so vier Einträge, die Arbeit an einer inzwischen **gelöschten** Datei beschrieben).
  - **2. Was über eine Sitzung hinaus trägt, wandert nach `features/` oder ins Git.** Eine Architekturentscheidung, ein gehäfteter Root-Cause oder eine Regel mit allgemeiner Geltung gehören dauerhaft dokumentiert — nicht in eine Liste, die bei der nächsten Bereinigung verschwindet. `git log` ist das Archiv für den Fortschritt.
  - **3. Erkenntnisse ohne offene Aufgabe sind trotzdem wertvoll — aber kompakt.** Kein abgeschlossener Block, der länger ist als der Befund, den er begründet.
  - **4. Drei Fälle, in denen ein Eintrag trotzdem bleibt:**
    - **manuell zu prüfen** — vom Owner nach einem Deploy anzusehen; als `[ ] manuell prüfen: <was, wo, worauf>` führen, nicht als erledigt
    - **wartet auf eine Entscheidung** — `[ ] Entscheidung offen: <Frage>`
    - **wartet auf eine Bedingung** — `[~] wartet auf <Bedingung>`
  - **5. Ein Eintrag, der eine Zahl oder einen Zustand beschreibt, wird mit dem Stand von heute eingetragen und mit der Quelle des Nachweises.** Keine Zahl ohne Herleitung, kein Zustand ohne Commit.
- **Belegregeln für `features/` (STRICT).** Zwei Muster haben in den
  Dokumentations-Audits vom 2026-09-28 wiederholt Kosten verursacht, weil sie **still
  veralten, statt zu irren** — sie zeigen keinen Fehler, nur eine halbe Wahrheit:
  1. **Zeilenanker verrotten.** `file.php:NN` ohne das Zitierte daneben ist für einen
     Leser nicht überprüfbar und wirkt dennoch wie ein Beleg. Eine verschobene Zeile sieht
     genauso aus wie eine richtige. **Regel: wer `file:NN` nennt, zitiert die Zeile mit**
     (nicht `SettingsController.php:34`, sondern `SettingsController.php:34` →
     `LICENSE_TERM_RULES['base_price']`), oder nennt nur den Symbolnamen.
  2. **Zahlen ohne Herleitung.** Eine Zahl in Prosa ohne danebenstehenden Befehl, der sie
     reproduziert, kann später von niemandem widerlegt werden. **Regel: jede Zahl trägt
     ihren Befehl** — das Repo hat das Vorbild, die 28 Dialoge mit
     `grep -rnE '<Modal(Shell|DialogShell)' frontend/src` — **oder sie verliert ihre Zahl.**
  Beide Regeln gelten auch für `AGENTS.md` und für die Betriebsdoku unter `deployment/`.
- **Einen Befund, der behoben wurde, kennzeichnen statt ihn im Präsens zu lassen.**
  Sonst kann der Leser Fact und Fix nicht unterscheiden. Beispiel aus dem Audit:
  „Der Scanner läuft deshalb an HEAD rot" war einmal wahr, wurde behoben, und die
  Behauptung stand weiter im Präsens — inklusive der Empfehlung, einen roten
  CI-Job zu akzeptieren.
- **Eine Aussage, die ein Nachbar-Dokument überholt hat, gehört als `superseded`
  gekennzeichnet, nicht stillschweigend ersetzt.** Mindestens vier Dokumente unter
  `features/infrastructure/` beschreiben eine Marke `srp`, die es seit dem 2026-07-14
  nicht mehr gibt, im Präsens und mit `status: active`.
- **Zero Pre-existing Failures Policy (STRICT):** Pre-existing Test-Failures (PHPUnit, Vitest, Playwright) MÜSSEN immer behoben werden, bevor neue Arbeit beginnt. Ein "pre-existing" Label oder Ausrede ist nicht erlaubt — jeder Fehlerblock wird analysiert und gefixt, oder als akzeptiertes Risiko in `features/` dokumentiert. Dies gilt auch für flaky Tests: Diese werden bis zur Stabilisierung debugged.

## 4. AI Operating Rules (STRICT)

- **ESLint Auto-Fix Policy (STRICT):**
  Always use `pnpm lint:fix` (= `eslint . --fix --max-warnings 0`) instead of plain `pnpm lint`. Auto-fix handles formatting and trivial rules — never fix those by hand. The plain `lint` script (without `--fix`) is reserved for CI/PR checks only.
- **Text-Einsatz in eine Markdown-Liste: am ENDE des Eintrags verankern, nicht an seinem
  ersten Satz.** Wer einen neuen Eintrag hinter einem bestehenden einfügen will und den Anker auf
  die Kopfzeile des bestehenden setzt, landet mit dem neuen Text **zwischen** dessen Überschrift
  und dessen Körper — der Eintrag wird lautlos zerrissen, und nichts im Ergebnis meldet einen
  Fehler. **Belegt fünffach am 2026-09-28, in einer einzigen Sitzung:** §6 (der „Max 3
  Fix-Versuche"-Satz landete am Ende des D-8-Blocks), §14/D-17 (D-18 und D-19 landeten zwischen
  D-17s Kopf und Körper), §9 (die D-20-Regel landete zwischen der D-18-Überschrift und ihrem
  Absatz), **§4 selbst** — die Regel landete zwischen der ESLint-Überschrift und ihrem Satz, also
  in dem Eintrag, der unmittelbar darüber steht — und `AGENTS.todo.md`, als dieselbe Operation
  **anstatt** eines Einfügens eine Kopfzeile **ersetzte**: der kurze Entscheidungstitel verdrängte
  den Satzanfang, und die erste Körperzeile begann mit „versionierte Compose fordert …" bzw.
  „**Tests**" ohne seinen Anfang (D-1 und D-16). In allen fünf Fällen war die Operation selbst
  korrekt und der Round-Trip-Check sauber; der Fehler fiel erst beim **Lesen** auf. Der vierte
  Fall ist der lehrreichste: die Regel wurde geschrieben und im selben Schritt dagegen verstoßen —
  ein Anker wird offenbar nicht deshalb richtig, dass man gerade darüber gelesen hat.
  **Beide Vektoren, eine Regel:** nicht nur *einfügen* nach, sondern auch *ersetzen* einer
  Kopfzeile vernichtet den Satz, der über die Zeilengrenze in den Körper lief. Wer eine
  Kopfzeile tauscht, muss den ursprünglichen Text als erste Körperzeile wiederherstellen — oder
  den ganzen Eintrag neu schreiben, nicht nur die erste Zeile.
  **Regel:** das letzte Merkmal des Zieleintrags bestimmen, nicht das erste. Und nach jedem
  Text-Einsatz in ein Dokument prüfen, ob Überschrift und Körper des Nachbareintrags **an-
  einander** stehen — nicht nur, ob die Zeilenzahl stimmt.
- **Test Debugging Transparency:** When analyzing test failure reports, you must explicitly document your debugging progress and thought process before proposing a fix. Explain what failed, why it failed based on the logs/DOM snapshots, and how the fix addresses the root cause.
- **Patching & File Modification (CRITICAL):**
  - Multi-line Regex for search-and-replace in code is STRICTLY FORBIDDEN. It is too brittle.
  - Base64 output for file content is STRICTLY FORBIDDEN.
  - **Safe Patching Policy (CRITICAL):** Alle `patch.mjs` Scripts MÜSSEN den Erfolg einer Ersetzung validieren. Prüfe zwingend mit `.includes()` oder `.indexOf()`, ob der Zielstring existiert, *bevor* du `.replace()` aufrufst. Prüfe danach, ob sich der `content` tatsächlich verändert hat. Brich mit einer klaren `console.error` ab, falls der Patch ins Leere läuft. Blinde `.replace()` Aufrufe sind untersagt!

## 5. Agent-Rollen & Delegation

The system and workflow are managed via a Main/Secondary Model architecture to prevent context pollution:

- **Main Model (Planner & Reviewer):** Has the full project context. Analyzes the problem, designs the architecture, updates documentation, and reviews implementations. Delegates isolated coding tasks to the Secondary Model by providing only the necessary files and specific instructions.
- **Secondary Model (Implementer):** Runs in a fresh, isolated context. Receives specific instructions and target files from the Main Model, implements the changes, and generates the patch script.
- **Build-Agent (STRICT):** Ein Build-Agent ist **ausschließlich Orchestrator**. Er darf **bis auf kleine Edits** (Korrektur von Tippfehlern, Sicherheits-/Policy-Anpassungen in `AGENTS.md`/`AGENTS.todo.md` selbst) nur `AGENTS.todo.md` und `AGENTS.md` (sowie direkt dort referenzierte Dateien) lesen und bearbeiten. Jede weitere Datei (Code, Tests, Templates, Komponenten) ist tabu — diese MÜSSEN an Subagenten delegiert werden. **Implementierungs-Delegation erfolgt an den `general`-Subagenten, NICHT an `build`.** Seine Aufgabe ist:
  1. Anforderungen analysieren und in `AGENTS.todo.md` als actionable TODOs dokumentieren.
  2. Umsetzungen an Subagenten (Implementer) **delegieren** — der Build-Agent schreibt selbst keinen Code.
  3. Sofern fachlich sinnvoll **parallel delegieren** (unabhängige Tasks gleichzeitig an mehrere Implementer) — für den Koordination-/Token-Footprint prüfen.
  4. Jede Umsetzung von einem **separaten Subagenten verifizieren** lassen (Review, Tests, Build) — der Verifikator ist NIE der Implementer desselben Tasks.
  5. Bei visuellen Prüfungen (Layout, Screenshots, Bilder, Screenshots-Analyse) den **`vision`-Subagenten** nutzen.
  6. **Kein `git add` und kein Commit, solange ein Subagent in diesem Working Tree läuft.** Der Index ist gemeinsam: `git add AGENTS.md` nimmt mit auf, was ein gleichzeitig arbeitender Agent bereits gestaged hat, und der eigene Commit behauptet dann etwas anderes als sein Inhalt. **Belegt am 2026-09-28:** ein Commit mit dem Titel „docs: fix the contradiction" enthielt 14 Dateien und 408 Zeilen aus `features/` — die Arbeit des laufenden Doku-Agenten. Inhalt war korrekt, die Nachricht falsch, und die Historie beantwortet die Frage „wann wurde der Geldeeinheiten-Vertrag geschrieben" fortan falsch. **Auf die Fertigmeldung warten, danach explizit die eigenen Dateien stagen, und `git show --stat` vor dem Commit prüfen.**
  Diese Regel wurde am 2026-07-31 etabliert, am 2026-08-02 konkretisiert und darf nicht umgangen werden.
  7. **Parallele Agenten bekommen getrennte Nebenzustände — nicht nur getrennte Dateien.** Der Working Tree teilt mehr als den Index: auch jeden Zustand, den ein Agent per Kommando verändert. **Belegt am 2026-09-28:** ein `migrate` auf der gemeinsamen E2E-Datenbank durch einen Agenten, während zwei andere dieselbe DB benutzten — die Folge war ein Flake, der wochenlang als drei getrennte Codefehler geführt wurde. **Regel für parallele Wellen: getrennte Datenbanken je Agent, oder die Datenbankmigration dem Agenten zuweisen, der nach allen anderen fertig ist.** `git stash` hat dieselbe Kollision — zwei Agenten, die ihre Änderung zum Rot-Nachweis wegparken, nehmen sich gegenseitig den Baum weg; stattdessen dateilimitierte Sicherungskopie plus `git checkout -- <eine Datei>`.
  8. **Ein Test, der lokal rot und in CI grün ist, ist zuerst eine Umgebungsfrage — nicht dreimal ein Code-Defekt.** **Belegt am 2026-09-28 an drei unabhängigen Stellen:** `wysiwyg-editor.spec.ts`, die Foto-Testgruppe (`FileDeliveryControllerTest`, `ModelPhoto*`) und `coupon-checkout-revalidation.spec.ts` scheitern lokal und bestehen in CI. Bei allen dreien war die behauptete Ursache widerlegt, nicht bestätigt: die Foto-Gruppe scheitert nachweislich **an keiner** — `Storage::fake` setzt die Wurzel eines gefaekten Disks immer auf `storage_path('framework/testing/disks/…')`, und 15 aufeinanderfolgende Läufe ergaben 0 Fehlschläge; beim Checkout greift im E2E-Lauf `E2E_CHECKOUT_LIMIT=1000` aus `scripts/e2e-up.sh`; der Coupon-Test besteht im CI-Run `36389471967` unter voller Shard-Last. **Bevor so ein Test als Code-Defekt behandelt wird, die Umgebung prüfen.** Und: **keine zweite Ursache erfinden, um sie passend zu machen** — eine widerlegte Hypothese wird sonst durch die nächste widerlegte ersetzt, nicht durch eine begründete.
  9. **Ein Test kann grün sein, ohne etwas zu prüfen.** Ein Watcher muss im selben Verzeichnis liegen wie der Writer, sonst ist die Assertion grün, weil sie in ein leeres Verzeichnis schaut. **Belegt am 2026-09-28:** `AIServiceImageBudgetTest` globbte `sys_get_temp_dir()`, während `AIService` seit `2b60b69` nach `TempDirectory::path('ai')` schrieb — vier Wochen lang grün, ohne die eigene Leck-Erkennung zu prüfen. **Beim Anfassen oder Neuerstellen einer Beobachtungs-Assertion den Schreibpfad gegenprüfen und im Commit belegen** — idealerweise mit der Gegenprobe, dass ein eingebautes Leck die Suite rot macht.

## 6. Testing & E2E (STRICT)

**Tag Policy — E2E tests MUST use Playwright's singular `{ tag: [...] }` option** (`tag` accepts `string | string[]`). Every new test must carry at least one **functional** tag:

- `@smoke` — Critical path (login, guest, auth, basic CRUD). Run after every code change.
- `@regression` — Full functional coverage. Run before deployment.
- `@feature:<name>` — Feature-specific selection (e.g., `@feature:checkout`, `@feature:brand`).

**Device scope:** `@mobile` is an additional device-scope tag for mobile-only gestures or responsive coverage; it does not replace a functional tag. Legacy untagged tests remain part of the full suite until they are tagged, but new tests must not be untagged.

**Execution:**

- Bei jedem Code-Change: `test:e2e:smoke` (`npx playwright test --grep @smoke`)
- Feature-spezifisch: `npx playwright test --grep @feature:<name>`
- Vor Deployment: `test:e2e` (full suite, `npx playwright test`)
- Wiederholung fehlgeschlagener Tests: `npx playwright test --last-failed`; für die Fehleranalyse gelten maximal drei Fix-Versuche (siehe §6).

**Workflow-Reihenfolge für Test-Fixes:**

1. Dokumentieren (SOLL in `features/`, Bug-Analyse)
2. Backend Unit/Integration-Tests schreiben (`php artisan test --filter`)
3. Frontend Unit-Tests schreiben (`pnpm vitest run`)
4. Erst danach: E2E-Tests fixen (`npx playwright test`)

**Max 3 Fix-Versuche für Tests (STRICT):**
Nach 3 erfolglosen Versuchen, einen fehlschlagenden Test zu fixen, MUSS der Agent an den Benutzer zurückgeben mit einer Analyse was schiefgeht. Keine Endlos-Fix-Loops.

**i18n-Regel (D-7):** `scripts/check-i18n.mjs` meldet **auf Satzebene** und unterscheidet
Prosa von Nicht-Prosa — begründet in §14/D-7. Praktisch: `Jahre (geb. {x})`
ist ein Befund, nicht zwei. Das Gate ist **immer fail (Exit 1)**, warn-only ist entfernt
(Owner-Vorgabe, §14/D-7). Wer die 212 einzeln behebt, statt
zuerst die Regel zu erweitern, produziert bei diesem Muster Rauschen ohne Nutzen.

**`frontend/tests/e2e/admin/` (D-8):** wird **nach Domäne** aufgeteilt, analog zu `client/`,
`photographer/`, `crm/`, `delivery/`, `selection/`. Begründet in §14/D-8. Der Umbau ist eine
eigene Arbeit; **kein nebenbei auszuführender Cleanup** — jede Verschiebung ohne vorher
festgelegte Zielstruktur erzeugt nur einen zweiten Zwischenstand.
**Unit-Test-Abdeckung der Management-Views (D-19):** `ManagementOrgsView` und
`ManagementOrgDetailView` besitzen Testdateien. „Konform" ist keine Abdeckung — eine View ohne
Testdatei ist nur durch die Build-Prüfung geschützt, nicht durch eine Prüfung ihres Verhaltens
(begründet in §14/D-19).

## 7. Modules

Module-specific instructions live in per-module `AGENTS.md` files:

- **`frontend/AGENTS.md`** — React Vite SPA: React Compiler policy (`reactCompilerPreset`; `useMemo`/`useCallback`/`React.memo`/`forwardRef` are antipatterns), frontend test/lint/build + Playwright E2E commands, frontend STRICT rules (Tailwind JIT/Only, Zod validation, ESLint & TypeScript, semantic locator scoping, no `page.goto` SPA navigation, localStorage injection, field labels, useEffect & derived state).
- **`backend/AGENTS.md`** — Laravel PHP: backend test command, Database Setup + Migration policy (seed after every migration; the current migration state lives **only** in `features/tech/07-architectural-decisions.md` AD-2 and is never restated here, because a copied number rots silently; a new migration is only for an unavoidable schema requirement), backend parallel testing/paratest rules and worker-DB concurrency.
- **`admin.lrplugin/AGENTS.md`** — Lightroom Classic Lua plugin: scope, key files, and Lua conventions. This is a separate module with its own doc.

The Security Risk Register (accepted risks, resolved C1–C7) is in §8 below and is repo-global.

## 8. Security Risk Register (Accepted Risks)

Bewusst akzeptierte Risiken aus dem Security-Audit (2026-07-11). **Nicht regredieren** — falls der jeweilige Guard entfernt wird, sofort fixen:

- **[C1] ✅ RESOLVED (2026-07-21)** — `JWT_SECRET`-Fallback in `backend/config/jwt.php:18` rotiert. Deployment-Guard in `deployment/docker-compose.yml` auf generische Leerwert-Prüfung umgestellt (keine hartcodierten Secrets mehr).
- **[C2] ✅ RESOLVED (2026-07-21)** — `APP_KEY`-Fallback in `backend/config/app.php:110` rotiert. Gleiche Maßnahme wie C1.
- **[C3] ✅ RESOLVED (2026-07-21)** — `backend/.env.testing` gelöscht; redundante Test-Credentials werden nur in `phpunit.xml` (localhost Fixtures) gehalten. Siehe auch C3b.
- **[C3b] ✅ RESOLVED (2026-07-21)** — Hardcoded `sk_test`/`pk_test`-Fallback in `backend/config/services.php` entfernt. `STRIPE_*`-Env ist nun verpflichtend (Tests verwenden `Config::set()`-Mocks).
- **[C4] ✅ RESOLVED (2026-07-21)** — `frontend/.env` (pk_live) und `frontend/.env.local` (pk_test) untracked; `!.env`/`!.env.local`-Negationen aus `frontend/.gitignore` entfernt. Nur `.env.example`/`.env.local.example` (mit Platzhaltern) committed.
- **[C5-history] ✅ RESOLVED (2026-07-21)** — Git-History mit `git-filter-repo` bereinigt (1. Durchlauf): alle 3 Stripe-Secrets (`pk_live`, `pk_test`, `sk_test`) in allen 133 Commits durch `*_REDACTED`-Platzhalter ersetzt. Force-Push zu GitHub, Reflog expired, GC mit `--prune=now`. Backup: `portal-backup-20260721-133753.bundle`.
- **[C5b-history] ✅ RESOLVED (2026-07-21)** — 2. `git-filter-repo`-Durchlauf: `backend/.env.local` aus History entfernt, APP_KEY-JWT_SECRET-Fallbacks und `SuperSecret123!` durch `*_REDACTED` ersetzt. Force-Push erforderlich. Backup: `portal-backup-20260721-155854.bundle`. Siehe `features/security/env-hardening.md`.

Offene Security-/Infra-TODOs (P1-I1–P1-I8) siehe `AGENTS.todo.md`; P2-T5 ist als reine Dokumentationskorrektur abgeschlossen. Die früheren M-/L-Kennungen sind keine aktuellen Task-IDs mehr.

### Abgeschlossene Security-Hardening (2026-07-11, Historie)

- C5: XSS via `dangerouslySetInnerHTML` → `sanitizeHtml.ts` (DOMPurify)
- C6: Stripe-Webhook Underpayment-Guard → `amount_received < total_amount`-Check
- H1: Text-Snippet-Preview XSS → Defense-in-depth via `sanitizeHtml()`
- H2: IDOR Notification Opt-In → `canAccessGallery()` Guards
- H3: IDOR `MailController::finishRating` → `canAccessGallery($gallery->id)` Guard
- H4: `ManagementMiddleware` null-deref → Null-Check + `auth('api')`
- H6: Security-Header-Middleware → `SetSecurityHeaders.php`
- H7: V025-Migrations-Split → hinfällig (V026 live)

## 9. Bestätigte Stärken (nicht regredieren)

- Brand-/Org-Isolation (`BrandRegistry` + `BrandContextMiddleware` + `forCurrentBrand()`-Scopes)
- httpOnly-Cookie-Auth (kein Token in localStorage/sessionStorage, deduped Refresh)
- Keine Raw-SQL mit User-Input, Shell-Outs via `Process` mit Array-Args
- `$fillable`-Disziplin (kein `$guarded=[]`, kein `Model::create($request->all())`)
- Bildupload mehrstufig validiert (`image`-Rule + `mimes` + `exiftool`-MIME-Check)
- File-Delivery auth-gated (`FileDeliveryController`)
- Frontend-Disziplin (0× `any`/`@ts-ignore`/`eslint-disable`, Zod-Resolver auf allen Forms, ESLint mit `--max-warnings 0` über `pnpm lint:fix`)
- Preisberechnung server-autoritativ (signiertes Offer-Token)
- HTML-Sanitize beim Persistieren (Symfony `HtmlSanitizer`) + beim Render (DOMPurify)
- Vertragssigning mit optimistischer Concurrency (`content_version` in UPDATE-WHERE)
- Keine Eigenbau-Kryptografie (Security-Critical nur etablierte Pakete/Bordmittel, Entscheidung 2026-09-19)

**UI-Verhaltensregeln (Owner-Entscheidungen 2026-09-28, §14/D-9 bis D-14 — nicht regredieren):**

- **Portalname in der Sidebar bricht um**, statt still gekürzt zu werden. `whitespace-nowrap`
  ohne Umbruch ist der Fehler, den D-9 abschafft.
- **Das `<main>`-Scoping im E2E-Harness wird nicht aufgeweicht.** Wenn ein Dialog per `click`
  nicht erreichbar ist, wird die **Struktur** geändert, nicht das Scoping (D-10).
- **`ModalShell` führt eine benannte Höhe** (statische Klasse je Wert) und hat einen
  `bodyHead`/`pinned`-Slot. Der Slot existiert **nur zusammen mit** der Migration der drei
  handge-rollten Dialoge — ungenutzte API in einer Komponente mit 28 Aufrufstellen ist
  toter Code (D-11).
- **`maxWidth` akzeptiert nur `'2xl'`.** `'lg'` und `'xl'` werden nicht still verworfen, sie
  sind nicht im Typ (D-13).
- **Escape und Backdrop lösen in `CouponFormDrawer` die Ungespeichert-Warnung aus**, genau
  wie Schließen und Abbrechen (D-14).
- **`ModalShell` und `ModalDialogShell` nehmen einen `testId` an.** Kein Dialog braucht einen
  Wrapper-`<div>` nur für `data-testid`; wenn doch einer existiert, ist er entweder überflüssig
  oder der `testId` wurde nicht gesetzt (D-18).
- **`ModalDialogShell` kennt kein `editing`.** Der Lösch-Button folgt aus `onDelete`; ein
  `editing`-Prop existiert dort nicht mehr. Wer einen Lösch-Button ohne Handler findet, hat
  einen Aufrufer ohne `onDelete` — das ist ab jetzt ein Fehler, kein Design (D-20).
- **Initial-Fokus wandert in das `autoFocus`-Element des Dialogs**, sonst auf das Schließen-Element.
  Kein Dialog braucht dafür eigenen Fokuscode, und die Shell behält die einzige Fokusverantwortung
  (D-12). **Niemals** ein destruktives Element mit `autoFocus` auszeichnen — die Aktion, die den
  Fehler auslöst, soll nicht den ersten Tastendruck bekommen.

## 10. IntelliJ Run-Configs (.run) — Benennungs-Konvention (STRICT)

Alle Run-Configs in `.run/*.run.xml` folgen einem einheitlichen Schema (etabliert 2026-08-04):

- **Format:** `<Emoji> [<Kategorie>] <Name>.run.xml` — Dateiname UND internes `<configuration name="...">`-Attribut.
- **Ein Emoji pro Kategorie** (thematisch, verbindlich) — **Ausnahme `[Run]`:**

| Kategorie | Emoji |
|---|---|
| `[Setup]` | ⚙️ |
| `[Run]` | **pro Ziel thematisch** — Docker 🐳, Frontend ⚡, Stripe 💳 |
| `[Build]` | 🔨 |
| `[Test]` | 🧪 |
| `[AI]` | ✨ |
| `[Wartung]` | 🔧 |
| `[Deploy]` | 🚀 |
| `[Core]` | 🛑 |
| `[Gefahr]` | 🧨 |

- **`[Run]` = Ausnahme (per-Config):** Start Docker, Start Frontend und Stripe-Tunnel sind konzeptionell **unterschiedliche Kategorien**, auch wenn sie den gleichen `[Run]`-Tag tragen → jedes Run-Ziel bekommt sein eigenes thematisches Emoji. Alle anderen Kategorien nutzen genau ein Emoji.

- **macOS-Regel:** `:` ist in Dateinamen verboten → im Dateinamen wird `: ` als `_ ` geschrieben (`Backend_ Import Locations.run.xml` ↔ intern `name="⚙️ [Setup] Backend: Import Locations"`). Das interne `name`-Attribut trägt immer `: `.
- **Konsistenz:** Dateiname und internes `name` müssen synchron sein (Name ohne `.run.xml`-Endung). Neue Configs MÜSSEN diesem Schema folgen.
- **Sprache:** gemischt erlaubt (deutsche Kategorien `[Wartung]`, `[Gefahr]` und Task-Namen wie „generieren" bleiben).

## 11. CodeGraph — Optionaler Index & Git-Sync-Hook

- **Optionaler lokaler Index:** `.codegraph/.gitignore` ist die einzige versionierte Datei in diesem Verzeichnis. Ein vorhandenes `.codegraph/`-Verzeichnis bedeutet **nicht**, dass ein Index initialisiert ist; `codegraph status` kann in einem frischen Checkout `Not initialized` melden. Index und Daemon-Dateien (`codegraph.db`, Logs) bleiben maschinenlokal. Status: `codegraph status`; Initialisierung: `codegraph init`; Rebuild/Sync: `codegraph index`/`codegraph sync`.
- **Auto-Sync:** Der CodeGraph-Daemon (MCP) watchet die Working Tree und synct laufend (`daemon.log`). Auto-Sync ist ein Best-Effort-Mechanismus, keine Freshness-Garantie.
- **Git-Sync-Hook (optional, versioniert):** `.githooks/pre-commit` ist **fails open** und garantiert keine Index-Frische. Sein Guard prüft nur, ob `.codegraph/` als Verzeichnis existiert (`[ -d .codegraph ]`); er prüft weder `codegraph.db` noch den Initialisierungsstatus. Fehlt das Verzeichnis, wird still übersprungen. Ist das Verzeichnis vorhanden, aber `codegraph` fehlt, ist der Index nicht initialisiert oder `codegraph sync` schlägt fehl, erscheint eine Warnung und der Hook beendet mit Exit 0. Aktiviert wird er lokal mit `git config core.hooksPath .githooks`. **Kanonische Kopie + Template:** `agents-skills/.agents/skills/codegraph-project-setup/templates/pre-commit.sh` (GitHub `reisi007/agents-skills`).
  - **Fails open:** Fehlender `codegraph` auf PATH, ein nicht initialisierter Index oder ein Sync-Fehler führen nur zu einer Warnung; der Hook beendet mit Exit 0. Index-Freshness ist kein Commit-Gate. Repos ohne `.codegraph/`-Verzeichnis werden durch den Guard still übersprungen.
  - Test: `git hook run pre-commit` (git ≥ 2.36) oder direkt `.githooks/pre-commit` ausführen. Ein erfolgreicher Lauf bedeutet nur, dass der Best-Effort-Sync nicht fehlgeschlagen ist, nicht dass ein Index initialisiert wurde.
  - CodeGraph bietet zusätzlich optionale **post**-Hooks (`post-commit`/`post-merge`/`post-checkout`, API `installGitSyncHook()` — für WSL2-Szenarien ohne File-Watcher). Wenn sie benötigt werden, als versionierte Dateien nach `.githooks/` legen (nicht den built-in Installer nutzen, er schreibt nach `.git/hooks/` — das wird bei gesetztem `core.hooksPath` ignoriert).

## 12. Zentrale Skills-Repo (agents-skills) — etabliert 2026-08-18

- **Alle eigenen Skills** leben versioniert in `~/dev/agents-skills/` (GitHub `reisi007/agents-skills`, privat), Struktur `.agents/skills/<id>/SKILL.md` (portable Agent-Skills-Spec). Registriert global via `skills`-Array in `~/.config/opencode/opencode.jsonc` → in **jedem** Projekt verfügbar.
- **Keine eigenen Skills in Projekten** (diese Regel): Projekt-Kopien sind entfernt (`portal/.opencode/skills/`, `open-accreditation/.opencode/skills/`).
- **Skills im Repo (Stand 2026-08-18):** `codegraph-project-setup` (Bootstrap-Runbook, §11), `ui-review` (Playwright-Screenshot-Loop), `agent-config` (globales Setup — opencode.jsonc, MCP, Skills-Registrierung).
- **Ownership:** Offizielle/Third-Party-Packs (daisyui, find-skills, stripe-*, …) bleiben dort, wo der Installer sie ablegt (`~/.agents/skills/`, Projekt-`.agents/skills/`, …); projekt-spezifische Skills (nx-*, blog-beitrag, testimonial) bleiben im Projekt.
- **Erweitern:** neuer Skill als `.agents/skills/<id>/SKILL.md` (Frontmatter `name`+`description`, kebab-case-ID = Ordnername) → Commit+Push; keine Config-Änderung nötig. Details: Skill `agent-config`.

## 13. Deployment & Sync (STRICT)

`sync.sh` kopiert Code, nichts mehr. Er startet keinen Container neu und
führt keine Migration aus. Deshalb gilt:

- **Nach jedem Sync mit PHP-Änderungen: `portal_backend` neu starten.**
  Auf dem Host: `docker restart portal_backend`. Grund: PHP kompiliert
  Klassen und hält das Ergebnis im Prozess (Compiler-/OPcache). Ein rsync
  aktualisiert die Dateien auf der Platte, der laufende Prozess behaelt
  aber die alten Klassen im Speicher. Das Ergebnis ist ein Backend, das
  teils den neuen und teils den alten Code ausfuehrt — und das sieht aus
  wie ein inkonsistenter Zustand, nicht wie ein Caching-Problem. Wer den
  Neustart weglaesst, debuggt einen Fehler, den es nicht gibt.
  Der Neustart seedet **nicht** — aber **erst, seit der Container neu erzeugt wurde**:
  der Entrypoint führt `php artisan app:seed-if-fresh` statt `db:seed --force` aus,
  und das seedet nur eine Datenbank ohne Admin-User. Vorher überschrieb der Neustart
  die 28 autoritativen `settings`-Keys des Seeders — die 28 Keys sind in
  `backend/AGENTS.md` (Database Setup Policy) aufgeführt. **Solange der Container nicht
  neu erzeugt ist, gilt die alte Fassung weiter**, siehe die nächste Regel.
- **Neustart ≠ Recreate (STRICT, 2026-09-28).** `docker restart portal_backend`
  startet einen **bestehenden** Container mit der Konfiguration, mit der er
  **erzeugt** wurde. Änderungen an `command:`, `entrypoint:`, `environment:` oder
  `volumes:` in `deployment/docker-compose.yml` werden dabei **nicht** übernommen —
  sie sind zum Erzeugungszeitpunkt in den Container gebacken. **Belegt:** Der
  Entrypoint wurde von `db:seed --force` auf `app:seed-if-fresh` umgestellt und mit
  `sync.sh` übertragen; nach `docker restart` lief trotzdem wieder
  `INFO Seeding database.`, und **beide** erwarteten Ausgaben von
  `app:seed-if-fresh` erschienen gar nicht. `docker inspect` zeigte
  `Created: 2026-09-28T07:19:33` — vor der Änderung.
  **Regel:** Nach einer Änderung an `command:`, `entrypoint:`, `environment:` oder
  `volumes:` muss der Container **neu erzeugt** werden, nicht neu gestartet. Ein
  Neustart genügt ausschließlich für PHP-, Frontend- und sonstigen Dateiinhalt.
- **Frontend-Sync braucht keinen Neustart.** `dist/` ist statisch, der
  Webserver liest pro Request neu. Er braucht aber einen **aktuellen**
  Bundle — siehe die Frische-Regel unten.
- **Vor jedem Sync mit Frontend-Änderungen: `pnpm lint:fix && pnpm build`.**
  `sync.sh` baut nicht selbst; es überträgt ein **vorgebautes** `dist/`.
  Ein `dist/`, das älter ist als der letzte Frontend-Commit, wird
  kommentarlos übertragen — und der Deploy ist dann erfolgreich, trägt aber
  den alten Stand. **Belege vom 2026-09-28:** `dist/index.html` war von
  18:58, die sechs Frontend-Commits kamen um 19:17/19:18. Die Dialog-Welle
  und die Layout-Korrekturen waren nicht im Bundle.
- **Ein Bundle-Hash-Vergleich beweist Übertragung, nicht Frische.** Host und
  lokal tragen denselben Dateinamen, solange beide veraltet sind — der
  Vergleich sagt dann „identisch" und meint „beide alt". Er ist als
  Integritätsnachweis tauglich und als Freshness-Nachweis wertlos. Für
  Frische zählt die Reihenfolge: **Commit-Zeitstempel des letzten
  Frontend-Commits gegen `mtime` von `dist/index.html`.** Ist das `dist/`
  jünger, ist der Bundle aktuell.
- **Migrationen laufen nicht mit.** `sync.sh` ist kein Deploy im Sinne von
  `migrate --force && db:seed --force`. Wer eine Migration braucht, macht
  sie bewusst und getrennt — und seedet danach, wie `backend/AGENTS.md`
  verlangt.
- **Reihenfolge:** Sync → Neustart → Gegenprobe. Die Gegenprobe ist der
  Health-Check plus ein Request, der den neuen Code zwingt (kein
  `cache:clear`-Verhalten, das der Neustart ohnehin erledigt).

### Owner-Entscheidungen zum Deploy (2026-09-28, §14)

- **D-1 — SFTPGo läuft als `user: "1002:82"`**, nicht `1000:1000`. `deployment/docker-compose.yml`
  ist die entsprechende Datei. Der laufende Stack wurde seit Einführung der `user:`-Zeile nie
  neu erzeugt und läuft als root; der erste spontane `docker compose up -d` fällt deshalb auf
  1000 zurück und bricht `FtpController::process()` (anlegen + `unlink` unter `2755`).
  **Diese Änderung ist ein Recreate, kein Restart** — siehe die Recreate-Regel oben. Deploy
  erst nach grünem CI für genau den gepushten Head. Vorher prüfen, dass auf `/` genug Reserve
  ist: der Deploy-Host stand am 2026-09-28 bei 12 GB frei (D-5), der dev-Host bei 477 GB — die
  Warnung gilt dem Deploy-Host.
- **D-4 — `.env.production` bleibt unverschlüsselt und unrotiert**, als akzeptiertes Risiko.
  Wer eine Rotation vorschlägt, hat die Entscheidung nicht gelesen: sie ist getroffen, und
  ein Repo-Risiko besteht nicht (gitignored, nie committet).
- **D-6 — `API_THROTTLE_LIMIT` steht auf 1000 in `backend/.env.ci`** (Schlüssel
  `API_THROTTLE_LIMIT=1000`), und `backend/.env.example` kommentiert daneben, dass **60 der
  Produktions-Sinnwert** ist und Testumgebungen deutlich höher müssen. `config/app.php`
  (Default 120/5) und `.env.production` bleiben unangetastet. **Bewacht von**
  `ApiThrottleLimitPolicyTest`, das die Datei als Text liest — ein Test auf `config('app.
  throttle_api')` wäre grün, egal was die Datei enthält, und könnte nicht rot werden.
  *Ankerform:* der **Schlüssel**, nicht die Zeilennummer — der eigene Kommentarblock hat die
  Zeile beim Umsetzen von 40 auf 44 verschoben, ein Anker `file:NN` wäre sofort verrottet.

## 14. Entscheidungen des Owners (2026-09-28)

**Diese Liste ist die dauerhafte Quelle für Produkt-, Betriebs- und Strukturentscheidungen —
nicht der Chatverlauf.** Sie entstand am 2026-09-28, als 17 offene Board-Positionen beantwortet
wurden. Format: **Frage → Entscheidung → warum**. Eine Position, die hier steht, ist nicht „noch zu
entscheiden".

**Zwei Leseregeln, ohne sie driftet die Liste:**

- **Ersetzen, nicht daneben stehen lassen.** Eine später verworfene Entscheidung wird an dieser
  Stelle überschrieben. Wer eine alte Zeile liest, muss sie für gültig oder für abgelöst halten
  können — nicht für beides. Die zurückgezogenen Varianten stehen gebündelt im
  Entscheidungs-Protokoll in `AGENTS.todo.md` unter „Verworfen", damit sie nicht erneut
  implementiert werden.
- **Der Umsetzungsstand steht nicht hier, sondern im Protokoll.** Diese Liste sagt, *was* gilt;
  `AGENTS.todo.md` sagt, *ob es schon gilt*. Ein Eintrag hier ohne Zeile im Protokoll ist eine
  Lücke in der Dokumentation, kein Freibrief.

### Betrieb & Deployment

- **D-1 — UID-Modell für SFTPGo (P1-I9, P0):** SFTPGo läuft als **`1002:82`**, der
  Host-Konvention des Website-Baums. `deployment/docker-compose.yml` wird entsprechend
  umgestellt und **automatisch deployt**, sobald der gepushte Head CI-grün ist. *Warum:* das
  versionierte Compose fordert `user: "1000:1000"`, der laufende Stack wurde seit dieser Zeile
  nie neu erzeugt und läuft als root (`docker exec portal_backend id` → `uid=0(root)`). Beim
  nächsten Recreate fällt alles auf 1000 zurück, und mit `2755` auf `ftp/<slug>` kann UID 1000
  **weder anlegen noch `unlink`en** — genau das macht `FtpController::process()`. Eine zweite UID
  im selben Baum zu mischen wäre dieselbe Unordnung wie der `r1`-Vorfall vom 2026-09-26, nur
  leiser. **Grenze:** `AGENTS.md` §13 bleibt bindend — `sync.sh` führt keine Migration aus, und
  `restart` ≠ `recreate`. Der Deploy dieser Änderung ist ein **Recreate**, kein Restart, und
  geschieht erst nach grünem CI.
- **D-2 — Anlage von `ftp/<ftp_slug>` (P1-M24, P1):** die **Software legt das Verzeichnis selbst
  an**, beim Setzen des FTP-Slugs, mit korrekter Ownership und **`2775`**+setgid — Gruppe
  schreibbar, **Welt nicht**. **Schlägt die Anlage fehl, schlägt auch das Setzen des Slugs fehl** —
  mit verwertbarer Meldung und ohne den Slug zu speichern. *Warum:* die SFTPGo-Doku sagt ausdrücklich
  *"you have to create the folder on disk yourself"*. Und der Zustand „Slug gesetzt, Ordner fehlt"
  darf gar nicht erst entstehen können: er ist genau der Zustand, den der Befund verhindert, und er
  wäre später nur noch schwerer zu entdecken. `chown -R` per Entrypoint auf
  `/home/webadmin/websites` bleibt ausgeschlossen
  (`features/infrastructure/19-ftp-upload-pipeline.md` §7.9).
  **Die Zahl `2775` ist eine Grenze in beide Richtungen, und beide Ränder sind belegt:**
  `2777` (Welt schreibbar) ist **ersetzt**, weil jeder Prozess mit Schreibbedarf entweder
  Eigentümer oder in `webgroup` ist und ein Dritter dort nichts zu suchen hat; `2755` (Gruppe nur
  lesbar) **bricht den Import**, weil `FtpController::process()` nach dem Upload `unlink($file)`
  macht und UID 1000 damit weder anlegen noch löschen kann — belegt in `AGENTS.todo.md` unter D-1
  und gewarnt in `tests/scripts/ftp-transport-test/verify.sh:563`. **Tragend ist die
  Gruppenvererbung, nicht das Welt-Schreibrecht:** ohne setgid landet ein Upload in der Gruppe des
  anlegenden Prozesses statt in `webgroup`, und der importierende Backend verliert den Zugriff.
  **Zwei Pfade, weil es zwei waren:** der Slug-Schreibpfad und `provisionAndShow()` (Weg
  „Neues Kamera-Passwort" bei einem `pending`-Konto). Beide rufen
  `App\Support\FtpInboxDirectory::ensure()`. **Reihenfolge: Ordner zuerst, Konto danach.** Die
  SFTPGo-Admin-API hat kein Rollback für einen angelegten User, also ist „Konto zuerst" die
  Reihenfolge, die den verbotenen Zustand erzeugen *kann*; „Ordner zuerst" hinterlässt im
  Fehlerfall höchstens ein leeres Verzeichnis, das der nächste `ensure()` wiederverwendet.
- **D-3 — Branch-Protection für `main`:** **bewusst nicht gesetzt.** *Warum:* Owner-Entscheidung.
  `GET /branches/main/protection` → `404 Branch not protected`; der `CI gate (push)` läuft grün,
  erzwingt sich aber selbst nicht. Trust-Frage an der Repo-/Org-Einstellung, keine Code-Aufgabe.
  **Folge:** das fehlende Gate darf in keinem Audit erneut als Befund aufgerollt werden.
- **D-4 — Produktions-Secret-Handling (P1-I1):** **als akzeptiertes Risiko dokumentiert**, keine
  Rotation, kein Secret-Manager. *Warum:* `.env.production` ist korrekt gitignored
  (`.gitignore:54:.env*`) und war **nie** committet (`git log --all -- .env.production` ist leer).
  Kein Repo-Risiko, nur lokale Hygiene auf einem Single-Host-Deployment. Ein Code-Fix würde das
  Secret nur an einen anderen Ort kopieren.
- **D-5 — Host-Speicher:** gilt dem **Deploy-Host**, nicht dem Entwickler-Rechner. *Warum:*
  gemessen am 2026-09-28 — auf dem dev-Host ist `/` zu **3 %** belegt (477 GB frei) und
  `/projects/LuminaRust` existiert dort nicht; die 97 % / 12 GB frei und die 152 GB gehören zum
  Deploy-Host. Damit ist der Punkt **Deploy-Arbeit** und gehört neben D-1, denn 12 GB Reserve sind
  für einen Stack-Recreate mit Image-Pulls knapp. Der eigene Fußabdruck (~1 GB) ist vernachlässigbar.
- **D-6 — `API_THROTTLE_LIMIT` in CI:** auf **1000** anheben, vergleichbar mit
  `AUTH_THROTTLE_LIMIT=1000` dort (Schlüssel in derselben Datei), und in `backend/.env.example`
  kommentieren, dass **60 der Produktions-Sinnwert** ist und Testumgebungen deutlich höher
  müssen sein. `config/app.php` (Default 120/5) und `.env.production` bleiben
  unangetastet. *Warum:* CI fährt laut eigenem
  Kommentar 4 Playwright-Worker und hat dasselbe Burst-Profil wie lokal mit 8 — die Drosselung ist
  dort latent, auch wenn sie bisher nicht als Fehler auffaellt.

### Test- & Repo-Struktur

- **D-7 — i18n-Regel: Meldungen auf Satzebene, Prosa von Nicht-Prosa getrennt.** Benachbarte
  `JsxText`/`JsxExpression`-Kinder eines Elternknotens bilden **einen** Lauf und werden **einmal**
  gemeldet; ein JSX-Element oder Fragment ist eine harte Grenze. *Warum:* `Jahre (geb. {x})`
  ergibt zwei JsxText-Knoten (`Jahre (geb.` und `)`); einzeln zu beheben ist sinnlos, und ohne die
  Erweiterung produziert die Regel mehr Rauschen als Nutzen. Die Regel musste zuerst lernen, was
  **kein** Satz ist, bevor sie Sätze melden soll. **Gemessen:** 246 → **212** Befunde.
  Befehl, aus `frontend/` — beide Stände dort gemessen, der Vorher-Stand über
  `git show HEAD:frontend/scripts/check-i18n.mjs` in einer Probedatei unter `scripts/`:
  `node --input-type=module -e "import { findUnlocalizedStringsInTree } from
  './scripts/check-i18n.mjs'; console.log(findUnlocalizedStringsInTree().length)"`
  **Fehlerrichtung ist benannt und absichtlich:** `MACHINE_VALUE`, `isUnitFragmentText`,
  `PROSE_LONE_TOKEN_MIN_LENGTH`, `SINGLE_NON_COPY_WORDS` und `PROSE_STOPWORDS` **unterschätzen**
  — sie können echten Text übersehen, und das ist die schlechtere Richtung, weil Rauschen sichtbar
  ist und ein Fund nicht. Die einzige **überzählende** Heuristik ist die Ausnahme für Literale
  neben einem Ausdruck, bewusst so, damit das Zusammenführen keinen vorbestehenden Befund
  verliert. **Owner-Vorgabe: nie warning, immer fail.** Der warn-only-Pfad ist entfernt — Befunde
  sind ab sofort ein harter Fehlschlag (Exit 1), lokal wie in CI, nicht erst bei 0.
  *Warum:* warn-only fault vor sich hin; die 212 standen seit der Regelerweiterung
  unverändert. Preis, bewusst gewählt: der Build ist rot, bis Bestand plus Regelarbeit
  (URL-Filter im JSX-Text-Pfad, die drei Fragment-Befunde, `PLZ`/`BIC`-Grenzfälle) auf 0
  stehen — daneben lief keine andere Frontend-Arbeit mit grünem Build. **Abgearbeitet:**
  Regel −4, helper −27, Attribute −44, jsx-text-Bulk −137; Zähler 0, Build grün, jede Welle
  implementiert und unabhängig verifiziert. Erledigte Folgearbeit: camelCase-`ariaLabel` sieht
  die Regel jetzt (hyphenfreier Abgleich gegen dieselbe Menge, testgepinnt); der Extract-Spawn
  läuft mit bereinigtem Env (`NODE_ENV`-Crash belegt und behoben). Dauer-Residuum per Design:
  Einzel-Token-Unterzählung (28 kurze Texte unter dem Netz, 5-mal deutsch).
- **D-8 — `frontend/tests/e2e/admin/`:** **nach Domäne aufteilen**, analog zu den bereits sauber
  skalierten Verzeichnissen `client/`, `photographer/`, `crm/`, `delivery/`, `selection/`.
  *Warum:* 34 von 89 Specs (38 %) liegen flach in einem Verzeichnis, das Pricing, CRM-Dokumente,
  Tenant-Administration, Galerie-Konfiguration und Projekt-Boards mischt. Der Eintrag hielt fest:
  „Eigene Aufgabe, keine Nebenwirkung eines Cleanup" — ohne Zielstruktur erzeugt jede Verschiebung
  nur einen zweiten Zwischenstand.

### UI-Verhalten

- **D-9 — Portalname in der Sidebar**
  (`frontend/src/ui/components/Sidebar.tsx:51`,
  `<span className="whitespace-nowrap">{portalName}</span>`): das **Layout bricht den Namen
  um**, statt ihn still zu kürzen. *Warum:* `whitespace-nowrap` ohne Truncation lässt einen
  längeren Namen ungebremst überlaufen — genau die Fehlerklasse, die am Mobile-Header
  gerade behoben wurde. Stille Kürzung ohne sichtbaren Effekt ist schlechter als Umbruch; die Asymmetrie
  Mobile (bricht um) vs. Desktop (nowrap) verschwindet damit.
- **D-10 — `<main>`-Scoping im E2E-Harness:** das Scoping wird **nicht aufgeweicht**; stattdessen
  wird die **Struktur geändert**, damit die Dialoge innerhalb von `<main>` rendern.
  *Warum:* `frontend/src/ui/components/DashboardLayout.tsx:88` schließt `</main>`, Z.90 rendert
  `<GalleryModals` — die Dialoge stehen also **nach** `</main>`, und `ModalShell` nutzt
  **kein Portal**; die acht Struktur-View-Instanzen sind per `click` nicht erreichbar. Ein
  Testfix hätte das Scoping geschwächt, ohne die Ursache zu beheben.
- **D-11 — `ModalShell`:** die **benannte Höhe wird eingeführt** (statische Klasse je Wert, kein
  freier Wert) **und die drei handge-rollten Dialoge werden migriert**. `GalleryAccessModal`,
  `PhotographerTeamModal` und `AIBatchEditModal` bekommen den `bodyHead`/`pinned`-Slot, und die
  zwei 80vh-Dialoge hören auf, `max-h`-Utilities zu stapeln. *Warum:* der Footer-Scroll-Befund
  ist seit `4474444` / `659a5a5` erledigt und per Screenshot verifiziert; offen war nur die
  Architekturfrage. Ein Slot **ohne** Migration wäre unbenutzte API in einer Komponente mit 28
  Aufrufstellen und verstößt gegen die No-Dead-Code-Haltung des Repos.
- **D-12 — Initial-Fokus in Dialogen: React `autoFocus` gewinnt.** Der Fokus wandert in das
  Element, das der Dialog selbst als `autoFocus` auszeichnet; ohne diese Auszeichnung bleibt es
  beim heutigen Default (das beschriftete Schließen-Element). Umgesetzt als **Reihenfolge-Entscheidung
  im Trap**, nicht als neuer Prop und nicht als Änderung am DOM. *Warum:* die Recherche hat
  gemessen, dass **23 von 28** Produktionsdialogen mit einem Eingabefeld beginnen (28 = 39
  Aufrufstellen minus 10 in Tests minus 1 der inneren `ModalShell` in `ModalDialogShell.tsx`;
  `grep -rnE '<Modal(Shell|DialogShell)' frontend/src | wc -l`) — der heutige Default auf das
  Schließen-Element ignoriert also fast überall die Datenerfassung. Entscheidend war ein bereits
  vorhandener Widerspruch: `ManagementOrdersView.test.tsx:224` hält fest, dass das Preisfeld
  `autoFocus` trägt und der Trap es danach überschreibt — *„if the price field should win, the
  shell needs an initial-focus hook and this test moves with it"*. Das Repo hatte die Regel also
  geschrieben und selbst abgeschaltet; D-12 macht sie wahr, statt eine vierte zu erfinden.
  **Die drei zuvor verworfenen Varianten bleiben verworfen**, und A ist keine von ihnen: die
  Fokusverantwortung bleibt **eine** (die Shell), kein Dialog bekommt Sondercode, und der Dialog
  benutzt ein Standard-Prop. **Pflichtteil derselben Änderung:** der Rückkehr-Zielpunkt wird
  **vor** dem Mount des Dialoginhalts festgehalten. `useFocusTrap.ts:124` liest `activeElement`
  nach dem React-Commit und erfasst daher bei jedem `autoFocus`-Dialog das Feld **im** Dialog; ohne
  diese Korrektur würde die Umleitung den Fokus beim Schließen nicht auf den Auslöser zurückgeben —
  aus einem Einzelfall würde der Regelfall. *Nicht Teil dieser Entscheidung:* `showModal()` (Variante
  D) bleibt das gründlichere Ziel, weil nur es die Hintergrund-Inertheit mitlöst — sie greift aber
  über `ModalHelper` (`tests/e2e/helpers/ModalHelper.ts:28`, `.modal-open`) in bis zu 36 E2E-Dateien
  ein und ist eine eigene Welle. **React-Falle für den Umsetzer:** React 19 fokussiert `autoFocus`
  imperativ in `commitMount` und rendert **kein** `autofocus`-Attribut — `querySelector('[autofocus]')`
  findet nichts. Die Lösung ist die Reihenfolge, kein Selektor.
- **D-13 — `maxWidth` an `ModalShell`:** der Typ wird auf **`'2xl'`** eingeschränkt. *Warum:*
  heute erzeugt nur `'2xl'` eine Klasse, `'lg'` und `'xl'` werden akzeptiert und still verworfen —
  der Prop lügt. Ein Compile-Error ist ehrlicher als ein Dialog, der sich wie `'2xl'` verhält.
- **D-14 — `CouponFormDrawer`, Escape und Backdrop:** **beide lösen die Ungespeichert-Warnung
  aus**, verwerfen also wie Schließen und Abbrechen den getippten Text. *Warum:* ein Modal, das
  sich nicht schließen lässt, ist eine Sackgasse. Der Verlust ist der Preis, und der Warnhinweis
  wird an vier Wegen statt an zwei gezeigt. Der Nutzer wird es spüren — das ist der bewusst
  gewählte Preis, kein Versehen.

### Produkt, Recht, Policy

- **D-15 — Altersnachweis: kein Löschkonzept, unbegrenzte Aufbewahrung, bewusst so.** Die
  technische Folge: kein Löschpfad, keine Frist, die UI bleibt unverändert
  (`ProfileEditForm` blendet das Feld nur bei
  `profile.age_proof_required && !profile.age_proof_uploaded_at` ein). *Warum:* Owner-Entscheidung
  vom 2026-09-28. **Als Fakt, nicht als Streitfrage:** ein Ausweisdokumenten-Scan ist ein
  personenbezogenes Datum nach Art. 4 Abs. 1 DSGVO, und Art. 5 Abs. 1 lit. e verlangt eine
  *bestimmte* Speicherdauer — „unbegrenzt" ist also selbst eine Position, die begründet werden
  muss. Sie ist hier begründet: bewusst, als Entscheidung, und nicht als Versehen. Die
  zurückgezogene Alternative (Frist setzen, Löschpfad implementieren) steht im Protokoll unter
  „Verworfen".
- **D-16 — Stripe-Identifikatoren (Customer-/PI-IDs, IP(+Hash), Fingerprint, Failure-Codes,
  Turnstile):** **vorerst nur technisch konsistent** dokumentieren, der rechtliche Teil bleibt eine
  **benannte Lücke mit Owner**. *Warum:* Zweck, Legal Ground, Retention und
  Lösch-/Anonymisierungsregeln sind fachliche DPO-/Rechtsfreigabe, und es gibt keinen DPO in
  Reichweite. `Privacy.tsx` enthält bereits einen technischen Teilabschnitt; ihn zu vervollständigen
  ist belegbar, den rechtlichen Teil zu erfinden nicht.
- **D-17 — Betriebs-Policies einzeln festgelegt.** Prompt-Injection und SMTP-Duplicate betreffen
  zwei Systeme mit zwei Reviewern und werden deshalb **getrennt** entschieden, nicht gebündelt. Die
  Queue-/Mail-/Worker-/Scheduler-Evidenz bleibt ein eigener Eintrag: das ist Betriebsnachweis, keine
  Policy.
  - **SMTP-Duplikate (entschieden):** die beiden Fenster schließen, die eine Nutzeraktion
    **zerstören** oder eine Nachricht **senden**, obwohl der Zustand es schon erledigt hat. Erstens
    der Reset-/Aktivierungspfad: umgesetzt als Claim in `ActivationTokenService::issue()` — ein noch
    gültiger Token wird nie ersetzt, der zweite Aufruf gibt `null` zurück und sendet keine zweite
    Mail; aufgerufen in `AuthController`, `UserController` und `ModelRegistrationController`
    (dritte Stelle, identischer Defekt). Ein zweiter Klick macht den Link der ersten Mail damit nicht
    mehr tot.
    Das ist die einzige Duplikatklasse mit dieser Folge. Zweitens das Crashfenster im Scheduler:
    `ProcessModelLifecycle.php:114-121` reiht die Erinnerungsmail ein und speichert **erst danach**
    `last_reminder_stage`, ohne Transaktionskopplung; ein Absturz dazwischen sendet am nächsten Tag
    erneut (geschlossen: Claim vor Enqueue in **einer** Transaktion — `DB::transaction` mit
    `lockForUpdate`, `ModelAccessToken::issueFor()` innerhalb, Log erst nach Commit; gepinnt durch
    `ModelLifecycleReminderDispatchTest`, 3 Tests, gegen HEAD gegenbelegt). **Bewusst nicht gemacht:** kein flächendeckendes `ShouldBeUnique` über alle
    Mails — gemessen kommt `ShouldBeUnique` und `uniqueId` im Repo **keinmal** vor, die Claims auf
    Invoice, Dispute, Quote, Webhook, Scheduler und Vertragsabschluss sind getestet, und ein
    beobachtetes Duplikat gibt es nicht. Die verbleibende Klasse (SMTP-Retry nach Transportfehler)
    ist dokumentiert und bewusst akzeptiert: eine zusätzliche Kopie einer Rechnung, keine zweite
    Belastung und kein zweiter Zustandswechsel.
  - **Prompt-Injection (entschieden — umgesetzt):** das Risiko **schriftlich festhalten, wie es wirklich
    ist** (so geschehen), und der irreführende Testname ist korrigiert:
    `AIServicePromptInjectionTest` heißt jetzt `AIServiceUntrustedInputContractTest` — Docblock sagt,
    was er belegt (Request-Vertrag) und was nicht (Modell-Gehorsam). Neu behauptet nur Belegbares:
    die drei Felder verlassen ihren Block nie, und der `detected_city`-Lookup bleibt lesend
    (`AIDetectedCityLookupReadOnlyTest`, Query-Log mit Non-Emptiness-Guard). *Was das Risiko ist:*
    jemand mit Metadatenrecht tippt Text in eines von drei Feldern
    (`global_context`, `specific_context` je max 1000 Zeichen, `text_input` max 2000), der mit dem
    Foto in den Prompt geht. Ein Modell kann die Grenze zwischen Daten und Anweisung verlieren
    („Ignore previous instructions …") — die `<`/`>`-Maskierung hält das **nicht** auf, denn der
    Angriff braucht kein solches Zeichen. *Wie begrenzt:* gemessen hat das Modell **keine** Tools,
    **keine** Retrieval-Fähigkeit und **keine** Aktionsbefugnis; die Antwort sind fünf begrenzte
    Metadatenfelder, und **nichts wird ohne menschlichen Klick gespeichert** (der einzige
    serverseitige Aufrufer ist der Controller). Der trägende Schutz ist damit **menschliche
    Prüfung**, nicht der Systemprompt. **Was das
    ausdrücklich nicht kauft:** Gehorsam des Modells. Der ist ohne Live-Provider nicht testbar,
    und jeder Test, der das behauptet, misst sich selbst. *Nicht gemacht:* weder JSON-Block noch
    getrennte Turns noch Ausgabefilterung — bei gemessener Reichweite und menschlicher Freigabe
    trägt der Aufwand den Schaden nicht. *Randnotiz, kein Vektor:* Markenname, Galeriename, EXIF
    und Dateiname gelangen **nicht** in den Prompt, und es gibt keinen OCR-Pfad.
- **D-18 — `testId` als Prop auf `ModalShell` und `ModalDialogShell`:** die Schells
  nehmen einen optionalen `testId` an, damit kein Dialog einen Wrapper-`<div>` braucht, dessen
  einziger Zweck `data-testid` ist. *Warum:* `ModelInviteDialog` trug genau einen solchen Wrapper,
  durch den die E2E-Referenzen auf diesen Dialog scopen. **Die Zahl 8 aus der ersten Fassung
  dieses Eintrags war falsch und ist ersetzt:** gemessen sind **7** Referenzen, davon **3**
  `expect`-Assertions und 4 Locator-/Click-Ketten — `grep -rn 'model-invite-dialog' frontend/tests | wc -l` → 7, davon `grep -c expect` → 3 Assertions und 4 Locator-/Click-Ketten. Bei 28 Aufrufstellen ist
  „selten" aber nicht „nie" — die andere Alternative, die Assertions auf sichtbare Merkmale
  umzustellen, macht die Assertions unschärfer: sie prüfen dann nicht mehr, welches
  Dialogelement gemeint ist. **Ersetzt, nicht ergänzt:** der Zustand bei HEAD war bereits
  halb umgesetzt, nur unter dem anderen Namen `boxTestId` und nur an `ModalShell`; D-18 hat
  den Namen auf `testId` vereinheitlicht und `ModalDialogShell` ergänzt.
- **D-19 — `ManagementOrgsView` und `ManagementOrgDetailView` bekommen Unit-Testdateien**, beide,
  nach dem Muster der übrigen Management-Views. *Warum:* beide waren „jetzt konform, aber
  ungeschützt" — die Konformität schließt die Lücke nicht, sie verlagert sie auf die
  Build-Prüfung. Ohne Testdatei ist die Formulierung eine Feststellung ohne Deckung.
- **D-20 — `ModalDialogShell` verliert das `editing`-Prop; der Lösch-Button wird aus
  `onDelete` abgeleitet.** Die Shell rendert `Löschen` **nur**, wenn `onDelete` übergeben ist.
  *Warum:* gemessen ist das Prop eine einzige Sorge, an genau einer Stelle gelesen
  (`ModalDialogShell.tsx:84`) — es gibt nichts zu splitten. Und die Umbenennung in ein
  negierendes `readOnly` hätte den einzigen **erreichbaren** Defekt nicht behoben:
  `VolumePresetSettingsCard.tsx:118` übergab `editing={!!initialName}` **ohne** `onDelete`, also
  renderte dort seit `e053f70` (2026-08-13) bis zur Umsetzung ein `Löschen`-Button mit
  `onClick={undefined}` — kein Fehler, keine Warnung, nur ein toter Button. Aus `editing={!!initialName}` würde
  `readOnly={!initialName}`; beim Bearbeiten ist `initialName` truthy, der Button rendert
  weiter. Der Rename hätte den Namen geändert, nicht den Button. Diese Herleitung macht
  „Button ohne Handler" strukturell nicht darstellbar und lässt ein **required** Prop aus 20
  Aufrufstellen **verschwinden**, statt es in 20 umzubenennen. **Pflicht-Schritt dabei:**
  `GalleryModal` und `GalleryGroupModal` übergaben `onDelete` bedingungslos; seit der Umsetzung
  tun sie es nur bei existierendem Datensatz — sonst bekäme der Create-Dialog einen
  Lösch-Button. **Umgesetzt:** die Shell kennt kein `editing` mehr, der Button folgt aus
  `onDelete`; der tote Button in `VolumePresetSettingsCard` entfiel ersatzlos. Gepinnt durch
  `ModalDialogShell.test.tsx` (mit/ohne `onDelete`) sowie je einen Create-/Edit-Fall in
  `GalleryModal.test.tsx` und `GalleryGroupModal.test.tsx`.
- **D-21 — die fünf Dialoge benennen ihr Domänen-`editing` um.** `ProjectModal`,
  `PhotoJobModal`, `TextSnippetModal`, `ProductModal`, `CustomerModal` und `CouponFormDrawer`
  führen ein Domänen-Prop mit demselben Namen, das der Shell-Prop hatte. *Warum:* D-20 nimmt den
  Shell-Bedeutungsträger weg, aber das Wort bliebe zweimal im selben Komponentenbaum — einmal
  fachlich, einmal strukturell. Die Kollision war bis zur Umsetzung eine **dokumentierte Falle**,
  kein lebender Durchschlag: die Dialoge gaben literal `editing={false}`, gepinnt durch
  `editingDeleteActionSemantics.test.tsx`. Der Rename beseitigt die Wortgleichheit restlos; die
  Aufrufer in `ManagementProjectsBoard.tsx:219` und `PhotographerProductionBoard.tsx:170` reichen
  das Domänen-Prop durch und müssen mitziehen. **Nebenbefund, ausdrücklich festgehalten:** das Board
  sprach von „fünf Dialogen" und nannte vier — die fünfte ist `ProjectModal`.
  **Umgesetzt:** lokal umbenannt (`snippet`, `product`, `customer`, `coupon`, `project`,
  `photoJob`); die externen Props heißen weiter `editingSnippet`/`editingProduct`/
  `editingCustomer`/`editingCoupon` bzw. `editing` (`ProjectModal`, `PhotoJobModal`), die Aufrufer
  sind unverändert. Gepinnt wird die Konsequenz, nicht die Benennung:
  `editingDeleteActionSemantics.test.tsx` öffnet alle sechs auf existierenden Datensätzen und
  assertiert keinen Lösch-Button. Ein späterer externer Rename wäre eine neue Entscheidung,
  kein Teil von D-21.
- **D-22 — Anlagefehler der SFTPGo-Verzeichnis-Anlage: einheitlich `503`.** Der Reset-Endpunkt
  antwortete mit `422`, der Slug-Pfad mit `500` — dieselbe Ursache, zwei Codes. *Warum:* ein Grund,
  ein Status. Ein fehlender Mount / nicht anlegbares Verzeichnis ist ein Host-/Config-Fehler, kein
  Client-Fehler; `503` liest sich wie die bereits bestehende SFTPGo-Unerreichbarkeit. `422` bleibt den
  Portal-Voraussetzungen. *Entscheidung vom 2026-09-29 (Owner, interaktiv):* `503` vor `422`/`500`.
  **Umgesetzt:** `AuthController` (500→503) und `FtpCredentialController` (beide
  `REASON_UNCREATABLE_*`-Arme vor dem 422-Default) mit Begründung an der Ursache
  (`FtpCredentialException::couldNotCreateInboxDirectory()`); Gleichheit beider Pfade gepinnt durch
  `FtpSlugDirectoryProvisioningTest` (gegen HEAD gegenbelegt: `500 is identical to 503`). Kein
  `Retry-After` — passend zum bestehenden SFTPGo-503.
## TODO (UI-Review)

UI-Review-Screenshot-Skill noch nicht angewendet (Playwright-Harness + Vision-Analyse). Referenz: ocg-price-tracker/tests/screenshots (ui-screenshots.spec.ts mit Section-Captures).
