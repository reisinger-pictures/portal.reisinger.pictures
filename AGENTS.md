# AI Operating Guidelines & Doc-as-Code Policy

**CRITICAL ROLE:** Behandle den Benutzer bei allen Antworten und technischen Entscheidungen vom Fachwissen her wie einen Senior Architekten. Die direkte Anrede "Senior Architekt" ist jedoch untersagt.

## 0. Definition of Done (DoD)

Ein Task gilt nur dann als **abgeschlossen**, wenn BEIDE Kriterien erfüllt sind:

1. **Tests existieren**
   - Backend-Änderungen (Controller, Services, Modelle, Gates, Middleware): → **PHPUnit Feature/Unit Tests**
   - Frontend-Logik (Hooks, Utils, API-Layer): → **Vitest Unit Tests**
   - Frontend-UI/Komponenten (Views, Modals, Formulare): → **Playwright E2E Tests**
   - Bug-Fixes: → **mindestens ein Regression-Test**, der den Bug reproduziert (PHPUnit oder E2E)
   - Refactoring / Dead-Code-Removal: → kann ohne Tests auskommen, muss im Commit begründet werden

2. **Codequalität ist gut**
   - Frontend: `pnpm lint:fix && pnpm build` (oder `tsc -b`) läuft fehlerfrei
   - Backend: `php artisan test` (alle bestehenden Tests grün)
   - Keine `eslint-disable`, `@ts-ignore` oder `any`
   - Keine blinden `.replace()`-Patches (Safe-Patching-Policy)

## 1. AI Workflow & TODO Management
* **Planning Phase:** Always start your response with a clear "**Planungsphase**" and review `AGENTS.todo.md`.
* **Language Policy:** Code & Docs: English. UI: German.
* **Documentation Policy (CRITICAL — features/ vs AGENTS.todo.md):**
  * `features/` = **dauerhafter SOLL-Zustand** des Systems. Hier landen nur Architekturentscheidungen, Datenmodelle, API-Verträge und Feature-Spezifikationen, die langfristig gültig sind.
  * `AGENTS.todo.md` = **temporäre Task-Liste** + Code-Review-Notizen + Bug-Analysen + Session-Tracking. Alles, was nur für die aktuelle Session oder den nächsten PR relevant ist, gehört hierher, **nicht** in `features/`.
  * Code-Reviews, temporäre Analysen und Diskussionen → `AGENTS.todo.md`. Nur wenn ein neuer SOLL-Zustand definiert wird → `features/`.
* **Task & Test Tracking:** Every feature requires actionable TODOs in `AGENTS.todo.md`. You MUST explicitly include TODOs for writing test cases (PHPUnit for backend, Playwright for E2E).
* **Zero Pre-existing Failures Policy (STRICT):** Pre-existing Test-Failures (PHPUnit, Vitest, Playwright) MÜSSEN immer behoben werden, bevor neue Arbeit beginnt. Ein "pre-existing" Label oder Ausrede ist nicht erlaubt — jeder Fehlerblock wird analysiert und gefixt, oder als akzeptiertes Risiko in `features/` dokumentiert. Dies gilt auch für flaky Tests: Diese werden bis zur Stabilisierung debugged.

## 2. E2E Tag Policy (STRICT)

* **E2E tests MUST be tagged** using Playwright's `{tag: [...]}` syntax (`tag` accepts `string | string[]`). Every test gets one of these tiers:
  * `@smoke` — Critical path (login, guest, auth, basic CRUD). Run after every code change.
  * `@regression` — Full functional coverage. Run before deployment.
  * `@feature:<name>` — Optional, for feature-specific selection (e.g., `@feature:checkout`, `@feature:brand`).
* **Device-specific tests** (mobile-only gestures, responsive layout) use `@mobile` tag.
* **New E2E tests MUST include at least one tag.** If the test is critical path, use `@smoke`. If it's feature-specific, use `@feature:<name>`. Deep edge cases can remain untagged but will only run in the full pre-deployment suite.

## 3. AI Operating Rules (STRICT)
* **ESLint Auto-Fix Policy (STRICT):** Always use `npm run lint:fix` (= `eslint . --fix`) instead of plain `npm run lint`. Auto-fix handles formatting and trivial rules — never fix those by hand. The plain `lint` script (without `--fix`) is reserved for CI/PR checks only.
* **Test Debugging Transparency:** When analyzing test failure reports, you must explicitly document your debugging progress and thought process in the "Planungsphase" before proposing a fix. Explain what failed, why it failed based on the logs/DOM snapshots, and how the fix addresses the root cause.
* **Patching & File Modification (CRITICAL):**
  * Multi-line Regex for search-and-replace in code is STRICTLY FORBIDDEN. It is too brittle.
  * Base64 output for file content is STRICTLY FORBIDDEN.
  * **Safe Patching Policy (CRITICAL):** Alle `patch.mjs` Scripts MÜSSEN den Erfolg einer Ersetzung validieren. Prüfe zwingend mit `.includes()` oder `.indexOf()`, ob der Zielstring existiert, *bevor* du `.replace()` aufrufst. Prüfe danach, ob sich der `content` tatsächlich verändert hat. Brich mit einer klaren `console.error` ab, falls der Patch ins Leere läuft. Blinde `.replace()` Aufrufe sind untersagt!

