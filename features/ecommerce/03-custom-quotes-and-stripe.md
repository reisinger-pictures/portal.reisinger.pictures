---
domain: ecommerce
topic: custom-quotes-and-stripe
status: planned
---

# Technical Concept: Custom Quotes & Stripe Integration

## 1. The Two-Way Quote Workflow
Custom Quotes allow dynamic pricing for specialized B2B requirements. This operates in two modes:

### A. Reactive Quote (Client-Initiated)
- The client adds items to the cart and selects "Individuelles Angebot anfragen".
- **UI Behavior:** The cart and checkout total explicitly display `--- € (Preis auf Anfrage)` instead of `0,00 €`.
- **Order Creation:** An order is created with `is_quote_request = true` and status `pending`.
- **Fulfillment:** The photographer edits the order in the backend, sets a custom price, and sends a payment link to the client.

### B. Proactive Quote (Photographer-Initiated via Link)
- The photographer negotiates a package price off-platform.
- **Token Generation:** The backend generates a cryptographically signed URL (JWT or HMAC) containing specific `photo_ids` and a `custom_price`.
- **Cart Restoration:** When the client clicks the link (`/cart?quote_token=...`), the frontend decodes the token, clears the local cart, populates it with the specified items, and locks the price.
- **Security:** The backend MUST verify the signature of the `quote_token` during checkout to prevent tampering.

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
