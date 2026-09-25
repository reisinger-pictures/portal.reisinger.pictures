# E2E Test Execution Strategy

## Status: Current contract (reviewed 2026-09-24)

This document describes the current Playwright setup in
`frontend/playwright.config.ts` and `.github/workflows/ci.yml`. Test counts and
individual feature coverage change with the suite; use
`npx playwright test --list` instead of relying on a stale count in this file.

## Test topology

The suite runs in two browser projects:

- `Desktop Chrome` (1920×950 viewport)
- `Mobile Chrome` (Galaxy A55 device profile)

`fullyParallel` is enabled. Local runs use eight workers by default; CI uses the
matrix worker count described below. Each test has a 120-second timeout and the
whole run has a bounded 25-minute (`1500000` ms) global budget. CI retries a test
up to two times (`retries: 2`); local runs do not retry automatically.

### Explicit timeout decision (2026-09-25)

The completed CI run `36035927250` measured a 6m51s serial entry and a 17m09s
slowest parallel shard; the former 900000-ms cap stopped that shard with 26
tests not run. The generic doubling guideline would produce 34m18s from
17m09s, but the user-approved contract deliberately caps the whole run at
25 minutes (`1500000` ms), leaving 7m51s above the measured maximum. The
per-test budget remains `120000` ms. This is an explicit, auditable exception
to the generic doubling rule, not an inferred timeout: any change requires a
new CI measurement and explicit approval. The task board records the same
decision and the fact that no local Playwright evidence is being claimed.

The current CI matrix has seven entries:

| Matrix entry | Project | Selection | Workers |
|---|---|---|---:|
| Desktop (1/3) | Desktop Chrome | Invert Kanban/billing selection, `--shard=1/3` | 2 |
| Desktop (2/3) | Desktop Chrome | Invert Kanban/billing selection, `--shard=2/3` | 1 |
| Desktop (3/3) | Desktop Chrome | Invert Kanban/billing selection, `--shard=3/3` | 2 |
| Mobile (1/3) | Mobile Chrome | Invert Kanban/billing selection, `--shard=1/3` | 2 |
| Mobile (2/3) | Mobile Chrome | Invert Kanban/billing selection, `--shard=2/3` | 1 |
| Mobile (3/3) | Mobile Chrome | Invert Kanban/billing selection, `--shard=3/3` | 2 |
| serial (isolated board/settings suites) | Both projects | `--grep "projects-board|production-board|project-clear-fields|brand-settings|billing-details"` | 1 |

The first six entries are project-scoped. The serial entry deliberately runs
without a `--project` restriction so the selected suites are offered to both
browser projects; explicit project skips (for example, the mobile skip in
`brand-settings`) remain authoritative. The serial selection currently covers
these five files:

- `frontend/tests/e2e/admin/projects-board.spec.ts`
- `frontend/tests/e2e/photographer/production-board.spec.ts`
- `frontend/tests/e2e/admin/project-clear-fields.spec.ts`
- `frontend/tests/e2e/admin/brand-settings.spec.ts`
- `frontend/tests/e2e/admin/billing-details.spec.ts`

The first four use Playwright serial mode; billing-details is isolated because
it writes global settings. This is a scheduling constraint for known shared
state, not permission for new tests to depend on one another or share fixtures.

## Functional tag-based execution

Tests are categorized by criticality and scope using Playwright's native
singular `tag` option. The option accepts a string or a string array; `tags` is
not a valid test option.

| Tag | Meaning | When to run |
|---|---|---|
| `@smoke` | Critical path such as login, guest access, and basic CRUD | After every code change |
| `@regression` | Broader functional regression coverage | Before deployment and in the full CI run |
| `@feature:<name>` | Feature-specific selection | While changing that feature |
| `@mobile` | Device-specific behavior | With the relevant functional tag on mobile coverage |

New E2E tests must include at least one **functional** tag (`@smoke`,
`@regression`, or `@feature:<name>`). Legacy untagged tests remain part of the
full suite until they are tagged; they are not silently included in a feature
grep. `@smoke` coverage must execute on Desktop Chrome. A desktop-only smoke
test may explicitly skip Mobile Chrome. Device-specific tests use `@mobile` in
addition to their functional tag; `@mobile` alone is not a functional tier.

### Adding a tag

```typescript
import { test } from '@playwright/test';

test('critical path test', {
    tag: ['@smoke', '@feature:auth'],
}, async ({ page }) => {
    // ...
});
```

