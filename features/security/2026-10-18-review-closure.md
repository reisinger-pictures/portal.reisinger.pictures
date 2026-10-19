# Architecture & Security Review 2026-10-18 — Finding Closure

## Status: all findings closed (updated 2026-10-19)

All 14 findings are closed and independently verified. SEC-08/09/10 were implemented in
`8f322cc` and verified in a dedicated read-only round; SEC-14 in the FTP waves
(`8550c4b`, `b5e1d7f`, `c9ec86b`, `4b87f34`). The later rounds (D-38 to D-44) closed the
non-blocking notes this review left behind; they are recorded in the rows below and in
`features/owner-decisions.md`. Nothing below is a prediction; each row states the
verification round that produced the verdict.

This document is the durable record of what the 2026-10-18 source review found and what
was done about it. The findings themselves, as originally written, are in `AGENTS.todo.md`
(section "Architecture & Security Review — 2026-10-18") and in the commit that recorded them
(`d5a412c`). The board keeps only the findings that are still open; this file keeps all of
them with their outcome, because a closed finding is history, not work.

Rules applied here: a finding is **closed** only after an independent verifier returned
`APPROVED` on the final state. An `APPROVED` that carried non-blocking notes keeps those
notes visible below instead of dropping them.

## Closure table

| Finding | Severity | Status | Commit | Regression test |
|---|---|---|---|---|
| SEC-01 org admins reassign foreign users | P1 | **closed** (verify round 3 APPROVED) | `488a17f`, `5e4c615` | `OrgAdminDelegationBoundaryTest` |
| SEC-02 org admins grant unauthorized gallery access | P1 | **closed** (verify round 3 APPROVED) | `488a17f`, `5e4c615` | `OrgAdminDelegationBoundaryTest`, `ManagementUserView*.test.tsx` |
| SEC-03 numeric role ranking lets org admin self-assign photographer | P1 | **closed** (verify round 3 APPROVED) | `488a17f`, `5e4c615` | `OrgAdminDelegationBoundaryTest` |
| SEC-04 any account provisions SFTP credentials via profile update | P1 | **closed** (verify round 3 APPROVED) | `488a17f`, `5e4c615` | `ProfileFtpProvisioningTest` |
| SEC-05 inactive model records downloadable by ordinary admin | P1 | **closed** (verify APPROVED) | `996a02c` | `ModelSensitiveDownloadLifecycleTest` |
| SEC-06 power-user delta ignores coupon and volume discounts | P1 | **closed** (verify round 3 APPROVED) | `996a02c`, `6433d04` | `PayoutCalculationServiceTest`, `CheckoutServiceTest`, `PayoutOrderFeeAllocationTest` |
| SEC-07 repeated single downloads inflate the monthly pool | P1 | **closed** (verify round 3 APPROVED) | `996a02c`, `6433d04` | `PayoutCalculationServiceTest` |
| SEC-08 uploader-controlled active-document Content-Type | P2 | **closed** (verify APPROVED) | `8f322cc` | `ImageUploadTest`, `FileDeliveryControllerTest`, `FtpInboxLocalDiskTest` |
| SEC-09 raster dimensions decoded without a pixel budget | P2 | **closed** (verify APPROVED) | `8f322cc`, `ae1a9ef`, `021aeb3` | `ModelFileImageBudgetTest`, `ImageProcessorHardeningTest` |
| SEC-10 expired galleries expose photo metadata via context endpoint | P2 | **closed** (verify APPROVED) | `8f322cc` | `SearchPhotoContextVisibilityTest` |
| SEC-11 cancelled unpaid orders retain coupon capacity | P2 | **closed** (verify APPROVED) | `ff8c46f` | `CancelStalePaymentIntentsCommandTest` |
| SEC-12 contract content GET writes request-proportional audit rows | P2 | **closed** (verify round 2 APPROVED, measured) | `ff8c46f`, `27e46cf` | `ContractContentHeartbeatMySqlTest`, `AtomicThrottleRequestsTest` |
| SEC-13 contract closure commits before recipient mail is enqueued | P2 | **closed** (verify APPROVED) | `ff8c46f` | `ContractCloseTest` |
| SEC-14 FTP import buffers unbounded inbox files and batches | P2 | **closed** (verify APPROVED) | `8550c4b`, `b5e1d7f`, `c9ec86b`, `4b87f34` | `FtpImportTest`, `FtpImportBatchTest`, `FtpQuarantineTest`, `FtpImportDefaultsTest`, `FtpStatCacheOrderTest`, `FtpEventEndpointTest`, `ProvisionFtpEventsTest` |