## 4. AI Agent Roles & Responsibilities
The system and workflow are managed via a Main/Secondary Model architecture to prevent context pollution:
* **Main Model (Planner & Reviewer):** Has the full project context. Analyzes the problem, designs the architecture, updates documentation, and reviews implementations. Delegates isolated coding tasks to the Secondary Model by providing only the necessary files and specific instructions.
* **Secondary Model (Implementer):** Runs in a fresh, isolated context. Receives specific instructions and target files from the Main Model, implements the changes, and generates the patch script.
* **Build-Agent (STRICT):** Ein Build-Agent ist **ausschließlich Orchestrator**. Er darf **bis auf kleine Edits** (Korrektur von Tippfehlern, Sicherheits-/Policy-Anpassungen in `AGENTS.md`/`AGENTS.todo.md` selbst) nur `AGENTS.todo.md` und `AGENTS.md` (sowie direkt dort referenzierte Dateien) lesen und bearbeiten. Jede weitere Datei (Code, Tests, Templates, Komponenten) ist tabu — diese MÜSSEN an Subagenten delegiert werden. Seine Aufgabe ist:
  1. Anforderungen in der **Planungsphase** analysieren und in `AGENTS.todo.md` als actionable TODOs dokumentieren.
  2. Umsetzungen an Subagenten (Implementer) **delegieren** — der Build-Agent schreibt selbst keinen Code.
  3. Sofern fachlich sinnvoll **parallel delegieren** (unabhängige Tasks gleichzeitig an mehrere Implementer) — für den Koordination-/Token-Footprint prüfen.
  4. Jede Umsetzung von einem **separaten Subagenten verifizieren** lassen (Review, Tests, Build) — der Verifikator ist NIE der Implementer desselben Tasks.
  5. Bei visuellen Prüfungen (Layout, Screenshots, Bilder, Screenshots-Analyse) den **`vision`-Subagenten** nutzen.
  Diese Regel wurde am 2026-07-31 etabliert, am 2026-08-02 konkretisiert und darf nicht umgangen werden.
* **E2E Execution (STRICT):** Playwright-Tests per Tag ausführen (siehe §2). Bei jedem Code-Change: `test:e2e:smoke`. Vor Deployment: `test:e2e` (full suite). Für Wiederholung fehlgeschlagener Tests: `npx playwright test --last-failed`.
* **Workflow-Reihenfolge für Test-Fixes (STRICT):**
  * 1. Dokumentieren (SOLL in `features/`, Bug-Analyse)
  * 2. Backend Unit/Integration-Tests schreiben (`php artisan test --filter`)
  * 3. Frontend Unit-Tests schreiben (`pnpm vitest run`)
  * 4. Erst danach: E2E-Tests fixen (`npx playwright test`)
* **Max 3 Fix-Versuche für Tests (STRICT):** Nach 3 erfolglosen Versuchen, einen fehlschlagenden Test zu fixen, MUSS der Agent an den Benutzer zurückgeben mit einer Analyse was schiefgeht. Keine Endlos-Fix-Loops.

## 5. Modules

Module-specific instructions live in per-module `AGENTS.md` files:

- **`frontend/AGENTS.md`** — React Vite SPA: React Compiler policy (`reactCompilerPreset`; `useMemo`/`useCallback`/`React.memo`/`forwardRef` are antipatterns), frontend test/lint/build + Playwright E2E commands, frontend STRICT rules (Tailwind JIT/Only, Zod validation, ESLint & TypeScript, semantic locator scoping, no `page.goto` SPA navigation, localStorage injection, field labels, useEffect & derived state).
- **`backend/AGENTS.md`** — Laravel PHP: backend test command, Database Setup + Migration policy (seed after every migration; V027 is the latest deploy-ready migration), backend parallel testing/paratest rules and worker-DB concurrency.
- **`admin.lrplugin/AGENTS.md`** — Lightroom Classic Lua plugin: scope, key files, and Lua conventions. This is a separate module with its own doc.

The Security Risk Register (accepted risks, resolved C1–C7) is in §7 below and is repo-global.

