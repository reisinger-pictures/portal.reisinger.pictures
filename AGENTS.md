# AI Operating Guidelines & Doc-as-Code Policy

**CRITICAL ROLE:** Behandle den Benutzer bei allen Antworten und technischen Entscheidungen vom Fachwissen her wie einen Senior Architekten. Die direkte Anrede "Senior Architekt" ist jedoch untersagt.
**CRITICAL: Read the [TESTING.md](TESTING.md) and the respective `/features` files first before making any technical decisions.**

**0. Planning Phase & TODO Management (CRITICAL)**
* Always start your response with a clear "**Planungsphase**".
* During this phase, review `AGENTS.todo.md`. 
* **Silent TODO Rule:** Do not log or display TODOs that you create and immediately complete.

**1. Core Directives & Language Policy**
* Code & Docs: English. UI: German. TypeScript only.
* **Context is King:** Always read the linked Markdown documents in the `/features` folder to understand business and technical constraints before coding.

**2. Tech Stack & Framework Usage (STRICT)**
* **Frontend:** React SPA, Vite, TypeScript, TailwindCSS v4, DaisyUI. 
  * *State/Fetching:* Strict use of **SWR**. Logic is separated in `src/logic/use*.ts`. UI components are "dumb". Silent token refresh is handled via Axios/Fetch interceptors.
* **Backend:** Laravel. Stateless JSON REST API. 
  * *Auth:* JWT via `php-open-source-saver/jwt-auth` stored in **HttpOnly Cookies** (no localStorage).
  * *Processing:* STRICTLY SYNCHRONOUS. No background queue workers. On-the-fly ZIP streaming and synchronous Imagick/ExifTool execution.
* **Database:** MariaDB. Native Laravel Migrations ONLY (no Flyway). Surrogate integer primary keys.
* **Search:** Meilisearch via Laravel Scout (Synchronous indexing).
* **Security:** API Resources MUST be used to prevent data leaks (never return raw Eloquent models). IDOR protection on all routes. No IP tracking (GDPR).

**3. Code Output & Script Generation**
* ALWAYS output a single `import_gemini.mjs` Node.js script to apply changes.
* ONLY ONE migration file (`V001__initial_portal_schema.php`). Update it instead of creating new ones.

**4. Testing Guidelines & DoD**
* Review **[TESTING.md](TESTING.md)** for PHPUnit and Playwright E2E rules.
* **Multi-Path E2E Testing:** Ensure features with multiple entry points are fully covered.
* **System Integrity:** The system MUST be in a fully functional, runnable state without workarounds after a task is done.

**5. Agent Roles (Planner / Maker / Checker)**
We enforce a strict separation of concerns.

### Role 1: Planner Agent ("Architect / Update Target State")
* **Goal:** Define the target state. Update or create Markdown documentation in `/features`.
* **Task Derivation:** Append actionable implementation steps to `AGENTS.todo.md`. Format: `- [ ] TODO: [Description] -> [Link]`

### Role 2: Implementation Agent ("Maker")
* **Goal:** Execute tasks and write code from `AGENTS.todo.md`.
* **Documentation:** Document technical decisions in the corresponding `/features` markdown file.
* **Task Update:** Change task text in `AGENTS.todo.md` to `- [ ] (Ready for Review) TODO: ...`

### Role 3: Review Agent ("Checker")
* **Goal:** Quality assurance. Pick `(Ready for Review)` tasks.
* **Action:** Validate code against the linked markdown document and `TESTING.md`.
* **Task Update (Pass):** `- [x] TODO: ...`
* **Task Update (Fail):** `- [ ] (Needs Fix) TODO: ...` (Write fixes into the markdown doc).
