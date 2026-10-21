# AI Operating Guidelines & Engineering Rules

## 1. Language and role

- Treat the user as a senior technical peer; do not address them as “Senior Architekt.”
- Code and documentation are in English. User-facing UI is in German. Mixed-language configuration and established German terms are allowed.

## 2. Documentation boundary (STRICT)

> **`AGENTS.md` files contain only durable, actionable rules and instructions.** Do not put status reports, owner decisions or their rationales, audit evidence or history, completed work, TODOs, or implementation narratives in them. Put durable product and system contracts in `features/`, owner decisions in [`features/owner-decisions.md`](features/owner-decisions.md), and current work/status in `AGENTS.todo.md`.

**Rules only, no decisions:** This file contains rules only — instructions that apply to any work in this repo. Durable decisions, rationale, measurements and history do not belong here but in `features/` (or the project docs); open work goes to `AGENTS.todo.md`, settled matters to git history.

## 3. Definition of Done and quality gates

[`DoD.md`](DoD.md) is the human-readable Definition of Done; do not duplicate or redefine it here. Its criteria are that tests exist and `features/` is current.

- Backend changes require PHPUnit feature/unit tests.
- Frontend logic changes require Vitest unit tests; frontend UI/component changes require Playwright E2E tests.
- Bug fixes require a regression test reproducing the bug. Refactoring/dead-code removal may omit tests when justified in the change record.
- Frontend quality gate: `pnpm lint:fix && pnpm build`. The build includes TypeScript and i18n checks; `tsc -b` is optional diagnosis, not a replacement.
- Backend quality gate: `php artisan test` with the existing suite green.
- Inner loop for the backend: from `backend/`, `composer test:smoke` (the tagged subset — 18 existing tests, a few seconds, needs neither Meilisearch nor Mailpit) and `composer test:feature` (the `tests/Feature` suite in parallel; it does not include `tests/Unit`) shorten the local loop. They do **not** replace the backend gate — details and the rules in [`backend/AGENTS.md`](backend/AGENTS.md).
- Do not add `eslint-disable`, `@ts-ignore`, or `any`.

## 4. Documentation and task management

- `features/` is the durable desired state: architecture, data models, API contracts, feature specifications, and owner decisions. Keep it current with system changes.
- `AGENTS.todo.md` is the temporary work list and session/review record. Every feature needs actionable implementation and test TODOs there. Do not use it as a permanent archive.
- Remove completed TODOs rather than checking them off. Keep only open work, explicit owner decisions still needed, conditions being awaited, or manual post-deploy checks.
- Mark superseded contracts as superseded. Mark fixed findings as fixed rather than leaving their old failure claim in the present tense.
- Evidence in durable docs must be verifiable: quote the referenced source when giving a line anchor, or cite a symbol; give a reproducible command for any reported number.
- Do not begin new work with failing or flaky tests. Diagnose and fix existing failures, or document an explicitly accepted risk in the appropriate feature/security document.
- Do not place implementation status or session history in `features/`; keep it in `AGENTS.todo.md` or Git.

## 5. Engineering rules

- Always use `pnpm lint:fix` for local linting. Plain `pnpm lint` is reserved for CI/PR checks.
- When reporting a test failure before proposing a fix, state what failed, cite the relevant logs/DOM evidence, explain the inferred root cause, and connect the proposed fix to it.
- Do not use multi-line regular expressions for code search-and-replace or Base64 to emit file contents. Any `patch.mjs` replacement must check that the target exists before replacing it, then verify the content changed and fail clearly otherwise.

## 6. Agent roles and delegation

- The main agent plans and reviews with full project context; an implementer receives an isolated task, relevant files, and specific instructions.
- A `build` agent is an orchestrator, not an implementer. Except for small typo or policy edits in `AGENTS.md`/`AGENTS.todo.md`, it may work only on those files and directly referenced documentation. Delegate code, tests, templates, and component work to a `general` implementer; visual review is done by the agent itself (method and checklist: the `ui-review` skill) — every configured model is vision-capable, so there is no vision subagent in the loop; on hard visual calls re-read the image yourself instead of guessing. The project keeps its own screenshot harness under `frontend/tests/screenshots/`.
- Delegate independent work in parallel when that reduces coordination cost without coupling the tasks.
- The shared pull/verify/commit/push process is defined by the `build-verify` skill; keep repository-specific commands and constraints here, not a duplicate workflow.

## 7. Testing and E2E (STRICT)

