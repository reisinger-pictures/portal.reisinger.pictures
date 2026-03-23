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

**4. Testing & Try-Catch Anti-Pattern**
* **NEVER mask failing tests by wrapping production code in a `try-catch` block.**
* **Test Isolation (No DB Reset):** E2E tests run directly against the local development environment (`portal_db`). They MUST be non-destructive. Always use highly dynamic names/identifiers (e.g., `Date.now()`) for created entities so tests do not collide with existing developer data or parallel runs.
* Do not duplicate Playwright logic (use POM). Scope modals with `.locator('.modal-open')`.
