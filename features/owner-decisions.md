# Owner Decisions

This is the canonical current contract for owner decisions D-1 through D-49. Apply these decisions unless a later reviewed decision replaces one. Implementation status belongs in `AGENTS.todo.md`; domain specifications carry the detailed system contracts.

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

### D-48: The contact sheet ships as a collection, and the customer link carries the external sheet only

The bulk export ("Ergebnis als Contact Sheet") is a **second document**, not a repetition of the single sheet: one tile per profile on A4, at most 25 profiles, and exactly **one** photo per profile — the tile grid is a memory budget; a document must not carry the 12 photos of a single sheet for 25 profiles. The privacy split is unchanged and per variant: the internal tile carries the real name, the city and the e-mail, the external tile only the stage name (never a fallback to the real name), the city and public photos only. A selection that contains a profile of another brand or an id that does not resolve fails the **whole** export with 404 instead of silently narrowing the selection — a dropped profile would change the document without a trace.

The shared link replaces the authenticated download for the recipient: it is a signed JWT (the same secret and verification path as the login token), valid for **seven days**, bound to the brand, and it can carry **only** the external variant, because the internal variant contains PII. The token is a bearer credential and never appears in a log line, an audit context or an error message; the audit lines carry the `link_id` (a UUID) instead. Every failure of the public endpoint — invalid, expired, wrong brand, no profile left — answers with the same 404 and the same message, so the endpoint is no token oracle. Profiles that no longer exist are skipped and **named** in the document (count only, PII-free): a silent narrowing is not allowed, and a link without any remaining profile is a 404. The delivery request carries no session cookie (`credentials: 'omit'`), so the link works exactly as the bearer credential it is. Two limits are accepted and documented: the link is **not revocable** before it expires (a new link does not invalidate an older one), and the token payload carries the profile ids in clear text — they are opaque UUIDs, and the endpoint only delivers what the signature covers. See [model contact sheet export](../crm/07-model-contact-sheet-export.md).

## CI and pricing/API access

### D-23: Documentation-only CI filtering

Documentation-only changes may skip the full CI pipeline. Keep `paths` with `'**'` as the sole positive pattern and retain re-inclusions for `features/infrastructure/**`, `features/ai/**`, `features/security/**`, and `features/e2e-test-strategy.md`, along with Dependabot manifest/lockfile coverage. Do not replace the filter with a blanket Markdown ignore. See [CI workflow](../.github/workflows/ci.yml).

**Revised on 2026-10-21 — the rule stands, the record was behind the workflow.** The previous wording named three re-inclusions (`features/infrastructure/**`, `features/ai/**`, `features/e2e-test-strategy.md`); the workflow has carried a fourth, `features/security/**`, since `8c0c32f` turned the security findings into a gate. The re-inclusions stay deliberately conservative: they cost redundant runs, and a redundant run is loud and self-healing, while a filtered-out change is silent. The reason that once justified them has expired: the ghcr-namespace gate read prose until 2026-10-18, when `InfrastructureSupplyChainPolicyTest::containerArtifactFiles()` stopped walking Markdown — of the 139 Markdown files it walked, five name a `ghcr.io/<organisation>` image reference and none carried a finding the delivered artifacts do not also carry. The re-inclusions therefore no longer keep image-doc drift gated; they are kept because dropping them buys nothing but silence. No filter behaviour changes with this revision.

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

### D-47: The brand overlay wins over the settings row for the pricing strategy

The per-brand pricing strategy is written through the brand-settings overlay (`brand_config.pricing_strategy`) and is resolved in exactly one place (`App\Services\PricingStrategyResolver`): brand overlay → the brand's own `settings.pricing_strategy` row → the canonical `rp` row → `scope_licensing`. The overlay is a delta over the config, and `pricing_strategy` deliberately has **no** config default — the settings row stays the authoritative fallback for a brand without an overlay, so the editor cannot silently take a brand's pricing mode away. A stored value that is neither `scope_licensing` nor `volume_licensing` is **ignored** and the resolution continues with the next source: the mode is a closed set everywhere it is consumed, and an unknown value must never reach a strategy binding or a gallery mode. Every direct read of the setting goes through the resolver — the gallery mode, the `PricingStrategy` binding, the checkout grouping and the public pricing endpoint read the same value, so they cannot disagree. The editor shows the mode that is in force **and where it comes from** (overlay, settings row or default) and carries the *overlay* value in the form, never the effective one: hydrating the form with the effective value would create an overlay for a mode that is merely in force. The gallery's own `licensing_mode` still wins over the brand default. See [pricing strategy](infrastructure/17-pricing-strategy-pattern.md) and [brand settings overlay](infrastructure/22-brand-settings-overlay.md).

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