Tags are additive, but `--grep @feature:auth` selects tests carrying that tag;
it is not a reason to run untagged tests. A failed focused run can be repeated
with `pnpm test:e2e:failed`, but an agent gets at most three fix attempts. After
three unsuccessful attempts, stop and report the logs and root-cause analysis
instead of entering an unbounded retry loop.

## Commands and workflow

### Local development

```bash
cd frontend
pnpm test:e2e:smoke                         # after every code change
pnpm test:e2e:grep @feature:checkout        # feature-focused run
pnpm test:e2e                               # full pre-deployment run
pnpm test:e2e:failed                        # reproduce the last failed tests
```

The default local integration path is the isolated backend started by
`scripts/e2e-up.sh`, backed by `backend/database/database.e2e.sqlite` and the
test Meilisearch service. Never point Playwright at the developer's working
SQLite database. After the normal seed, that setup explicitly runs
`Database\Seeders\E2ELocationSeeder` from the checked-in
`backend/database/fixtures/e2e-locations.json` fixture, then flushes and imports
the `Location` Scout index. This supplies the stable Salzburg/Graz/Linz contract
for location E2E specs without network access in `DatabaseSeeder` or production
startup.

### CI and deployment

CI runs the full seven-entry E2E matrix on pushes and on normal same-repository
pull requests. Dependabot's `pull_request` event is intentionally skipped for
the secret-dependent E2E job and its aggregate gate because that event has no
usable repository secrets; the push run on the same head SHA is the sole
authoritative `CI gate (push)`. Fork pull requests are also skipped because
their secrets are not available, while normal same-repository PRs remain
fail-closed. A deployment requires the full suite; `@smoke` is the fast local
post-change check, not a replacement for the CI matrix. Every fresh CI E2E
matrix database runs the same explicit, offline location-fixture seeder and Scout
import before the backend server starts.

The E2E job runs in the digest-pinned `ghcr.io/reisinger-pictures/portal-e2e`
image (the exact digest is maintained in `ci.yml`) and installs the current
application dependencies on the mounted workspace. The image supplies the
environment and browser; it never supplies application code, `node_modules`, or
`vendor`. The namespace is the GitHub org that owns this repository, which is
also where `e2e-image.yml` publishes. This records the configured digest only;
it does not assert that the published GHCR image has been freshly rebuilt or is
currently available.

## Test isolation and security rules

- Tests must use real login, navigation, and form flows or the API-based
  `E2ESessionHelper`; injecting application state or `localStorage` with
  `page.evaluate()` or `addInitScript` is forbidden. A documented external
  provider-widget stub, such as the Turnstile test renderer, is a separate
  boundary exception and must not seed application storage.
- Test fixtures must be dynamic and isolated. Never hard-code shared users,
  gallery names, order IDs, or credentials, and always clean up resources in
  `test.afterEach()`.
- Internal API route mocks are forbidden in E2E tests. This includes CRUD,
  authentication/authorization, status/failure fixtures, and configuration
  responses under `/api/*`; use real backend/test fixtures or component/
  integration tests for those states. Only an explicitly documented external
  provider/widget boundary (for example Stripe.js or Turnstile) may be replaced
  as an external integration stub, and it must not seed application storage.
- CI never uploads Playwright HTML reports, `test-results`, traces, screenshots,
  or videos. They can contain browser storage, cookies, request data, or PII.
  CI uses the list reporter and retains only a sanitized step summary. Local
  HTML reports and traces remain local; tracing is opt-in with `PW_TRACE=1`.

## Card-testing and Turnstile flow

The `@feature:card-testing` E2E flow exercises the risk-triggered Turnstile
path with documented test/dummy values and can be selected with
`npx playwright test --grep @feature:card-testing`. Turnstile is **fail-closed
only while it is active**, meaning both the site key and secret are configured
and a challenge is required. In that state a missing/invalid token or
verification failure rejects the checkout and no PaymentIntent is created. If
the configuration is missing, Turnstile is intentionally inactive and the other
checkout controls (validation, account age, limits, idempotency, and Stripe
identity checks) still apply. Missing configuration must not be described as a
Turnstile failure.

## Shard maintenance

Do not change shard counts or worker counts from historical timing claims. The
`1500000` ms global timeout is a bounded CI budget, not an SLA. The explicit
25-minute decision and its exception to the generic doubling policy are
recorded above; the former `900000` ms cap was hit by the slowest parallel
shard in completed run `36035927250` (17m09s, with 26 tests not run), so it was
replaced with the 25-minute budget. After changing the matrix, measure the
actual matrix entries and keep the selection in this document and
`features/infrastructure/28-ci-test-image.md` in sync. Re-balance only when a
current run demonstrates a problem, and preserve the dedicated serial shard for
the documented board/settings suites.
