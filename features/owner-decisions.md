# Owner Decisions

This is the canonical current contract for owner decisions D-1 through D-42. Apply these decisions unless a later reviewed decision replaces one. Implementation status belongs in `AGENTS.todo.md`; domain specifications carry the detailed system contracts.

## Operations and deployment

### D-1: SFTPGo and backend identity

Run SFTPGo as `1002:82` and the backend as `1000:82`. See [FTP upload pipeline](infrastructure/19-ftp-upload-pipeline.md).

### D-2: FTP inbox directory provisioning

Create `ftp/<ftp_slug>` in software before saving the slug or creating the SFTPGo account. Use correct ownership and mode `2775` with setgid (group-writable, not world-writable); provisioning failure fails the operation. Do not recursively `chown` the website tree. See [FTP upload pipeline](infrastructure/19-ftp-upload-pipeline.md).

### D-3: Main branch protection

Do not enable branch protection on `main`; this is an intentional owner trust decision, not a code issue.

### D-4: Production secret handling

The production `.env` remains gitignored, unencrypted, and unrotated by owner decision; no secret manager is required. Never commit or expose secret values. See [environment and secret hardening](security/env-hardening.md).

### D-5: Host storage

Assess storage capacity for the deployment host. Developer-machine capacity is not a proxy for deploy-host capacity.

### D-6: API throttle limit in CI

Keep `API_THROTTLE_LIMIT=1000` in `backend/.env.ci`; document `60` as the production-intended value in `backend/.env.example`. Do not change the production value or application default as part of this CI adjustment.

## Test and repository structure

### D-7: Sentence-aware i18n check

The i18n scanner groups adjacent `JsxText`/`JsxExpression` siblings into one prose run; a nested JSX element or fragment ends the run. The check remains a hard failure, not warning-only. Prose heuristics deliberately favor under-counting over false positives on non-prose; include literals adjacent to expressions conservatively so grouping does not drop findings. See [i18n contract](tech/06-i18n-internationalization.md).

### D-8: Admin E2E organization

Organize `frontend/tests/e2e/admin/` by domain, consistent with the other domain directories. Treat the reorganization as a separately planned task, not incidental cleanup. See [E2E test strategy](e2e-test-strategy.md).

### D-19: Management organization view tests

Maintain unit-test files for both `ManagementOrgsView` and `ManagementOrgDetailView`.

## UI and dialog behavior

### D-9: Sidebar portal name

Allow the portal name in the sidebar to wrap; do not silently truncate it.

### D-10: Main-scoped E2E dialogs

Keep E2E dialog locators scoped to `<main>`. If a dialog is outside that landmark, fix its rendered structure rather than weakening the test scope.

### D-11: Shared modal layout

`ModalShell` provides named heights with statically declared classes and the required `bodyHead`/pinned layout slots. Introduce or retain those slots together with consuming-dialog migration; do not add unused shell API or parallel hand-rolled bounded layouts. See [dialog height contract](tech/08-dialog-height-contract.md).

### D-12: Dialog initial focus

The shell focuses the dialog's React `autoFocus` target, otherwise its close control, and remains the sole focus manager. Capture the return-focus target before mounting dialog content. Never autofocus a destructive action.

### D-13: Modal width API

`ModalShell.maxWidth` accepts only `'2xl'`.

### D-14: Coupon drawer dismissal

Escape and backdrop dismissal in `CouponFormDrawer` trigger the same unsaved-change warning as close and cancel.

### D-18: Modal test IDs

`ModalShell` and `ModalDialogShell` accept an optional `testId`; do not add a wrapper solely to attach `data-testid`.

### D-20: Delete action on dialog shell

`ModalDialogShell` has no `editing` prop. Render its delete action only when `onDelete` is supplied, and supply that handler only when a deletable record exists.

### D-21: Domain editing state names

Keep domain editing state distinct from generic shell state in `TextSnippetModal`, `ProductModal`, `CustomerModal`, `CouponFormDrawer`, `ProjectModal`, and `PhotoJobModal` (local names: `snippet`, `product`, `customer`, `coupon`, `project`, and `photoJob`). Existing external prop names remain unchanged; changing them requires a new decision.