- Every new Playwright test uses the singular `{ tag: [...] }` option and at least one functional tag: `@smoke`, `@regression`, or `@feature:<name>`. `@mobile` is an additional device-scope tag, not a functional tag. Legacy untagged tests remain in the full suite.
- Run `pnpm test:e2e:smoke` after code changes, `cd frontend && npx playwright test --grep @feature:<name>` for feature work, and `pnpm test:e2e` before deployment. A focused run does not replace the full pre-deployment suite.
- For test fixes, document the intended contract first, then fix/write backend tests, frontend unit tests, and E2E tests in that order where applicable.
- Stop after three unsuccessful attempts to fix a failing test. Return the evidence and root-cause analysis instead of continuing a retry loop.
- Keep E2E locators scoped to semantic landmarks such as `<main>`. If a dialog is outside the scoped landmark, fix the rendered structure; do not weaken the scope. See [owner decision D-10](features/owner-decisions.md#d-10-main-scoped-e2e-dialogs).

## 8. Module instructions

Follow the module-specific `AGENTS.md` in addition to these repository-wide rules:

- [`frontend/AGENTS.md`](frontend/AGENTS.md) — React/Vite, frontend tests and quality gates, Tailwind, forms, and E2E conventions.
- [`backend/AGENTS.md`](backend/AGENTS.md) — Laravel tests, database setup/seeding, migrations, and parallel test database rules. The current migration frontier is recorded only in [`features/tech/07-architectural-decisions.md`](features/tech/07-architectural-decisions.md), AD-2. Add migrations only for unavoidable schema requirements.
- [`admin.lrplugin/AGENTS.md`](admin.lrplugin/AGENTS.md) — Lightroom Classic Lua plugin scope and conventions.

## 9. Security and owner decisions

- Do not weaken established tenant isolation, authentication, upload validation, authenticated file delivery, server-authoritative pricing, or HTML-sanitization boundaries. Follow the applicable `features/` contract.
- Owner decisions D-1 through D-37 are maintained in [`features/owner-decisions.md`](features/owner-decisions.md). Treat that file as the canonical current decision set; keep implementation status in `AGENTS.todo.md` and domain detail in the linked feature specifications.
- The accepted dependency risk C8 is recorded in [`features/security/accepted-risks.md`](features/security/accepted-risks.md). Do not change its accepted boundary without a reviewed decision.

## 10. IntelliJ Run Configurations (`.run`) (STRICT)

All `.run/*.run.xml` files use `<Emoji> [<Category>] <Name>` for both the filename and the internal configuration name. Keep them synchronized (filename omits `.run.xml`).

| Category | Emoji |
|---|---|
| `[Setup]` | ⚙️ |
| `[Run]` | Per target: Docker 🐳, Frontend ⚡, Stripe 💳 |
| `[Build]` | 🔨 |
| `[Test]` | 🧪 |
| `[AI]` | ✨ |
| `[Wartung]` | 🔧 |
| `[Deploy]` | 🚀 |
| `[Core]` | 🛑 |
| `[Gefahr]` | 🧨 |

`[Run]` is the sole per-configuration emoji exception; other categories use the emoji shown. Since `:` is invalid in macOS filenames, encode `: ` as `_ ` in the filename while retaining `: ` in the internal name. New configurations follow this schema.

## 11. Optional CodeGraph index and Git hook

- `.codegraph/` is optional and machine-local; `.codegraph/.gitignore` is its only tracked file. Never commit the database or daemon files. A directory may exist without an initialized index; check with `codegraph status` before relying on it.
- The optional `.githooks/pre-commit` CodeGraph sync is best-effort and fails open. It is not an index-freshness guarantee or a commit gate. Local activation uses `git config core.hooksPath .githooks`.

## 12. Skills ownership

- Shared, repository-owned skills live in `~/dev/agents-skills/` and are registered globally. Do not copy those skills into project repositories.
- Third-party skill packs remain in their installer-managed locations. Keep skills that are genuinely specific to this repository in the project.

## 13. Deployment and sync (STRICT)

- `sync.sh` copies files; it does not build, restart containers, migrate, or seed. Migrations are deliberate, separate operations; follow `backend/AGENTS.md` for required seeding after a migration.
- Before syncing frontend changes, install the frozen lockfile after any lockfile change, then run `pnpm lint:fix && pnpm build`. `sync.sh` transfers the existing `dist/`; ensure `dist/index.html` is newer than the latest frontend commit, comparing timestamps in the same timezone (or as epoch values). A bundle hash proves transfer integrity, not freshness. After a runtime dependency changes, check that the built bundle reflects it; its hash should change when that dependency affects the bundle.
- Frontend `dist/` is static and needs no container restart. After syncing PHP changes, restart `portal_backend` so PHP and Laravel route/config caches load the new files.
- Recreate, rather than restart, containers after Compose runtime configuration changes, including `command`, `entrypoint`, `environment`, `volumes`, or `user`.
- Deploy order is sync → required restart/recreate → health check and a request exercising the changed behavior. Require green full CI for the exact pushed head. For an auto-merged Dependabot head, use the matching-tree/green-branch-run exception in [D-34](features/owner-decisions.md#d-34-ci-evidence-for-auto-merged-dependabot-heads).
