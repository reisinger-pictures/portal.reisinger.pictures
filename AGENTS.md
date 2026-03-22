# AI Operating Guidelines

**CRITICAL ROLE:** Behandle den Benutzer bei allen Antworten und technischen Entscheidungen vom Fachwissen her wie einen Senior Architekten. Die direkte Anrede "Senior Architekt" ist jedoch untersagt.
**CRITICAL: Read the [ARCHITECTURE.md](ARCHITECTURE.md) file first before making any technical decisions.** It contains all system design, security, and UI rules.

**0. Planning Phase & TODO Management (CRITICAL)**
* Always start your response with a clear "**Planungsphase**".
* During this phase, review `AGENTS.todo.md`. Remove completed (`[x]`) tasks, add new ones if necessary, and **NEVER** drop or delete unchecked (`[ ]`) tasks. All pending tasks must be preserved.

**1. Language Policy & Lightroom Feature Parity**
* **Lightroom Plugin:** The Lightroom plugin is a first-class citizen for the photographer workflow. We strive for Feature Parity between the React Dashboard and the Lightroom Plugin for gallery management (editing, live mode, meta-galleries, invites). The plugin UI must strictly verify the `photographer` or `admin` role before allowing interaction.
* Code & Docs: English.
* Code & Docs: English.
* UI & Frontend Strings: German.
* **Frontend Code:** Strictly **TypeScript** (`.ts`, `.tsx`). Absolutely no plain JavaScript.

**2. Code Output & Script Generation (ALWAYS USE MJS)**
* Always output complete files. No placeholders.
* **CRITICAL RULE:** ALWAYS output a single `import_gemini.mjs` Node.js script to apply any file changes, regardless of the number of files changed. This is for the user's convenience.
* **CRITICAL RULE:** Always provide a markdown summary of changed files *before* outputting the script, so the user knows exactly what is being modified.
* **Refactoring:** For small string replacements across many files, use the same `import_gemini.mjs` approach with regex replacements (`content.replace(/old/g, 'new')`) to save tokens.

**3. Database & Migrations Workflow (CRITICAL)**
* During development, there must be exactly ONE migration file (`V001__initial_portal_schema.php`).
* Do not create new migration files for schema changes. Instead, append or modify the existing `V001` file.

**4. Documentation & Setup Guards (CRITICAL)**
* Always update `README.md` or `deployment/DEPLOYMENT.md` if your code changes alter the setup, execution, routing, or login process.
* Never leave the documentation out of sync with the codebase.

**5. IntelliJ Run Configurations (CRITICAL)**
* Whenever you instruct the user to execute a command (especially recurring ones like migrations, tests, or setup scripts), you MUST provide a corresponding IntelliJ Run Configuration (`.run/Name_of_Config.run.xml`).
* This ensures the user can trigger the command directly from the IDE UI without typing in the terminal.

**6. Automated Testing (TDD/BDD Approach)**
* Whenever a new feature is added or an existing feature is modified, the corresponding unit or feature tests MUST be updated or created.
* Never consider a feature complete without providing the necessary test coverage.

**7. Role-Based Access Control (RBAC) Testing (CRITICAL)**
* Whenever creating or modifying API endpoints, you MUST write individual test cases for each role (Admin, Photographer, Client, Guest).
* Do not mix roles in a single test user just to make a test pass. Ensure positive tests (200 OK) for authorized roles and negative tests (401/403) for unauthorized roles.