Lock the org row, the actor row, and the member rows in one sorted set, so that two concurrent syncs moving the same user cannot deadlock each other. The org row lock serialises syncs of one org, not every write to `users.org_id`: the member set is read unlocked before the single lock query, so a member that a non-sync writer adds in that window (invite redemption, org-admin user creation, registration/auto-join) is no longer detached by that sync — it is not written at all, so none of its roles, flatrate or upgrade rights are revoked by the sync. `OrgController::destroy()` takes the same org row lock first and takes its member rows in the same ascending id order, so a destroy and a sync of one org cannot hold the org row and a member row in opposite orders; the destroy still locks its members one statement at a time, and it writes a member's `user_roles` pivot row before that member's `users` row, while the sync takes all of its `users` locks before any pivot write. The proof needs two real database connections; a SQLite-only test cannot establish it.

**The proof was made on 2026-10-21** with the disposable-MariaDB probe `backend/tests/scripts/org-sync-deadlock-mariadb-probe.sh` — 60 scenarios, each firing two concurrent syncs over two orgs whose actors are members of each other's org. Result: 120 concurrent syncs, all HTTP 200, **23** InnoDB row-lock waits as the positive control (the transactions really contended) and **0** deadlocks. The **negative control** — the pre-D-40 lock order taken from `61186f6^` — produced **14** deadlocks and **14** HTTP 500 in the same 60 scenarios, so the probe does detect the cycle it exists to detect. The destroy variant of the same measurement (`SECOND_ACTION=destroy`: a concurrent org destroy against a concurrent org sync, with the members' `user_roles` pivot write active) produced **0** deadlocks with **15** row-lock waits: **this shape** forms no cycle. It does **not** show that the destroy's residual pivot order is safe — in this fixture the destroy pivots the member of the second org and the sync pivots the member of the first, so the two pivot sets are disjoint and the pivot order is never load-bearing. Reaching that residual needs a concurrent membership move (a third writer that moves a member between orgs while the destroy runs), which the probe does not model. A sample is not a proof of absence: the run can only show that no cycle appeared in the contended scenarios it executed.

### D-41: The contact sheet reports omitted photos

The contact sheet states how many photos it omitted and why (byte, dimension, pixel, or MIME check) instead of dropping them silently. The counter belongs to the PDF contract. See [contact sheet export](crm/07-model-contact-sheet-export.md).

### D-42: `ext-gd` is a declared platform requirement

Declare `ext-gd` in `composer.json` instead of keeping it an undocumented runtime assumption: the contact sheet depends on it for JPEG/PNG embedding, and the image processor uses it unconditionally. See [contact sheet export](crm/07-model-contact-sheet-export.md).

### D-43: The direct gallery assignment offers delivery galleries only

A direct gallery assignment to a user (`gallery_ids` on the user update) may only carry delivery galleries — the same rule the org-admin path already enforces through the org scope. A newly requested non-delivery gallery is rejected by the API and is not offered in the management UI. An already assigned gallery is not silently revoked by an unrelated save; it stays visible until it is removed. A selection gallery reaches a client through an invite, not through a direct assignment.

### D-44: A re-resolved actor keeps its validated transient claims

When the media delivery and the photo download re-resolve a registered actor from the database, they carry the transient claims of the request onto the re-resolved actor. The guest branch already does this; the registered branch dropped them and denied a client who had redeemed an invite access to the media of their selection gallery. The carried claims are the ones the request's token was validated with, and the authorization layer re-validates them against the live invite state; a token that carries gallery claims without invite provenance (issued before the provenance existed) is carried as it is, like every other controller that uses the request's actor directly.

### D-45: The gallery notification subscription has its own table

