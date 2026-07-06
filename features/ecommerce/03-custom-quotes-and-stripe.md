---
domain: ecommerce
topic: custom-quotes-and-stripe
status: planned
---

# Technical Concept: Custom Quotes & Stripe Integration

> **Decision Log — 2026-07-06 (Architekt-Klärung, final):** A Proactive Quote (Quote-Link) is a
> **payable fixed-price offer** that runs through the **standard cart + Stripe checkout flow** —
> NOT a mere request. The photographer creates the offer including **custom conditions**; the
> customer receives it by email, adds it to the cart at the locked price, and pays via the normal
> checkout. The custom conditions are an integral part of the offer and MUST be embedded in the
> delivered assets (image EXIF/XMP + license PDF). See reconfirmed Section 1.B below.

## 1. The Two-Way Quote Workflow
Custom Quotes allow dynamic pricing for specialized B2B requirements. This operates in two modes:

### A. Reactive Quote (Client-Initiated)
- The client adds items to the cart and selects "Individuelles Angebot anfragen".
- **UI Behavior:** The cart and checkout total explicitly display `--- € (Preis auf Anfrage)` instead of `0,00 €`.
- **Order Creation:** An order is created with `is_quote_request = true` and status `pending`.
- **Fulfillment:** The photographer edits the order in the backend, sets a custom price, and sends a payment link to the client.

### B. Proactive Quote (Photographer-Initiated via Link) — RECONFIRMED 2026-07-06

A Proactive Quote is a **payable fixed-price offer** assembled by the photographer (photos +
locked price + custom conditions) and sent to the client by email. The client adds it to the cart
and pays through the **standard cart checkout** (Stripe). The backend MUST honor the locked price
— the standard pricing strategies are bypassed for these items.

- **Offer Creation (Photographer):** The photographer defines:
  - `photos` — the negotiated photo IDs
  - `price` — the **locked, payable** package price (charged as-is at checkout)
  - `rights` / **custom conditions** — the negotiated usage terms (territory, duration, exclusivity,
    editorial/commercial, free-text clauses). These are an integral part of the offer.
- **Token Generation:** The backend issues a signed JWT (`QuoteLinkService` → `OfferTokenService`,
  see `infrastructure/18-jwt-offer-tokens.md`) carrying `photos`, `price`, and `rights`.
- **Cart Restoration:** When the client opens the link (`/cart?quote_token=...`), the frontend
  decodes the token, clears the local cart, and adds the photos as **fixed-price items** that flow
  through the standard checkout. The cart displays the locked offer price.
- **Checkout (standard flow):** The client pays via the normal Stripe cart checkout. The backend
  MUST charge the **locked offer price**, NOT the standard volume/use-case price. Concretely the
  pricing path for quote-link items is a **custom-price passthrough** — `CheckoutService` /
  strategies accept the token price instead of recomputing.
- **Fulfillment (Custom Conditions):** The negotiated rights MUST be embedded in delivery:
  - Image delivery (`DownloadController` / `FileDeliveryController`): EXIF/XMP rights fields
    (`UsageTerms`, `Rights`, `SpecialInstructions`) reflect the custom conditions, not the standard AGB.
  - License PDF (`ManualInvoiceService` / invoice snapshot `customer_details`): the custom
    conditions text replaces/augments the standard license block.
- **Security:** `OfferTokenService::verify()` validates signature + `exp` on decode. Because the
  payable amount originates from the signed token (not from client-supplied cart input), price
  tampering is impossible as long as the backend reads the price from the verified token, not
  from the request body.

> **IST-Divergence (Bug F-01, AGENTS.todo.md 2026-07-06):** The current implementation does NOT
> honor the locked price. `ClientCartView.tsx:86-96` decodes the token and splits `custom_price`
> across the photos as client-side `item.price`, but the backend pricing strategies
> (`VolumeLicensingStrategy` / `ScopeLicensingStrategy`) **ignore the request price and recompute**:
>   - **SRP:** customer is charged the standard volume price (`tier1Price × count`), not the offer.
>   - **RP:** items have no `useCaseId` → `ScopeLicensingStrategy::calculateSingleItem` calls
>     `LicenseUseCase::findOrFail('')` → **HTTP 500**.
> SOLL target: a custom-price passthrough that charges the signed token price and records the
> custom conditions on the order/invoice snapshot for rights-embedded delivery.



## 2. Stripe Payment Architecture (One-Off Payments)
The system uses the modern **Payment Intents API** for secure, one-off transactions (Fallback for B2B credit cards).
- **Backend (Laravel):** Uses the `stripe/stripe-php` SDK. It calculates the final price, creates a PaymentIntent with Stripe, and returns the `client_secret` to the frontend. The secret key (`sk_test_...`) never leaves the server.
- **Frontend (React):** Uses `@stripe/react-stripe-js` and `@stripe/stripe-js`. It securely collects card details via Stripe Elements and confirms the payment directly with Stripe using the public key (`pk_test_...`).

## 3. Local Testing & Webhooks
- **Stripe CLI:** For local development, the Stripe CLI is required to forward events: `stripe listen --forward-to localhost:8000/api/webhooks/stripe`.
- **Webhook Security:** The backend MUST verify incoming webhook signatures using the endpoint secret (`whsec_...`) provided by the Stripe CLI.

## 4. E2E Testing Guidelines (Credit Cards)
Playwright tests MUST cover these specific Stripe test cards (future expiry, any 3-digit CVC):
- **Positive Flows:**
  - Standard Success (Visa): `4242 4242 4242 4242`
  - Alternative (Mastercard): `5555 5555 5555 4444`
- **Negative Flows (Error Handling):**
  - Generic Decline: `4000 0000 0000 0002`
  - Insufficient Funds: `4000 0000 0000 0004`
  - Invalid CVC: `4000 0000 0000 0127`
  - Expired Card: Use the positive Visa card but input a past expiry date (e.g., `01/22`).

## 5. B2B & Accounting Rules (Austria)
- **Fee Calculation:** The business model accounts for B2B corporate card fees (approx. 1.9% + 0.25 € per transaction).
- **Invoice Generation:** PDF invoices are ONLY generated and emailed *after* a successful Stripe webhook event (`payment_intent.succeeded`).
- **Invoice Status:** Paid invoices explicitly state "Bezahlt via Kreditkarte".
- **Tax Law (Kleinunternehmer):** All amounts are strictly Net (e.g., 45.00 €). The invoice MUST include the legal notice: *"Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung"*.
