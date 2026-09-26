---
domain: infrastructure
topic: payout-system
status: active
---

# Technical Concept: Photographer Payout System (Weighted Share Pool-Modell)

## 1. Overview & Business Logic
The system remunerates photographers based on actual usage (downloads) by end customers. To guarantee financial security and fairness, a "Weighted Share" model is applied within isolated flat-rate pools.

### 1.1 Core Rules
* **Currency & Data Type (Money Pattern):** Implicitly Euro (€). All financial values are stored as integers in **Cents**.
* **Isolated Flatrate Pools:** Each sold flat-rate category (e.g., "Basic Package", "Premium Package") forms a completely isolated money pool per month.
* **Weighted Shares:** Within a pool, revenues are *not* split 1:1 by the number of downloads. Instead, each download generates "shares" based on its value (e.g., resolution/license). 
  * *Example:* A "Web/Editorial" download generates 1 share. A "High-Res/Commercial" download generates 4 shares (multipliers are derived from `PricingFactor` / `LicenseUseCase`).
* **Unique Downloads & Deduplication:** One image per end customer and calendar month counts a maximum of **1x**.
  * *The "Best Share" Rule:* If a customer downloads the same image multiple times under different conditions (e.g., 1x ZIP Web-Res, 1x single High-Res), only the download with the **highest share value** is used for billing.
* **Exclusion of Free Downloads:** Downloads from galleries where `is_free_download = true` are strictly ignored and generate **0 shares**.
* **Power-User Surcharge (Delta Logic):** If a flat-rate customer pays an explicit surcharge for a download, this net delta (after Stripe fees) is distributed *directly* to the photographer, independently of the share pool.
* **Sleeper Customers:** Flat-rate revenues from customers without downloads remain 100% with the platform.

### 1.2 MVP Workflow & Visualization
1. **Calculation:** A cron job calculates the pools at the beginning of the month, determines the total shares per pool, and calculates the `value_per_share_cents`.
2. **Visualization (Management UI):** The Super Admin sees the total unique downloads vs. generated shares per pool (e.g., "100 Downloads ≙ 240.5 Shares") and the value of a single share.
3. **Approval:** The Super Admin approves the statements.
4. **Statement PDF:** Generation of the credit overview for the photographer.
5. **Payout:** Photographers can request a payout once their total balance reaches **50€**.

## 2. Database Schema

### 2.1 `payout_pools`
* `id` (UUID, Primary)
* `month`, `year` (Integer)
* `product_id` (UUID, Foreign Key)
* `gross_amount_cents`, `stripe_fee_cents`, `net_pool_cents` (Integer)
* `photographer_share_percent` (Integer)
* `total_unique_downloads` (Integer - Statistical)
* `total_shares` (Decimal 8,2)
* `value_per_share_cents` (Integer)

### 2.2 `photographer_statements`
* `id` (UUID, Primary)
* `user_id` (UUID, Foreign)
* `sequence_number` (String)
* `month`, `year` (Integer)
* `total_shares_earned` (Decimal 8,2)
* `pool_earnings_cents` (Integer)
* `delta_surcharge_earnings_cents` (Integer)
* `earned_amount_cents` (Integer)
* `rolled_over_amount_cents` (Integer)
* `total_payable_cents` (Integer)
* `status` (Enum: `pending`, `rollover`, `approved`, `paid`)

## 3. Data-integrity contract

### 3.1 Natural keys and duplicate policy (P1-M16)

The active calculation workflow has one aggregate pool per calendar month and
one statement per photographer and calendar month. Its natural keys are therefore
`(year, month)` for `payout_pools` and `(user_id, year, month)` for
`photographer_statements`. The nullable `payout_pools.product_id` column is not
part of the active key until product-scoped pool calculation is introduced;
adding it now would make the current global pool ambiguous because SQL unique
indexes do not consistently treat multiple `NULL` values as conflicts across
supported drivers.

`V040__enforce_payout_natural_keys.php` is the deployment boundary for these
constraints. Before adding either unique index, the migration audits both
natural keys and emits a bounded duplicate report. **Existing financial rows
are never deleted, merged, or silently selected as a winner.** If a duplicate
group exists, the migration aborts and an operator must reconcile the reported
rows in a controlled maintenance procedure before retrying it. The finance
owner must document whether the rows are data-entry duplicates or distinct
historical events; if they are distinct events, the pool/statement scope must
be corrected before the constraint is retried. Once the preflight is clean,
the named unique indexes are the durable authority for
future writes. Run the migration during the payout-write maintenance window;
if a concurrent write still slips past the scan, index creation fails closed
and the migration can be retried after the write is reconciled. V011 and
earlier deployed migrations remain unchanged.

On MySQL/MariaDB, replacing the statement index first verifies that another
index begins with `user_id` and creates `photographer_statements_user_id_fk_support`
when needed. The composite natural-key index can therefore be replaced without
leaving the `users` foreign key without a supporting index. PostgreSQL and
SQLite keep their existing branches and do not receive that MySQL-only index.

Payout writers use a race-safe create-or-read primitive followed by a
transactional row lock. The unique index resolves a concurrent first insert;
the lock makes the subsequent money arithmetic atomic and ensures an
`approved`/`paid` statement is checked while locked before any contribution is
added. A calculation replay therefore cannot create a second natural-key row.

`PayoutCalculationService::calculatePoolShares()` is the authoritative
calculation for the single pool identified by `(year, month)`: it replaces the
pool-derived `total_shares_earned` and `pool_earnings_cents` fields for the
month, while preserving surcharge earnings and locked statements. Calling the
service directly again with the same logs is therefore idempotent. The
power-user surcharge pass is a separate additive step and is run after the
pool replacement.

### 3.2 Full-ZIP audit counts

Every new `full_zip` `DownloadLog` must provide an explicit, positive
`photo_count`. The current gallery and order download paths always derive this
count from the files they prepared. The `DownloadLog` model rejects omitted,
zero, and negative counts on creation so the historical database default of
`1` cannot silently under-count a new archive.

**External contract of the empty-archive branch.** `photo_count` is derived
from the *prepared* files, not from the number of persisted `Photo` rows: a
photo whose source file is missing on the photos disk is skipped during
preparation and is not counted. The gallery ZIP endpoint
(`GET /api/galleries/{id}/download-zip`) and the order ZIP endpoint
(`GET /api/orders/{id}/download-zip`) therefore both fail closed when
preparation yields zero files:

- HTTP status `422 Unprocessable Entity` with the exact German message
  `Der ZIP-Download enthält keine Bilder.`
- No ZIP bytes and **no `download_log` row** — neither a partial row nor a
  zero-count row. A zero-count archive is never auditable and must never
  reach payout attribution.

The two endpoints wrap that message differently, and both shapes are part of
the contract: the gallery branch catches the abort and answers
`{"error": "Der ZIP-Download enthält keine Bilder."}`, while the order branch
lets the exception reach the framework and answers
`{"message": "Der ZIP-Download enthält keine Bilder."}` for JSON clients.
Both are covered by regression tests; a third-party ZIP client must therefore
treat either key as the authoritative reason and must not infer the count from
the absence of a log row.

Existing legacy rows are not rewritten or deleted. A stored positive legacy
count (including the historical `1` fallback) remains readable and is used by
payout attribution; malformed non-positive legacy values are ignored by the
read-side calculation guard rather than converted into a payout.
