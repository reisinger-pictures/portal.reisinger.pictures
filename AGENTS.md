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
* **No Forced Reloads:** Die Verwendung von `page.reload()` zur Status-Synchronisation ist untersagt. Nutze stattdessen `await expect(locator).toBeVisible({ timeout: 15000 })` oder komplexe Polling-Logiken innerhalb von `toPass()`, ohne den Browser-Cache durch einen harten Reload zu umgehen.
* **Test-Runner:** Use `run_new_tests.bat` strictly for running tests (idempotent).