---
domain: infrastructure
topic: payout-system
status: active
---

# Technical Concept: Photographer Payout System (Weighted Share Pool-Modell)

## 1. Overview & Business Logic
The system remunerates photographers based on actual usage (downloads) by end customers. To guarantee financial security and fairness, a "Weighted Share" model is applied within isolated flat-rate pools.

### 1.1 Core Rules
* **Currency & Data Type (Money Pattern):** All monetary amounts are **cents**, in storage and on the API, without exception. The money rule itself is stated once and only once, in [`../tech/02-backend-architecture.md` § 4](../tech/02-backend-architecture.md) (Owner-Entscheidung 2026-09-28); this line scopes it to the payout tables, it does not restate it. The currency is fixed to EUR and is not stored as a column. Two payout columns are deliberately **not** money and keep their own unit: `total_shares` / `total_shares_earned` are `decimal(12,4)` share counts (`V011__payout_system.php:21,32`) and `photographer_share_percent` is a percentage in points (`V011__payout_system.php:19`, default `50`).
* **Isolated Flatrate Pools:** Each sold flat-rate category (e.g., "Basic Package", "Premium Package") forms a completely isolated money pool per month.
* **Weighted Shares:** Within a pool, revenues are *not* split 1:1 by the number of downloads. Instead, each download generates "shares" based on its value (e.g., resolution/license). 
  * *Example:* A "Web/Editorial" download generates 1 share. A "High-Res/Commercial" download generates 4 shares (multipliers are derived from `PricingFactor` / `LicenseUseCase`).
* **Unique Downloads & Deduplication:** One identified image per end customer and pool month counts a maximum of **1x**.
  * *The "Best Share" Rule:* Single-image, gallery-ZIP, and mixed-gallery order-ZIP logs contribute to one ledger keyed by customer + photo + pool month. If a customer downloads the same identified image several times under different conditions (e.g., 1x ZIP Web-Res, 1x single High-Res), only that photo's **highest share value** is used. The ZIP tier does not raise other photos in the same archive, and a ZIP/single overlap does not count the photo twice.
* **Exclusion of Free Downloads:** Downloads from galleries where `is_free_download = true` are strictly ignored and generate **0 shares**.
* **Power-User Surcharge (Delta Logic):** If a flat-rate customer pays an explicit surcharge for a download, this net delta (after Stripe fees) is distributed *directly* to the photographer, independently of the share pool.
* **Sleeper Customers:** Flat-rate revenues from customers without downloads remain 100% with the platform.
* **Arrears & closed statements:** A month's payout is calculated after that month closes, one month in arrears. `approved` and `paid` statements are immutable. A later refund is booked as an explicit negative correction in the next finalized statement after the originating period, never by changing the closed statement.

### 1.2 MVP Workflow & Visualization
1. **Calculation:** After a calendar month closes, the calculation for that month runs in the following month. It calculates the pools, total shares, and `value_per_share_cents` from the closed month's activity.
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
* `total_shares` (Decimal 12,4 — see §1.1, `V011__payout_system.php:21`)
* `value_per_share_cents` (Integer)

### 2.2 `photographer_statements`
* `id` (UUID, Primary)
* `user_id` (UUID, Foreign)
* `sequence_number` (String)
* `month`, `year` (Integer)
* `total_shares_earned` (Decimal 12,4 — see §1.1, `V011__payout_system.php:32`)
* `pool_earnings_cents` (Integer)
* `delta_surcharge_earnings_cents` (Integer)
* `earned_amount_cents` (Integer)
* `rolled_over_amount_cents` (Integer)
* `total_payable_cents` (Integer)
* `status` (Enum: `pending`, `rollover`, `approved`, `paid`)

### 2.3 `payout_corrections`
* `id` (UUID, Primary)
* `order_id`, `user_id` (UUIDs identifying the originating order and photographer; cascade with their owning records)
* `refund_amount_cents` (Unsigned Integer — cumulative refund snapshot used for this idempotent correction)
* `amount_cents` (Signed Integer, always negative)
* `closed_month`, `closed_year` (the approved/paid statement period being corrected)
* `settled_at` (nullable timestamp; set when a finalization books the correction)
* `settled_month`, `settled_year` (nullable integers; the statement period in which the correction was actually booked)
* `created_at`, `updated_at` (timestamps)

Corrections have no target period. The natural key is
`(order_id, user_id, closed_year, closed_month, refund_amount_cents)`. A later
cumulative refund creates a separate incremental line; a replay of the same
cumulative amount cannot create another audit line. The correction row remains
the refund audit record; its settlement fields mirror the matching refund item
in `payout_open_items`.