## Product, privacy, and security

### D-15: Age-proof retention

The current policy has no age-proof deletion path or retention limit. Do not introduce one without a new owner decision.

### D-16: Stripe-related personal data documentation

Document the technical handling of Stripe customer/payment-intent IDs, IP addresses and hashes, fingerprints, failure codes, and Turnstile data. Legal purpose, lawful basis, retention, and deletion/anonymization remain an explicit DPO/legal-owner decision; do not invent those terms.

### D-17: Duplicate mail and prompt-injection policies

- Preserve valid activation/reset tokens rather than replacing them on duplicate requests; atomically claim lifecycle reminders before enqueue. Do not impose blanket queue uniqueness. SMTP transport retries may produce a duplicate copy, but must not cause a second charge or state transition. See [email system](infrastructure/02-email-system.md).
- Treat AI context as untrusted input; delimiter encoding is not a guarantee against prompt injection. The model has no tools, retrieval, or action authority; its bounded metadata output remains untrusted and is never saved without a human click. Prompt-construction tests prove the request contract, not model obedience. See [AI service architecture](ai/01-ai-service-architecture.md).

### D-22: FTP directory provisioning failure status

Return HTTP `503` for FTP inbox directory provisioning failures on both credential-reset and slug-setting paths, without `Retry-After`. Keep `422` for invalid portal prerequisites. See [FTP upload pipeline](infrastructure/19-ftp-upload-pipeline.md).

## CI and pricing/API access

### D-23: Documentation-only CI filtering

Documentation-only changes may skip the full CI pipeline. Keep `paths` with `'**'` as the sole positive pattern and retain re-inclusions for `features/infrastructure/**`, `features/ai/**`, and `features/e2e-test-strategy.md`, along with Dependabot manifest/lockfile coverage. Do not replace the filter with a blanket Markdown ignore. See [CI workflow](../.github/workflows/ci.yml).

### D-24: Frontend Stripe publishable key

The production frontend key belongs in gitignored `frontend/.env.production`; the test key remains in `frontend/.env.local` with `frontend/.env` as its test-key default. Do not rename `.env.local`. The build gate requires a live key and rejects a test key when `.env.production` declares a production build; CI without a declared key is allowed; an undeclared local build must not contain a test key; `STRIPE_TEST_BUILD=true` is allowed only without `.env.production`. See [environment and secret hardening](security/env-hardening.md).

### D-25: Live Stripe secret rotation

Keep the live Stripe keys unrotated under the owner's accepted provider-ZDR decision. This does not permit exposing or committing those values. See [environment and secret hardening](security/env-hardening.md).

### D-26: Stripe self-test coverage

`POST /api/management/settings/stripe-check` always returns five strict checks. E2E asserts only the four environment-deterministic checks; test webhook-endpoint branches in PHPUnit, not by mocking the internal endpoint in E2E. See [environment and secret hardening](security/env-hardening.md).

### D-27: Full license terms access

`GET /api/settings/license-terms` requires `auth:api`, `throttle:api`, then `super_admin`, in that order (anonymous `401`, authenticated non-super-admin `403`). See [pricing strategy](infrastructure/17-pricing-strategy-pattern.md) and [volume licensing presets](infrastructure/27-volume-licensing-presets.md).

### D-28: Public license pricing projection

`GET /api/settings/license-pricing` remains public and returns only `pricing_strategy` and `volume_pricing`. Both public and privileged pricing endpoints use the same context resolution and volume-pricing projection. See [pricing strategy](infrastructure/17-pricing-strategy-pattern.md).

### D-29: Local storage serving

Keep Laravel's local-disk `serve` disabled. Authenticated media delivery uses `FileDeliveryController`; do not expose the private storage disk through a public storage route. See [file delivery](delivery/03-file-delivery-controller.md).

### D-30: Management access to full pricing terms

Gate every UI consumer of the full license-terms payload to super-admin users.

