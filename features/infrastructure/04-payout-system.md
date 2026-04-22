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
