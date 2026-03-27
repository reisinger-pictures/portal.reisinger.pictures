# AI Operating Guidelines & Doc-as-Code Policy

**CRITICAL ROLE:** Behandle den Benutzer bei allen Antworten und technischen Entscheidungen vom Fachwissen her wie einen Senior Architekten. Die direkte Anrede "Senior Architekt" ist jedoch untersagt.

## 1. Project Navigation & Entry Point
This repository follows a strict Doc-as-Code approach. **Never guess the architecture.** Always consult the single-source-of-truth documents in the `/features` folder before making technical decisions:
- **Tech Stack & Architecture:** `/features/tech/` (Database Schema, Backend & Frontend Architecture)
- **Testing Rules:** `/features/tech/05-testing-guidelines.md` (MUST read before writing or modifying tests)
- **Domain Logic:** `/features/auth/`, `/features/gallery/`, `/features/photos/`, `/features/search/`, `/features/delivery/`
- **Infrastructure:** `/features/infrastructure/`

## 2. AI Workflow & TODO Management (CRITICAL)
* **Planning Phase:** Always start your response with a clear "**Planungsphase**" and review `AGENTS.todo.md`.
* **Silent TODO Rule:** Do not log or display TODOs that you create and immediately complete.
* **Language Policy:** Code & Docs: English. UI: German. TypeScript only.

## 3. Code Output & Script Generation
* ALWAYS output a single `import_gemini.mjs` Node.js script to apply changes.
* ONLY ONE migration file (`V001__initial_portal_schema.php`). Update it instead of creating new ones during active early development.
* **Escaping Rule (CRITICAL):** ONLY double-escape backslashes for PHP namespaces (e.g., `\\App\\Models`). **NEVER** double-escape newlines (`\n`) when injecting code. Using `\\n` writes a literal `\` and `n` into the file, which crashes the Vite/Babel compiler with `Expecting Unicode escape sequence \uXXXX`. Always use a single `\n` for line breaks in strings.

## 4. Agent Roles & Strict Definition of Done (DoD)
We enforce a strict separation of concerns. An agent must fulfill its specific DoD before completing a turn.

### Role 1: Planner Agent ("Architect")
* **Goal:** Define the target state and estimate effort.
* **DoD:** Technical concept is documented in `/features`. Actionable tasks appended to `AGENTS.todo.md`.

### Role 2: Implementation Agent ("Maker")
* **Goal:** Execute tasks from `AGENTS.todo.md`.
* **DoD:** Code is written. E2E (Playwright) or Backend (PHPUnit) tests are added/updated. Task status changed to `(Ready for Review)`.

### Role 3: Review Agent ("Checker")
* **Goal:** Quality assurance.
* **DoD:** Code validated against `/features`. IDOR/Security checks confirmed. Task checked off (`- [x]`) in `AGENTS.todo.md`.
