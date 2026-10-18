# Architecture & Security Review 2026-10-18 — Finding Closure

## Status: in progress (updated 2026-10-18)

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
| SEC-08 uploader-controlled active-document Content-Type | P2 | open — follow-ups uncommitted | `8f322cc` + pending | `ImageUploadTest`, `FtpInboxLocalDiskTest` |
| SEC-09 raster dimensions decoded without a pixel budget | P2 | open — follow-ups uncommitted | `8f322cc` + pending | `ModelFileImageBudgetTest`, `ImageProcessorHardeningTest` |
| SEC-10 expired galleries expose photo metadata via context endpoint | P2 | open — follow-ups uncommitted | `8f322cc` + pending | `SearchPhotoContextVisibilityTest` |
| SEC-11 cancelled unpaid orders retain coupon capacity | P2 | **closed** (verify APPROVED) | `ff8c46f` | `CancelStalePaymentIntentsCommandTest` |
| SEC-12 contract content GET writes request-proportional audit rows | P2 | **closed** (verify round 2 APPROVED, measured) | `ff8c46f`, `27e46cf` | `ContractContentHeartbeatMySqlTest`, `AtomicThrottleRequestsTest` |
| SEC-13 contract closure commits before recipient mail is enqueued | P2 | **closed** (verify APPROVED) | `ff8c46f` | `ContractCloseTest` |
| SEC-14 FTP import buffers unbounded inbox files and batches | P2 | open — batches implemented, quarantine (D-37) in flight | pending | `FtpImportTest` |

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
* **Not covered:** every other `throttle:` route in the framework keeps the check-then-increment
  pattern (`api`, `checkout`, `coupon-validate`, `model-registration`, `ai-generate`, `page-exit`).
  Changing that globally is a separate decision, not a side effect of SEC-12. The `file` cache
  store is not atomic across processes either; production runs the `database` store.

### SEC-14 — the import must not dead-end

Measured with `FtpImportTest`; the batch semantics are the owner decision D-35.

* 50 files in the inbox → three runs of **20 / 20 / 10**, each HTTP 200, inbox empty afterwards.
* A file that cannot be imported at all used to block the whole inbox forever (the selection
  aborted with 413, the import loop with 422, and the file was never removed). D-37 moves such
  files to a quarantine subfolder after a configurable number of attempts, so the inbox drains
  and nothing is deleted.

## Non-blocking notes carried forward

* SEC-01..04 (verify round 3): `getOrgGroupScope` is uncached and runs twice per `/api/auth/me`
  and again on the org detail endpoint — a hot path, cost only. The `syncUsers` lock order
  (org → actor → members → sorted union) can theoretically deadlock between two orgs; the
  database breaks one transaction, no corruption. Legacy grants that were created before the
  fix, or groups that become unsafe later, are not re-validated on read — fail-closed on new
  grants, documented as a limit.
* SEC-06/07: a count-only legacy ZIP suppresses explicit photo IDs of the same customer/gallery
  group, so an unreconstructable archive row can under-count in the transition month. A
  `photo_ids` payload is cut at 500 IDs per row; the remainder earns no shares and is not
  double-counted. Snapshots without the payout map fall back to proportional allocation: the
  photographer split may differ, but it cannot overpay.
* SEC-11/13: closure mail is durable for the production database queue; local/testing sync
  queues keep the after-commit callback, so the guarantee is production-scoped.
* SEC-12: the frontend polls content every 5 seconds, i.e. 12 requests/minute against the
  30/minute budget — a 2.5× margin. The deterministic test pins the fix by reproducing the
  framework's repair write (`add()` loses the race, the first `increment()` returns 1): the
  middleware must not write there, and `RateLimiter::hit()` demonstrably does. `RateLimiter::for('checkout', …)`
  is registered in `AppServiceProvider` but referenced nowhere — the checkout quota runs through
  `RateLimiter::hit()` in `CheckoutRiskService`; dead registration, pre-existing.
