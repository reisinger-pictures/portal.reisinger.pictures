---
domain: ecommerce
topic: licensing-and-cart
status: planned
---

# Technical Concept: Licensing, Pricing & Cart

## 1. Dynamic Pricing Matrix
- Prices are calculated dynamically based on a base price multiplied by specific factors:
  - **Usage Type:** Editorial (x1.0) vs. Commercial (x3.0).
  - **Resolution/Purpose:** Web/Social up to 2560px (x1.0) | Print up to A4 4000px (x2.0) | Original/Cover (x4.0).
  - **Duration:** 1 Year (x1.0) | Unlimited (x2.0).
- **Edge Case handling:** If the original Master File has been purged by the Storage Lifecycle, the "Original" resolution tier MUST be disabled in the UI.

## 2. Hierarchical Governance
- Licensing restrictions (e.g., `is_editorial_only`) and visibility (`is_hidden`) are inherited strictly top-down: `Folder -> Gallery -> Photo`.
- **Overrides:** A specific photo can override the inherited state (Force True / Force False). The UI must calculate and display the effective inherited state in real-time.

## 3. Entitlements & Delta-Pricing (Upgrades)
- **Instant Download (Bypass):** If a user has an active flat-rate (entitlement) that covers the requested resolution, the checkout is completely bypassed. The UI displays an instant "Download" button.
- **Delta-Pricing:** If a user requests a higher resolution than included in their flat-rate, the UI must transparently display the price difference (Upgrade-Fee). Only the delta is placed into the shopping cart.

## 4. Cart & Checkout
- The cart supports mixed license purchases in a single session.
- **Data Immutability:** Upon purchase, all invoice-relevant data (price, user details) are frozen in an append-only snapshot table (`invoice_snapshots`). Hard deletes of users must not affect the integrity of historical orders.
