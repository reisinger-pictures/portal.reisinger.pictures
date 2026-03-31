# AI Operating Guidelines & Doc-as-Code Policy

**CRITICAL ROLE:** Behandle den Benutzer bei allen Antworten und technischen Entscheidungen vom Fachwissen her wie einen Senior Architekten. Die direkte Anrede "Senior Architekt" ist jedoch untersagt.

## 1. AI Workflow & TODO Management
* **Planning Phase:** Always start your response with a clear "**Planungsphase**" and review `AGENTS.todo.md`.
* **Language Policy:** Code & Docs: English. UI: German. TypeScript only.

## 2. Code Output & File Patching
* **File Modification Rule (CRITICAL):**
  * Multi-line Regex for search-and-replace in code is **STRICTLY FORBIDDEN**. It is too brittle.
  * When patching, use **exact string replacement** or **rewrite the entire file**.
  * Patch-Scripts must be standalone `.mjs` files.

## 3. Testing Rules (UI-FIRST)
* **UI-First Synchronization (MANDATORY):**
  * Never use `page.waitForResponse()` or network status codes to verify UI updates. E2E tests must only care about what the user sees.
  * Use `expect(locator).toBeVisible({ timeout: 15000 })` for simple updates.
  * Use `await expect(async () => { ... }).toPass()` for complex SWR/React state transitions where multiple re-renders occur.
* **STRICT Anti-Reload Policy (Genuine User Reactivity):** Die Verwendung von `page.reload()` zur Status-Synchronisation in E2E-Tests ist **strikt untersagt**. 
  * **Begründung:** Ein Reload maskiert fehlerhaftes State-Management (z.B. fehlende SWR `mutate()` Aufrufe). Wenn eine Entität (wie eine Galerie) erstellt wird, *muss* sich das UI im Hintergrund automatisch aktualisieren.
  * **Lösung:** Wenn ein Test auf ein UI-Update wartet, nutze ausschließlich geduldige Asserts (z.B. `await expect(locator).toBeVisible({ timeout: 20000 })`). Schlägt dies fehl, liegt ein Bug in der Applikation vor (State/Cache Update fehlt), nicht im Test.
* **Test-Runner:** Use `run_new_tests.bat` strictly for running tests (idempotent).