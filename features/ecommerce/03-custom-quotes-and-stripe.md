---
domain: ecommerce
topic: custom-quotes-and-stripe
status: planned
---

# Technical Concept: Custom Quotes & Online Payment

> **Provider status (2026-11-17):** The quotes workflow below is current; the
> *provider* it names is not. New online payments run through Mollie's hosted
> checkout and the API accepts `mollie` and `invoice` only — contract and cutover
> in [`features/infrastructure/30-mollie-payments.md`](../infrastructure/30-mollie-payments.md).
> Section 2 and the test-card list are **retired history**: they describe the
> removed in-page card flow, which no new order entered or enters. Stripe is gone
> entirely (no account, no credential, no SDK, no webhook route), so nothing on
> this page is an instruction — the current payment contract is the Mollie
> document.

> **Decision Log — 2026-07-06 (Architekt-Klärung, final):** A Proactive Quote (Quote-Link) is a
> **payable fixed-price offer** that runs through the **standard cart checkout flow** (online payment or
> invoice) — NOT a mere request. The photographer creates the offer including **custom conditions**;
> the customer receives it by email, adds it to the cart at the locked price, and pays via the normal
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
and pays through the **standard cart checkout** (online payment or invoice). The backend MUST honor the
locked price — the standard pricing strategies are bypassed for these items.

- **Offer Creation (Photographer):** The photographer defines:
  - `photos` — the negotiated photo IDs
  - `price` — the **locked, payable** package price, in **integer cents** (charged as-is at checkout). The wire type is enforced, not assumed: `OfferTokenService::issue()` takes `int $price` (`backend/app/Services/OfferTokenService.php:78`) and `verify()` rejects anything that is not an `int` or is `< 1` (`:122`). Unit rule: [`../tech/02-backend-architecture.md` § 4](../tech/02-backend-architecture.md).
  - `rights_text` / **custom conditions** — the negotiated usage terms (territory, duration,
    exclusivity, editorial/commercial, free-text clauses). These are an integral part of the offer.
- **Token Generation:** The backend issues a signed JWT (`QuoteLinkService` → `OfferTokenService`,
  see `infrastructure/18-jwt-offer-tokens.md`) carrying `photos`, `price`, and optional
  `rights_text`.
- **Cart Restoration:** When the client opens the link (`/cart?quote_token=...`), the frontend
  requests the backend to resolve and validate the token, clears the local cart, and adds the
  photos as **fixed-price display items** that flow through the standard checkout. The displayed
  per-photo split is not payment authority.
- **Checkout (standard flow):** The client pays via the normal invoice or hosted online checkout.
  `CheckoutService` verifies the token again, requires a positive token `price`, and uses that
  signed value as the server-authoritative total. The standard volume/use-case strategies are
  bypassed, client-supplied item prices are ignored, and the offer line items plus `rights_text`
  are frozen in the invoice snapshot.
- **Fulfillment (Custom Conditions):** The negotiated rights MUST be embedded in delivery:
  - Image delivery (`DownloadController` / `FileDeliveryController`): EXIF/XMP rights fields
    (`UsageTerms`, `Rights`, `SpecialInstructions`) reflect the custom conditions, not the standard AGB.
  - License PDF (`ManualInvoiceService` / invoice snapshot `customer_details`): the custom
    conditions text replaces/augments the standard license block.
- **Security:** `OfferTokenService::verify()` validates the signature and `exp`. The payable amount
  comes from the verified token, never from the checkout request or the cart's display price.

> **Implemented:** `CheckoutService` resolves the signed quote token before pricing, rejects
> non-positive offer prices, bypasses the normal pricing strategies, and records the negotiated
> conditions in the order invoice snapshot. The former Bug F-01 divergence is resolved.



## 2. Payment Architecture (One-Off Payments)

**Retired (history, not the desired state).** This section described the Stripe
Payment Intents flow: the `stripe/stripe-php` SDK, an actionable PaymentIntent
client secret, a Stripe webhook endpoint secret (`whsec_…`) and a
generation-aware Stripe idempotency key. Stripe is removed completely — no
account, no credential, no SDK, no webhook route. The current payment contract is
Mollie's hosted checkout: one provider payment attempt per order and generation,
an immutable amount/currency snapshot written before the provider call, and a
verified `paid` status from the webhook or the reconciliation command
([Mollie payments](../infrastructure/30-mollie-payments.md), D-63).

## 3. Local Testing & Webhooks

A portal-side payment CLI is not part of the setup. Mollie needs no local
listener: the webhook target is `MOLLIE_WEBHOOK_URL`, which is mandatory in every
environment and must be a reachable HTTPS URL — locally that means a public
HTTPS tunnel, and without one the provider-dependent E2E specs skip by name.

**Retired:** the Stripe CLI (`stripe listen --forward-to
localhost:8000/api/webhooks/stripe`) and the `whsec_…` signature check belonged to
the removed Stripe webhook; the tunnel artifact `storage/app/private/stripe_secret.txt`
no longer exists.

## 4. E2E Testing Guidelines (Credit Cards) — historical
Historical, and no longer a rule. It described the Stripe Elements form that [#58](https://github.com/reisinger-pictures/portal.reisinger.pictures/issues/58) removed: the provider's hosted page now collects the card number, so a portal-side card fixture has nothing to type into and no spec uses these numbers. Mollie documents its own test cards for the hosted page. The list is kept only as a record of what the removed Stripe specs pinned:
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
- **Invoice Generation and Email:** Immediate online-payment invoices are queued after a verified `paid` (webhook or reconciliation). Invoice and delivery-note checkouts queue the invoice/confirmation immediately after checkout; a valid discount that settles the cart at exactly `0` also queues it immediately without creating a provider payment.
- **Invoice Status:** Only a provider-confirmed online payment may state "Bezahlt via Kreditkarte"; invoice and settled-free orders must not be described as paid by card.
- **Tax Law (Kleinunternehmer):** All amounts are strictly Net (e.g., 45.00 €). The invoice MUST include the legal notice: *"Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung"*.