## 7. Security Risk Register (Accepted Risks)

Bewusst akzeptierte Risiken aus dem Security-Audit (2026-07-11). **Nicht regredieren** — falls der jeweilige Guard entfernt wird, sofort fixen:

- **[C1] ✅ RESOLVED (2026-07-21)** — `JWT_SECRET`-Fallback in `backend/config/jwt.php:18` rotiert. Deployment-Guard in `docker-compose.yml` auf generische Leerwert-Prüfung umgestellt (keine hartcodierten Secrets mehr).
- **[C2] ✅ RESOLVED (2026-07-21)** — `APP_KEY`-Fallback in `backend/config/app.php:110` rotiert. Gleiche Maßnahme wie C1.
- **[C3] ✅ RESOLVED (2026-07-21)** — `backend/.env.testing` gelöscht; redundante Test-Credentials werden nur in `phpunit.xml` (localhost Fixtures) gehalten. Siehe auch C3b.
- **[C3b] ✅ RESOLVED (2026-07-21)** — Hardcoded `sk_test`/`pk_test`-Fallback in `backend/config/services.php` entfernt. `STRIPE_*`-Env ist nun verpflichtend (Tests verwenden `Config::set()`-Mocks).
- **[C4] ✅ RESOLVED (2026-07-21)** — `frontend/.env` (pk_live) und `frontend/.env.local` (pk_test) untracked; `!.env`/`!.env.local`-Negationen aus `frontend/.gitignore` entfernt. Nur `.env.example`/`.env.local.example` (mit Platzhaltern) committed.
- **[C5-history] ✅ RESOLVED (2026-07-21)** — Git-History mit `git-filter-repo` bereinigt (1. Durchlauf): alle 3 Stripe-Secrets (`pk_live`, `pk_test`, `sk_test`) in allen 133 Commits durch `*_REDACTED`-Platzhalter ersetzt. Force-Push zu GitHub, Reflog expired, GC mit `--prune=now`. Backup: `portal-backup-20260721-133753.bundle`.
- **[C5b-history] ✅ RESOLVED (2026-07-21)** — 2. `git-filter-repo`-Durchlauf: `backend/.env.local` aus History entfernt, APP_KEY-JWT_SECRET-Fallbacks und `SuperSecret123!` durch `*_REDACTED` ersetzt. Force-Push erforderlich. Backup: `portal-backup-20260721-155854.bundle`. Siehe `features/security/env-hardening.md`.

Offene Security-TODOs (M6, L2) siehe `AGENTS.todo.md`. M1–M5, M7–M9, L1, L3–L5 sind erledigt (Commit `0f10091` + Session 2026-07-14).

### Abgeschlossene Security-Hardening (2026-07-11, Historie)

- C5: XSS via `dangerouslySetInnerHTML` → `sanitizeHtml.ts` (DOMPurify)
- C6: Stripe-Webhook Underpayment-Guard → `amount_received < total_amount`-Check
- H1: Text-Snippet-Preview XSS → Defense-in-depth via `sanitizeHtml()`
- H2: IDOR Notification Opt-In → `canAccessGallery()` Guards
- H3: IDOR `MailController::finishRating` → `canAccessGallery($gallery->id)` Guard
- H4: `ManagementMiddleware` null-deref → Null-Check + `auth('api')`
- H6: Security-Header-Middleware → `SetSecurityHeaders.php`
- H7: V025-Migrations-Split → hinfällig (V026 live)

## 8. Bestätigte Stärken (nicht regredieren)

- Brand-/Org-Isolation (`BrandRegistry` + `BrandContextMiddleware` + `forCurrentBrand()`-Scopes)
- httpOnly-Cookie-Auth (kein Token in localStorage/sessionStorage, deduped Refresh)
- Keine Raw-SQL mit User-Input, Shell-Outs via `Process` mit Array-Args
- `$fillable`-Disziplin (kein `$guarded=[]`, kein `Model::create($request->all())`)
- Bildupload mehrstufig validiert (`image`-Rule + `mimes` + `exiftool`-MIME-Check)
- File-Delivery auth-gated (`FileDeliveryController`)
- Frontend-Disziplin (0× `any`/`@ts-ignore`/`eslint-disable`, Zod-Resolver auf allen Forms, `lint --max-warnings 0`)
- Preisberechnung server-autoritativ (signiertes Offer-Token)
- HTML-Sanitize beim Persistieren (Symfony `HtmlSanitizer`) + beim Render (DOMPurify)
- Vertragssigning mit optimistischer Concurrency (`content_version` in UPDATE-WHERE)

## 9. IntelliJ Run-Configs (.run) — Benennungs-Konvention (STRICT)

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