### 2.4 `payout_open_items`
* `id` (UUID, Primary)
* `user_id` (UUID, Foreign Key to `users`)
* `source_type` (`carry` or `refund`)
* `source_id` (nullable UUID: originating statement for carry, correction row for refund)
* `order_id` (nullable UUID; populated for refunds)
* `origin_month`, `origin_year` (the period the item belongs to)
* `amount_cents` (Signed Integer; carries may be positive, zero, or negative)
* `settled_month`, `settled_year`, `settled_at` (nullable actual booking period and time)
* `created_at`, `updated_at` (timestamps)

The unique `(source_type, source_id)` key permits at most one item per source.
The global claim index is `(settled_at, origin_year, origin_month)`; the
user-scoped claim index is `(user_id, settled_at, origin_year, origin_month)`;
the settlement lookup index is `(settled_year, settled_month)`. The migration
re-runs both backfills when the table already exists and inserts by the unique
source key, so a retry completes missing rows without duplicating existing ones.

V051 backfills every existing `payout_corrections` row one-to-one as a refund
item, preserving its closed period, signed amount, and settlement state. It
backfills a carry only from the photographer's latest statement overall, and
only when that statement is a non-zero `rollover`. Earlier rollover statements
already flowed into later statements; backfilling all of them would book the
same balance more than once. If the latest statement is `pending`, `approved`,
or `paid`, no carry is backfilled. Existing statement money columns are never
rewritten. A rollback that drops the new table would lose open-item state while
leaving `payout_corrections` and all statement rows untouched; migration
`down()` is intentionally empty under the repository migration policy.

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

### 3.3 Explicit photo ledger and conservative legacy boundary

`PayoutCalculationService::calculatePoolShares()` builds one in-memory ledger
keyed by customer + photo for the pool month. A **single_image** row supplies
`payload.photo_id`; a **full_zip** row supplies `payload.photo_ids`. Both feed
the same ledger, so explicit identities are resolved once regardless of whether
they arrived through a single download, a gallery archive, or a mixed-gallery
order archive. A higher tier replaces only that photo's lower multiplier; it
never promotes every photo in a ZIP. Photo ownership is read from the identified
`Photo.user_id`, and free-download galleries are excluded before a photo enters
the ledger. A mixed-gallery ZIP whose `payload.gallery_ids` constraint is
malformed or contains no IDs contributes nothing; it never falls back to
"every photo in every archive".

The unconditional customer/photo/month uniqueness applies only where a photo
identity exists. Two legacy shapes are deliberately different:

- **Legacy single-image rows without `payload.photo_id` contribute zero shares.**
  They cannot be deduplicated, so counting each row would violate the monthly
  uniqueness rule. This is an intentional compatibility change: historical
  single rows remain in the audit table but no longer generate payout shares.
- **A legacy count-only full-ZIP row still generates one archive contribution**
  per customer + gallery: the largest stored `photo_count` and the best ZIP tier
  are used, and shares are split by the current ownership of that gallery's
  photos. Because the archive contents are unknown, such a row takes precedence
  over explicit photo identities from the same customer + gallery instead of
  adding to them. Monthly uniqueness is **not** asserted for that legacy shape.

A mixed-gallery ZIP without a gallery and without photo IDs contributes
nothing: it cannot be assigned to any gallery's ownership, so it must not be
counted as if its contents were known. An explicit mixed-gallery payload is
authoritative and participates in the same customer + photo ledger.

**Boundary: at most the first 500 payload values per row are read.**
`explicitPhotoIds()` cuts the payload through `array_slice($values, 0, 500)` —
command, from the repository root: `grep -n 'array_slice($values, 0, 500)' backend/app/Services/PayoutCalculationService.php`
→ `329: foreach (array_slice($values, 0, 500) as $photoId) {`. Deduplication
happens after the cut, so repeated IDs inside those 500 consume slots too. A
`photo_ids` payload with more than 500 entries is therefore **silently
truncated**: the remaining photos keep no identity and earn 0 shares for that
row. The truncation creates no second position and double-pays nothing — the row
still enters the customer/photo ledger exactly once — but the under-count is
silent, and the cut applies per row, not per month. No row ever earns shares for
an identity it did not carry.

### 3.4 Photo-level collectible revenue for surcharge attribution

New checkout snapshots persist an internal
`customer_details._payout_photo_revenue_cents` map from invoice line index to the
photo's collectible revenue after volume pricing and coupon discounting. The
volume strategy supplies effective per-photo prices; percentage `max_items` and
`photo_package` coupons mark the same cheapest eligible items that
`CouponService::applyCoupon()` discounts, and the aggregate discount is then
allocated across exactly those items with cumulative-floor rounding. Fixed and
whole-cart percentage coupons apply across all eligible photo lines. The map is
stripped by `InvoiceSnapshot::customerDetailsForPresentation()` and therefore
never appears in the customer-facing invoice representation.

