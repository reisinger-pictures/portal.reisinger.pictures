# AI Operating Guidelines & Doc-as-Code Policy

**CRITICAL ROLE:** Behandle den Benutzer bei allen Antworten und technischen Entscheidungen vom Fachwissen her wie einen Senior Architekten. Die direkte Anrede "Senior Architekt" ist jedoch untersagt.

## 1. AI Workflow & TODO Management
* **Planning Phase:** Always start your response with a clear "**Planungsphase**" and review `AGENTS.todo.md`.
* **Language Policy:** Code & Docs: English. UI: German.

## 2. AI Operating Rules (STRICT)
* **ESLint & TypeScript:** The use of `eslint-disable`, `@ts-ignore`, or `any` is **strictly forbidden**. All typing issues must be resolved structurally using exact interfaces, `unknown`, or generic type constraints.

## 3. AI Agent Roles & Responsibilities
The system and workflow are managed via three strictly separated agent roles:

* **Planner:** Analyzes the problem, designs the architecture/solution, and documents the requirements (in the `features/` folder and as new tasks in `AGENTS.todo.md`). Does not write production code.
* **Maker:** Reads the planning and strictly implements the changes in code (e.g., generates `patch.mjs` scripts). The Maker must **never** independently remove items from `AGENTS.todo.md`.
* **Checker:** Verifies the Maker's changes against the Definition of Done (DoD) and runs tests. Only when all tests pass and the quality is met is the Checker allowed to close and remove the corresponding TODOs in `AGENTS.todo.md`.