## Measured evidence (not assertions)

These two findings were the ones where a plausible implementation was **not** enough, so
their outcome rests on a measurement. Both numbers carry the command that reproduces them.

### SEC-12 — the rate limiter did not hold under concurrency

Measured on a disposable `mariadb:11.4` (11.4.13-MariaDB-ubu2404) with 40 simultaneously
released clients against `GET /api/contracts/sign/{personalToken}`:

```
cd backend && DOCKER_BIN="$HOME/.rd/bin/docker" ./tests/scripts/contract-content-mariadb-probe.sh
```

* Before the atomic counter: **32–37** HTTP 200 and 32–37 heartbeat rows against a limit of
  30/minute (four runs).
* After it: **30 × 200 / 10 × 429 / 30 heartbeat rows** in all four runs, exit 0, 0 deadlocks,
  0 × 5xx, 50–60 InnoDB row-lock waits (ordinary queueing).
* Root cause of the overshoot: Laravel's `ThrottleRequests` checks `tooManyAttempts()` and only
  then calls `RateLimiter::hit()`; and `RateLimiter::increment()` ends with an unconditional
  repair write that can roll a foreign counter back when a worker that lost the `add()` race
  counts from 0 to 1. `AtomicThrottleRequests` therefore counts with `add()` + `increment()`
  and never writes unconditionally.
* **Not covered:** the remaining `throttle:` routes keep the framework's check-then-increment
  pattern and stay a **documented limit**: the shared API safety net (`api`), the AI endpoints
  (`ai-generate`), the model-registration and model-profile group (`model-registration`), the
  positional auth and invite-redeem throttles, the single-photo and ZIP download throttles, the
  `page-exit` route (`60,1`), and the FTP credential reset counter in `FtpCredentialService`.
  Owner decision D-38 converts the prioritized security- and billing-relevant paths only:
  * the **checkout quota** in `CheckoutRiskService::assertCheckoutQuotaAllowed()` (per-user hour,
    per-IP hour, per-IP day),
  * the **Turnstile attempt and failure-velocity counters** in `CheckoutRiskService`
    (`assertImmediateStripeAllowed()`, `recordVerifiedPaymentFailure()`),
  * the **coupon validation route** (`POST /api/coupons/validate`, named limiter
    `coupon-validate`),
  * the **Stripe webhook route** (`POST /api/webhooks/stripe`, positional `10,1`).

  All four count before they decide through the shared primitive
  `App\Support\AtomicRateCounter` (the same primitive `AtomicThrottleRequests` uses), keep the
  framework key shape, and never write the counter blindly. Every converted path carries its own
  deterministic concurrency test in `backend/tests/Feature/RateLimit/`. A counter that cannot be
  determined — the row was deleted between `add()` and `increment()`, e.g. by a concurrent
  `cache:clear` — is handled **fail-closed**: checkout quota answers 429, the Turnstile attempt
  counters require the challenge, and the failure-velocity counters take the existing best-effort
  `Log::warning` path. The old code counted such a counter as zero, which widened the budget and
  skipped the challenge. The visible residue is a small number of spurious 429s and extra
  challenges inside a cache-eviction window; details in
  `features/ecommerce/10-digital-contracts.md`, section "Offene Grenzen der neuen Middleware".
  Converting the rest globally is a separate decision, not a side effect of SEC-12. The `file`
  cache store is not atomic across processes either; production runs the `database` store.

### SEC-14 — the import must not dead-end

Measured with `FtpImportTest`; the batch semantics are the owner decision D-35.

* 50 files in the inbox → three runs of **20 / 20 / 10**, each HTTP 200, inbox empty afterwards.
* A file that cannot be imported at all used to block the whole inbox forever (the selection
  aborted with 413, the import loop with 422, and the file was never removed). D-37 moves such
  files to a quarantine subfolder after a configurable number of attempts, so the inbox drains
  and nothing is deleted.

## Non-blocking notes carried forward

