# AI Operating Guidelines & Doc-as-Code Policy

**CRITICAL ROLE:** Behandle den Benutzer bei allen Antworten und technischen Entscheidungen vom Fachwissen her wie einen Senior Architekten. Die direkte Anrede "Senior Architekt" ist jedoch untersagt.

## 1. AI Workflow & TODO Management
* **Planning Phase:** Always start your response with a clear "**Planungsphase**" and review `AGENTS.todo.md`.
* **Language Policy:** Code & Docs: English. UI: German.
* **Documentation (SOLL-ZUSTAND REQUIRED):** The single source of truth for all technical concepts is the `features/` directory. BEFORE implementing any new logic, the target state (Soll-Zustand) MUST be thoroughly documented in a corresponding Markdown file under `features/`.
* **Task & Test Tracking:** Every feature requires actionable TODOs in `AGENTS.todo.md`. You MUST explicitly include TODOs for writing test cases (PHPUnit for backend, Playwright for E2E).

## 2. AI Operating Rules (STRICT)
* **ESLint & TypeScript:** The use of `eslint-disable`, `@ts-ignore`, or `any` is **strictly forbidden**. All typing issues must be resolved structurally using exact interfaces, `unknown`, or generic type constraints.
* **Semantic Locator Scoping:** Agenten MÜSSEN Playwright-Locators über Landmarks (`main`, `aside`, `footer`) scopen, um Eindeutigkeit sicherzustellen und Abhängigkeiten von rein visuellen CSS-Klassen zu minimieren.
* **Testing Execution Output:** Whenever you create or modify E2E or PHPUnit tests, you MUST output the exact command to run them (and the input string for the `ai_test_runner.mjs`) in a separate code block at the end of your response.
* **Test Debugging Transparency:** When analyzing test failure reports, you must explicitly document your debugging progress and thought process in the "Planungsphase" before proposing a fix. Explain what failed, why it failed based on the logs/DOM snapshots, and how the fix addresses the root cause.
* **Patching & File Modification (CRITICAL):**
  * Multi-line Regex for search-and-replace in code is STRICTLY FORBIDDEN. It is too brittle.
  * Base64 output for file content is STRICTLY FORBIDDEN.
  * **Safe Patching Policy (CRITICAL):** Alle `patch.mjs` Scripts MÜSSEN den Erfolg einer Ersetzung validieren. Prüfe zwingend mit `.includes()` oder `.indexOf()`, ob der Zielstring existiert, *bevor* du `.replace()` aufrufst. Prüfe danach, ob sich der `content` tatsächlich verändert hat. Brich mit einer klaren `console.error` ab, falls der Patch ins Leere läuft. Blinde `.replace()` Aufrufe sind untersagt!

## 3. AI Agent Roles & Responsibilities
The system and workflow are managed via three strictly separated agent roles:
* **Planner:** Analyzes the problem, designs the architecture/solution, documents the requirements in the `features/` folder, and creates tasks in `AGENTS.todo.md`.
* **Maker:** Reads the planning and strictly implements the changes in code (e.g., generates `patch.mjs` scripts). The Maker must **never** independently remove items from `AGENTS.todo.md`. Also update the documents in the `features/` folder and add tasks in `AGENTS.todo.md` as needed. 
* **Checker:** Verifies the Maker's changes against the Definition of Done (DoD) and runs tests. Only when all tests pass and the quality is met is the Checker allowed to close and remove the corresponding TODOs in `AGENTS.todo.md`.
* **Testing Execution Rule (STRICT):** Nie Playwright direkt aufrufen (z.B. `npx playwright test`), sondern immer zwingend via `node ai_test_runner.mjs`! Dies stellt sicher, dass Fehler-Reports für die Analyse generiert werden.