A gallery notification opt-in is a subscription, never a grant. It lives in its own table (`gallery_notification_subscriptions`) and no longer writes into `user_galleries`, the pivot that carries the client grant. A subscription must not create access and must not keep a gallery reachable after the membership that justified it was revoked. The legacy rows that carried the opt-in flag are ambiguous — the flag cannot separate an opt-in-only row from a real grant that was additionally subscribed to — so the migration moves the subscription state and does not delete pivot rows; the remainder stays a documented residual that the owner clears deliberately with `notifications:prune-legacy-opt-in-grants`. That command lists every flagged row with its verdict and deletes nothing in its dry run. `--apply` deletes **all** candidate rows unless the operator selects them: `--only=<user_id>:<gallery_id>` (repeatable) or `--only-ids=<file>` restricts the deletion to the verified rows, and a selected row that is no longer a candidate is reported and kept. A selected pair that matches no flagged row is reported as `unmatched` and ends the run with a non-zero exit code, so a typo in an id cannot look like a successful repair. A candidate is not proof of an opt-in-only row — the same shape is produced by a real grant that was additionally subscribed to — so every candidate must be verified individually or repaired by hand before it is removed. The group opt-in pivot `user_gallery_groups` carries the same structural defect and is not covered by this decision. See [email system & notifications](infrastructure/02-email-system.md), [IDOR protection](auth/01-roles-and-access.md) and [AD-9](tech/07-architectural-decisions.md).

### D-46: The group notification subscription has its own table

A group notification opt-in is a subscription, never a grant, exactly like the gallery opt-in in D-45. It lives in its own table (`group_notification_subscriptions`) and no longer writes into `user_gallery_groups`, the pivot that carries the client group grant — a group grant covers the whole subtree of the group, so an opt-in stored there produced a grant over the subtree and outlived the membership that justified it. A subscription must not create access and must not keep a group reachable after the membership that justified it was revoked; it is also not part of the grant, so it must not be destroyed together with the grant. The legacy rows that carried the opt-in flag are ambiguous — the flag cannot separate an opt-in-only row from a real grant that was additionally subscribed to — so the migration moves the subscription state and does not delete pivot rows; the remainder stays a documented residual that the owner clears deliberately with `notifications:prune-legacy-group-opt-in-grants`. That command lists every flagged row with its verdict and deletes nothing in its dry run. `--apply` deletes **all** candidate rows unless the operator selects them: `--only=<user_id>:<gallery_group_id>` (repeatable) or `--only-ids=<file>` restricts the deletion to the verified rows, and a selected row that is no longer a candidate is reported and kept. A selected pair that matches no flagged row is reported as `unmatched` and ends the run with a non-zero exit code, so a typo in an id cannot look like a successful repair. A candidate is not proof of an opt-in-only row — the same shape is produced by a real grant that was additionally subscribed to — so every candidate must be verified individually or repaired by hand before it is removed. The grant reader stays untouched: the legacy rows keep granting until the owner removes them deliberately. The preferences endpoint keeps its response shape and lists a subscription without a grant so that it stays revocable, but the name of a group the caller can no longer reach is not disclosed. See [email system & notifications](infrastructure/02-email-system.md), [IDOR protection](auth/01-roles-and-access.md) and [AD-10](tech/07-architectural-decisions.md).

### D-49: MariaDB is the production database engine

The project runs **MariaDB** in production. This is a deliberate deviation from the `laravel` skill's database standard, which names PostgreSQL for development and production and marks MySQL as deprecated for new work: the project does not follow that standard and records the deviation here instead of leaving it undocumented. The default connection is `mariadb` (`backend/config/database.php:20`), the deployment stack provisions the `mariadb:11.4` service (`deployment/docker-compose.yml:5`, `DB_CONNECTION=${DB_CONNECTION:-mariadb}` in Z. 101), and the production runbook names the same engine (`features/infrastructure/29-production-operations-runbook.md:48`). Local development uses a SQLite file and the test suite uses SQLite `:memory:` (`backend/AGENTS.md:28`, `backend/AGENTS.md:41`, `backend/phpunit.xml:39-40`); the test engine is not part of this decision and stays as it is.

The engine is not changed by this decision, and changing it needs a decision of its own: it would be a migration of the live database, not a configuration change, and the engine-dependent parts of the schema and the queries would have to be reworked with it — the case-insensitive collation of `ratings.actor_key` and the portable unique index that avoids MySQL/MariaDB partial-index syntax (`features/tech/01-database-schema.md`), and the MariaDB-safe column alias `contract_database_now` (`backend/app/Models/Contract.php:262`). The decision that needs two real database connections is proven against MariaDB: the D-40 lock-set proof runs on the disposable-MariaDB probe, because a SQLite-only test cannot establish it. See [database schema](tech/01-database-schema.md) and [production runbook](infrastructure/29-production-operations-runbook.md).
