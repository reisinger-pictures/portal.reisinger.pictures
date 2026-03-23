# AI Operating Guidelines

**CRITICAL ROLE:** Behandle den Benutzer bei allen Antworten und technischen Entscheidungen vom Fachwissen her wie einen Senior Architekten. Die direkte Anrede "Senior Architekt" ist jedoch untersagt.
**CRITICAL: Read the [ARCHITECTURE.md](ARCHITECTURE.md) file first before making any technical decisions.**

**0. Planning Phase & TODO Management (CRITICAL)**
* Always start your response with a clear "**Planungsphase**".
* During this phase, review `AGENTS.todo.md`. Remove completed (`[x]`) tasks, add new ones if necessary.
* **Silent TODO Rule:** Do not log or display TODOs that you create and immediately complete.

**1. Language Policy & Lightroom Feature Parity**
* Code & Docs: English. UI: German. TypeScript only.

**2. Code Output & Script Generation**
* ALWAYS output a single `import_gemini.mjs` Node.js script to apply changes.

**3. Database & Migrations Workflow**
* ONLY ONE migration file (`V001__initial_portal_schema.php`).

**4. Testing Guidelines**
* Siehe zwingend **[TESTING.md](TESTING.md)** für alle Regeln rund um PHPUnit und Playwright E2E-Tests!

**5. Definition of Done (DoD) & TODO Management**
* **Test Planning Requirement:** Whenever you create a new TODO for a feature or refactoring, you MUST explicitly consider and define the types of tests (e.g., PHPUnit for backend logic, Playwright E2E for UI/workflows) required for that task. Include these test requirements directly in the TODO description or as distinct sub-tasks.
* **Strict File Separation:** `AGENTS.todo.md` is EXCLUSIVELY for listing TODOs. Never write instructions, rules, or guidelines in the TODO file. Architecture and rules belong in `ARCHITECTURE.md` or `AGENTS.md`.
* **Check-Off Condition:** A TODO can ONLY be checked off if the feature is 100% complete. This means: Production code works, PHPUnit tests are green, E2E tests are green, and external integrations (e.g., Lightroom Plugin) are adjusted and unbroken.
* **System Integrity:** After checking off a TODO, the entire system MUST be in a fully functional, runnable state without workarounds.