### D-31: Website license-terms import

The separate website repository imports full license terms by logging in as a super-admin and forwarding the httpOnly `rp_jwt` cookie. Credentials come only from environment variables; plan and deploy that integration in its owning repository.

### D-32: Public license catalog

`GET /api/settings/license-catalog` remains public and uses `throttle:api`. Its projection contains customer-facing catalog prices only, never internal calculation parameters. See [pricing strategy](infrastructure/17-pricing-strategy-pattern.md).

### D-33: Dependabot CI secrets

Store the required Stripe **test-mode** values (`STRIPE_SECRET`, `STRIPE_KEY`, `STRIPE_WEBHOOK_SECRET`, `VITE_STRIPE_PUBLIC_KEY`) in Dependabot secrets, not only Actions secrets. The Dependabot branch push is the authoritative CI run; skip the duplicate E2E matrix on its `pull_request` event so the matrix runs once per PR. Treat the test values as visible to checked-out PR code.

### D-34: CI evidence for auto-merged Dependabot heads

For an auto-merged Dependabot commit, accept the green Dependabot-branch push run only when its tree matches the merged `main` tree. This exception applies only to auto-merge heads; a manually pushed head still requires green CI for that exact head.

## FTP import

### D-35: Bounded inbox batches

When an inbox exceeds `ftp.import.max_files` (default `20`), process the first eligible files in stable order up to the file-count or batch-byte limit, return success, and leave the remainder for later runs. A single file over `ftp.import.max_file_bytes` (default `20 MiB`) or a first file exceeding `ftp.import.max_batch_bytes` (default `100 MiB`) remains a visible failure. See [FTP upload pipeline](infrastructure/19-ftp-upload-pipeline.md).

### D-36: Event-driven background import

An SFTPGo upload event enqueues background import work; each job processes a bounded batch and enqueues the next while eligible files remain, stopping on an empty inbox or repeated batch failure. Opening the FTP page triggers a one-shot catch-up for lost events; there is no polling or sweep job. Do not auto-import without a photographer-selected target gallery. Provision the SFTPGo event rule idempotently through the versioned command. See [FTP upload pipeline](infrastructure/19-ftp-upload-pipeline.md).

### D-37: Quarantine of unimportable files

After a configurable number of failed attempts, move a permanently unimportable file to a discoverable quarantine subdirectory. Keep it visible through SFTP and the UI; never delete it. See [FTP upload pipeline](infrastructure/19-ftp-upload-pipeline.md).

## Rate limiting, access grants, and runtime requirements

### D-38: Prioritized atomic rate limiting

Do not convert every framework `throttle:` route and every direct `RateLimiter::hit()` counter to the atomic check-then-count pattern in one sweep. Convert the prioritized security- and billing-relevant paths only; the remaining counters keep the framework behaviour as a documented limit. Every converted path carries its own deterministic concurrency test, in the shape the contract-content route already has. See [review closure](security/2026-10-18-review-closure.md).

### D-39: Re-validate existing gallery grants on read

Re-validate existing gallery grants against the current selection-gallery and subtree safety when they are read, not only when they are granted. A grant that became unsafe after it was issued is fail-closed and loses access; newly granted access keeps the existing fail-closed check.

### D-40: One sorted lock set in `OrgController::syncUsers`

Lock the org row, the actor row, and the member rows in one sorted set, so that two concurrent syncs moving the same user cannot deadlock each other. The proof needs two real database connections; a SQLite-only test cannot establish it.

### D-41: The contact sheet reports omitted photos

The contact sheet states how many photos it omitted and why (byte, dimension, pixel, or MIME check) instead of dropping them silently. The counter belongs to the PDF contract. See [contact sheet export](crm/07-model-contact-sheet-export.md).

### D-42: `ext-gd` is a declared platform requirement

Declare `ext-gd` in `composer.json` instead of keeping it an undocumented runtime assumption: the contact sheet depends on it for JPEG/PNG embedding, and the image processor uses it unconditionally. See [contact sheet export](crm/07-model-contact-sheet-export.md).