* SEC-01..04 (verify round 3): `getOrgGroupScope` is uncached and runs twice per `/api/auth/me`
  and again on the org detail endpoint — a hot path, cost only. The `syncUsers` lock order is
  **org → one ascending user lock set** (D-40): the org row is locked first, then the actor, the
  current members and the incoming members are locked in a single primary-key range scan, so two
  concurrent syncs over different orgs whose actors are members of each other's org serialise
  instead of deadlocking. The former order (org → actor → members → sorted union) locked the
  actor outside the sorted set and could form that cycle; the database breaks one transaction,
  no corruption. The two-connection proof runs against a disposable MariaDB
  (`backend/tests/scripts/org-sync-deadlock-mariadb-probe.sh`, CI-only). `OrgController::destroy()`
  takes the same org row lock first, so the order is **org → users** in that path as well and a
  destroy cannot close a cycle with a sync of the same org. That order is pinned by
  `OrgDestroyLockOrderTest`; the two-connection measurement of the destroy path is still open (it
  needs a host with Docker). **The trade-off:** the org row lock serialises the *syncs* and the
  *destroy* of one org, not every write to `users.org_id` — none of
  `OrgInviteController::redeem()`, `UserController::store()`,
  `AuthController::register()`/`resetPassword()` (membership added) takes it. The member set has to
  be known before the single lock query, so it is now read **unlocked** (a consistent read) and the
  membership decision is taken on the locked rows. The set is therefore read *earlier* in the
  transaction than the old
  `SELECT … FOR UPDATE` on `org_id` was, and that old read was a current read that saw the latest
  committed state. A member that a non-sync writer adds in the new window — between the unlocked
  read and the lock statement — is **no longer detached** by that sync, while before it was. The
  bound: the sync only writes members it observed as members under the lock, so the membership
  granted by the other writer simply stands and no role, flatrate or upgrade right is revoked for
  it; no authorization rule is weakened.
  **Legacy grants are re-validated on read (D-39):** a persistent client grant of a selection
  gallery is void, and a group grant whose subtree holds a selection gallery is refused as a
  whole — the same rule the new-grant path applies, shared as one computation. The write path
  offers delivery galleries only (D-43), so a grant that could not have been issued does not
  survive either. A re-resolved actor keeps the transient claims of the request (D-44), so a
  client who redeemed an invite still reaches the media of their selection gallery.
* SEC-06/07: a count-only legacy ZIP suppresses explicit photo IDs of the same customer/gallery
  group, so an unreconstructable archive row can under-count in the transition month. A
  `photo_ids` payload is cut at 500 IDs per row; the remainder earns no shares and is not
  double-counted. Snapshots without the payout map fall back to proportional allocation: the
  photographer split may differ, but it cannot overpay.
* SEC-11/13: closure mail is durable for the production database queue; local/testing sync
  queues keep the after-commit callback, so the guarantee is production-scoped.
* SEC-08: two residual surfaces, both outside the photo delivery the finding was about.
  `SettingsController::getWatermarkSvg()` serves the brand watermark as `image/svg+xml` — an
  active-document type — pre-existing, with `Content-Security-Policy: default-src 'none';
  style-src 'unsafe-inline'; sandbox`, `nosniff` and `no-store`; the edge Caddy CSP was not
  inspected. `ModelManagementController::photo()` uses the uploader-provided `original_name`
  as the download file name; the Content-Type is the store-time detected mime and the
  disposition is `attachment` beside the global `nosniff`.
* SEC-12: the frontend polls content every 5 seconds, i.e. 12 requests/minute against the
  30/minute budget — a 2.5× margin. The deterministic test pins the fix by reproducing the
  framework's repair write (`add()` loses the race, the first `increment()` returns 1): the
  middleware must not write there, and `RateLimiter::hit()` demonstrably does. D-38 extended
  the count-first pattern to the prioritized paths: the checkout quota and the Turnstile and
  failure-velocity counters in `CheckoutRiskService` now count through
  `App\Support\AtomicRateCounter`, the coupon validation route and the Stripe webhook route
  through `AtomicThrottleRequests`. `RateLimiter::for('checkout', …)` in `AppServiceProvider`
  is **not** a dead registration: no route carries `throttle:checkout` (the quota is
  enforced in the service), but `CheckoutRateLimitTest` reads
  `RateLimiter::limiter('checkout')` as the declaration of the three bounds the service
  enforces.
* SEC-14: two residuals, both visible outside the finding. The **transport quota** is still
  open: `SftpGoClient` provisions the camera account without an SFTPGo `quota`, so a camera
  credential can still fill the host disk — the new budgets bound only what the importer
  reads per run (20 files, 20 MiB per file, 100 MiB per run). The **catch-up** is documented
  thinly: the feature doc lists it as one row of the trigger table, while the semantics that
  matter ("once per mount, not per cache entry", "no target gallery ⇒ no import", "the
  decision rests on a read this mount performed") live only in the component and its tests.
