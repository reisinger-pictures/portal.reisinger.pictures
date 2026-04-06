---
domain: ecommerce
topic: licensing-and-cart
status: active
---

# Technical Concept: Licensing, Pricing & Cart

## 1. Dynamic Pricing Matrix & UI Dropdowns
- The `LicenseSelectorModal` MUST present 3 distinct dropdowns to the user, acting as multipliers against the base price:
  - **Dropdown 1: Usage Type (Rechtlicher Rahmen):** Editorial (x1.0) vs. Commercial (x3.0).
  - **Dropdown 2: Resolution (Verwendungszweck):** Web/Social (x1.0) | Print (x2.0) | Original (x4.0).
  - **Dropdown 3: Duration (Nutzungsdauer):** 1 Year (x1.0) | Unlimited (x2.0).
- **Price Calculation:** Displayed dynamically in real-time before adding to the cart.
- **Delta-Pricing Base:** A user's `flatrate_level` always acts as an "Editorial, 1 Year" base. If they want Commercial or Unlimited, the delta price applies regardless of resolution.

## 2. Entitlements & Delta-Pricing (Upgrades)
- **Instant Download (Bypass):** If a user has an active flat-rate (e.g., `flatrate_level = 'print'`) that covers the requested resolution, the checkout is completely bypassed. The UI displays an instant "Download" button.
- **Delta-Pricing:** If a user requests a higher resolution than included, the UI transparently displays the price difference (Upgrade-Fee). Only the delta is placed into the shopping cart.

## 3. Cart & Checkout Form (Phase 1: AT MVP)
- The checkout restricts purchases to domestic customers (Austria) to bypass complex EU Reverse-Charge and live UID validation in the MVP phase.
- **Mandatory Checkout Fields:** First Name, Last Name, Billing Address (Street, ZIP, City), Email.
- **Optional Fields:** Company Name, UID-Number (for Austrian B2B).
- **Legal Checkboxes:** 1. Agreement to Terms & Conditions (AGB) and License Terms.
  2. Explicit waiver of the right of withdrawal due to immediate digital download.

## 4. Data Immutability
- Upon purchase, all invoice-relevant data (price, address, user details, line items) are frozen in an append-only snapshot table (`invoice_snapshots`). Hard deletes of users must not affect the integrity of historical orders.
