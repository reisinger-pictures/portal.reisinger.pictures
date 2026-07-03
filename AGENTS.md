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
* **Documentation (SOLL-ZUSTAND REQUIRED):** The single source of truth for all technical concepts is the `features/` directory. BEFORE implementing any new logic, the target state (Soll-Zustand) MUST be thoroughly documented in a corresponding Markdown file under `features/`.
* **Task & Test Tracking:** Every feature requires actionable TODOs in `AGENTS.todo.md`. You MUST explicitly include TODOs for writing test cases (PHPUnit for backend, Playwright for E2E).

## 2. AI Operating Rules (STRICT)
* **useEffect & Derived State Policy (STRICT):** Forbid the use of `useEffect` for side effects triggered by user events (e.g. creating object URLs). Handlers MUST perform these actions. Forbid the use of `useState` for values that can be derived during rendering.
* **Tailwind JIT Policy (STRICT):** Dynamische String-Konkatenation für Tailwind-Klassen (z.B. `btn-${color}`) ist **strikt verboten**, da der JIT-Compiler diese beim Build-Prozess übersieht und restlos entfernt (Purge). Klassen müssen immer vollständig und statisch ausgeschrieben werden (z.B. per explizitem Mapping-Objekt oder Ternary-Operator).
* **Validation (Zod) Policy (STRICT):** * Alle `react-hook-form` Implementierungen MÜSSEN `@hookform/resolvers/zod` nutzen.
  * Daten aus unsicheren, lokalen Quellen (wie `localStorage`) MÜSSEN via Zod geparst werden (`safeParse` oder `catch`), bevor sie in den State übernommen werden.
* **ESLint Auto-Fix Policy (STRICT):** Always use `npm run lint:fix` (= `eslint . --fix`) instead of plain `npm run lint`. Auto-fix handles formatting and trivial rules — never fix those by hand. The plain `lint` script (without `--fix`) is reserved for CI/PR checks only.
* **ESLint & TypeScript:** The use of `eslint-disable`, `@ts-ignore`, or `any` is **strictly forbidden**. All typing issues must be resolved structurally using exact interfaces, `unknown`, or generic type constraints.
* **Semantic Locator Scoping:** Agenten MÜSSEN Playwright-Locators über Landmarks (`main`, `aside`, `footer`) scopen, um Eindeutigkeit sicherzustellen und Abhängigkeiten von rein visuellen CSS-Klassen zu minimieren.
* **No `page.goto` for SPA Navigation (STRICT):** `page.goto()` ist ein Anti-Pattern und darf nicht für SPA-Navigation verwendet werden. Ausnahmen:   
  * Externe Links (Invite, Magic-Link, Setup-Link)   
  * Initialer Seitenaufruf bei Gästen (`/`)   
  * Route-Guard-Tests (direkter URL-Zugriff testen)   
  * Brand-Isolation-Tests (Cross-Domain-Navigation)   
  * Stripe-Redirect-Simulation (return_url nach Zahlung)   
  * Navigation MUSS via `sidebar.navigateTo()`, Klicks im UI oder API-Aufrufe erfolgen. localStorage-Injektion + page.goto('/cart') ist verboten — Cart-Items MÜSSEN via API hinzugefügt werden.
* **Testing Execution Output:** Whenever you create or modify E2E or PHPUnit tests, you MUST output the exact command to run them (and the input string for the `ai_test_runner.mjs`) in a separate code block at the end of your response.
* **Test Debugging Transparency:** When analyzing test failure reports, you must explicitly document your debugging progress and thought process in the "Planungsphase" before proposing a fix. Explain what failed, why it failed based on the logs/DOM snapshots, and how the fix addresses the root cause.
* **Patching & File Modification (CRITICAL):**
  * Multi-line Regex for search-and-replace in code is STRICTLY FORBIDDEN. It is too brittle.
  * Base64 output for file content is STRICTLY FORBIDDEN.
  * **Safe Patching Policy (CRITICAL):** Alle `patch.mjs` Scripts MÜSSEN den Erfolg einer Ersetzung validieren. Prüfe zwingend mit `.includes()` oder `.indexOf()`, ob der Zielstring existiert, *bevor* du `.replace()` aufrufst. Prüfe danach, ob sich der `content` tatsächlich verändert hat. Brich mit einer klaren `console.error` ab, falls der Patch ins Leere läuft. Blinde `.replace()` Aufrufe sind untersagt!
* **Migration Policy (CRITICAL):** Bei jeder Migration muss der Agent vorher nachfragen, ob die Änderung als **neue, separate Migration** oder als **Erweiterung der aktuell letzten Migration** erfolgen soll. V017 ist die letzte deployte Migration. Vor dem Deployment werden alle Nicht-Produktions-Migrationen (≥ V018) zu EINER konsolidierten Migration zusammengefasst. Diese Regel verhindert eine übermäßig fragmentierte Migrations-Historie.

## 3. AI Agent Roles & Responsibilities
The system and workflow are managed via a Main/Secondary Model architecture to prevent context pollution:
* **Main Model (Planner & Reviewer):** Has the full project context. Analyzes the problem, designs the architecture, updates documentation, and reviews implementations. Delegates isolated coding tasks to the Secondary Model by providing only the necessary files and specific instructions.
* **Secondary Model (Implementer):** Runs in a fresh, isolated context. Receives specific instructions and target files from the Main Model, implements the changes, and generates the patch script.
* **Testing Execution Rule (STRICT):** Nie Playwright direkt aufrufen (z.B. `npx playwright test`), sondern immer zwingend via `node ai_test_runner.mjs`! Dies stellt sicher, dass Fehler-Reports für die Analyse generiert werden.
* **Workflow-Reihenfolge für Test-Fixes (STRICT):**
  * 1. Dokumentieren (SOLL in `features/`, Bug-Analyse)
  * 2. Backend Unit/Integration-Tests schreiben (`php artisan test --filter`)
  * 3. Frontend Unit-Tests schreiben (`pnpm vitest run`)
  * 4. Erst danach: E2E-Tests fixen (`node ai_test_runner.mjs --console`)
* **localStorage Injection (STRICT ANTI-PATTERN):** Daten via `page.evaluate()` oder `addInitScript` in `localStorage` zu injizieren ist verboten. localStorage ist ein Implementierungsdetail des Frontends. Tests MÜSSEN den User-Flow abbilden: Login → Navigation → Formular-Interaktion. Ausnahme: `E2ESessionHelper` für Test-Setup (API-basiert).
* **Max 3 Fix-Versuche für Tests (STRICT):** Nach 3 erfolglosen Versuchen, einen fehlschlagenden Test zu fixen, MUSS der Agent an den Benutzer zurückgeben mit einer Analyse was schiefgeht. Keine Endlos-Fix-Loops.
* **Last-Failed Tip:** `node ai_test_runner.mjs --console` reicht alle Args durch. Für Wiederholung nur der fehlgeschlagenen Tests: `node ai_test_runner.mjs --console --last-failed`. Oder via Playwright direkt: `npx playwright test --last-failed`

## 4. Test Commands
```bash
# Backend (PHP via Herd: PATH muss php85 enthalten)
export PATH="/c/Users/flori/.config/herd/bin/php85:$PATH"
cd backend && php artisan test

# Frontend Unit (pnpm, NICHT npm)
cd frontend && pnpm vitest run

# Frontend Lint + Build (pnpm, NICHT npm)
cd frontend && pnpm lint:fix && pnpm build

# E2E (via ai_test_runner, NIE direkt npx playwright)
cd frontend
node ai_test_runner.mjs brand
```