`calculatePowerUserDelta()` uses this map when present. The order-level Stripe
fee is first apportioned to the photo-backed portion of `total_net`, then split
across those photo revenues; only the remaining net, after that fee, receives
the photographer share. This makes photographer attribution follow actual coupon
coverage: a coupon for one photo does not reduce every photographer's revenue,
and shares and revenue cannot exceed the collectible amount.

### Refunds in the payout

A partial refund changes nothing for the customer — the order keeps its status
and the download access — but it reduces what the photographer is paid. That is
an owner decision (2026-10-25), not an inference from the revenue split.

`charge.refunded` stores the charge's cumulative `amount_refunded` in
`orders.refunded_amount_cents` (clamped to `0 … total_amount`, and monotonic
under a replayed older event). The monthly calculation allocates that amount
proportionally across the order's photo revenues — the same
`allocateCentsProportionally()` used by the discount fallback — and subtracts it
**before** fee proration and the share split. An open month's recalculation
therefore reduces each photographer's share in the same ratio as the revenue
split. The fully refunded `refunded` order status is included in this calculation
basis and contributes zero, rather than disappearing while leaving its already
booked share unreversed. The customer-facing invoice is untouched:
`refunded_amount_cents` is not part of the snapshot, and
`InvoiceSnapshot::customerDetailsForPresentation()` sees no new key.

Under D-58, a refund received after the originating statement is `approved` or
`paid` creates a `payout_corrections` row in the same transaction that advances
the order's cumulative refund. Its negative amount is the difference between the
photographer shares computed at the previously stored refund amount (the share
already booked) and the new cumulative amount, using the same proportional
allocation, fee proration, and 50% split as the normal calculation. Each row
identifies the order, photographer, closed period, and cumulative refund
snapshot. The unique key makes the cumulative snapshot idempotent; a later
increase in the cumulative refund produces only its incremental share reduction.
The same transaction creates a `refund` item in `payout_open_items` with the
correction's source ID, signed amount, and closed origin period. The correction
remains the refund audit record.

`payout_open_items` is the single source for unsettled carry and refund amounts.
Every finalization locks and claims all unsettled items whose origin period is
strictly earlier than the period being finalized. A refund item with no current
statement retains the existing behavior of creating a zeroed statement. An
open carry alone does not manufacture statements in an otherwise idle month; it
remains unsettled until a statement for that photographer is finalized. If the
destination statement is `approved` or `paid`, it is left untouched and
eligible items stay unsettled for a later finalization. Claiming a refund item
also updates the matching correction audit row's settlement fields.

`earned_amount_cents` is pool earnings plus delta surcharge earnings plus the
refund items booked into the statement period. `rolled_over_amount_cents` is
the sum of carry items booked into that period, and `total_payable_cents` is
earned plus carry. Both sums are re-read by `(settled_month, settled_year)` on
recalculation, regardless of `settled_at`, so a removed statement can be
recreated without booking an item twice or moving it to another period.
Approved and paid statements are never touched.

When a statement finalizes as `rollover`, it creates one carry item whose source
is that statement, origin is that period, and signed amount is its full
`total_payable_cents`. A non-zero negative or positive total is carried; a zero
item may be omitted and acts as a boundary, so an older amount cannot reappear.
Claiming a carry settles the previous item before the current rollover creates
its replacement. Missing statement months do not consume an item. The former
latest-prior-statement lookback is removed: statements' stored money columns
remain historical audit values, while the ledger is the only carry source. The
existing payout threshold is unchanged.

The management calculation endpoint enforces chronological finalization: it
returns HTTP 422 and names the later period when a later statement is already
finalized. Any status except a pre-finalization draft counts, including
`rollover`, `pending`, `approved`, and `paid`. Recalculating the same period is
allowed. Both photographer and management statement APIs expose each booked
correction separately with its order reference, negative cents, originating
closed period, and actual settled period; both statement views render those
traceable lines.

Snapshots **without** the map cannot reveal which photos a targeted coupon
covered. "Without" is not a historical-only category: a collective invoice
snapshot is still created today with only `items` in its `customer_details`
(`InvoiceService::generateCollectiveInvoice()` → `'items' => $allItems`,
`InvoiceService.php:140`, no `_payout_photo_revenue_cents` key), and such an
order can be switched to `paid` afterwards (`OrderController::updateStatus()`,
`OrderController.php:63` → `'status' => 'required|string|in:…paid…'`), which is
the state `calculatePowerUserDelta()` reads. Those snapshots use a conservative
fallback: the aggregate itemized
discount is spread proportionally across photo gross prices, capped by both the
snapshot's `total_net` and its recorded discounts. That fallback cannot overpay
the collected amount, but it may attribute revenue to a different photographer
than the original coupon intended; the historical coverage is not presented as
known.
