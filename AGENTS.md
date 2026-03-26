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


**4. Testing Guidelines & Definition of Done (DoD)**
* Review **[TESTING.md](TESTING.md)** for PHPUnit and Playwright E2E rules.
* **Multi-Path E2E Testing:** Ensure features with multiple entry points are fully covered.
* **Strict Definition of Done (DoD):** A task is only truly DONE (`- [x] TODO:`) when:
  1. **Feature Complete:** Meets all requirements defined in the respective `/features` doc.
  2. **Tested:** Playwright (E2E) and/or PHPUnit tests are written, updated, and passing. No flaky `sleep()` calls.
  3. **Secure:** IDOR and role-checks (`canAccessGallery`) are implemented. JSON responses do not leak sensitive fields.
  4. **Documented:** `/features` markdown files are up-to-date with technical decisions.
  5. **Runnable:** The system works locally without workarounds.


**5. Agent Roles & Strict Definition of Done (DoD)**
We enforce a strict separation of concerns. An agent must fulfill its specific DoD before completing a turn.

### Role 1: Planner Agent ("Architect")
* **Goal:** Define the target state and estimate effort.
* **DoD (Definition of Done):**
  - [ ] The technical concept is documented or updated in the corresponding `/features` markdown file.
  - [ ] Actionable, bite-sized tasks are appended to `AGENTS.todo.md`.

### Role 2: Implementation Agent ("Maker")
* **Goal:** Execute tasks from `AGENTS.todo.md`.
* **DoD (Definition of Done):**
  - [ ] Code is written following the stack rules (SWR, Tailwind v4, etc.).
  - [ ] E2E (Playwright) or Backend (PHPUnit) tests are added/updated and use patient assertions.
  - [ ] The task status in `AGENTS.todo.md` is changed to `- [ ] (Ready for Review) TODO: ...`

### Role 3: Review Agent ("Checker")
* **Goal:** Quality assurance.
* **DoD (Definition of Done):**
  - [ ] Code is validated against the linked `/features` document.
  - [ ] IDOR/Security checks are confirmed.
  - [ ] Task is either checked off (`- [x]`) or rejected (`(Needs Fix)`) in `AGENTS.todo.md`.
