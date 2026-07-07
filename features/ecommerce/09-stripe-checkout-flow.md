# Stripe Checkout Flow — State Machine & Webhook Architecture

> **Status:** Soll-Zustand.
> Describes the complete checkout lifecycle from cart to order fulfillment via Stripe Payment Intents.
> References: `features/ecommerce/03-custom-quotes-and-stripe.md`, `features/infrastructure/16-srp-volume-pricing.md`, `features/infrastructure/17-pricing-strategy-pattern.md`.

## 1. Order State Machine

Orders track the following states, enforced by `Order::booted()`:

| State | Meaning | Entry Point |
|---|---|---|
| `pending` | Quote request — no payment expected | Checkout with `is_quote_request=true` |
| `invoice_created` | Payment via invoice (B2B) | Checkout with `payment_method=invoice` |
| `delivery_note` | Collective invoice Org — no immediate payment | Checkout with Org `invoice_frequency !== immediate` |
| `pending_payment` | Stripe PaymentIntent created, awaiting confirmation | Checkout via Stripe |
| `paid` | Payment confirmed | Webhook `payment_intent.succeeded` |
| `overdue` | Invoice payment overdue | Manual admin action |
| `cancelled` | Order cancelled by admin (also set when a quote is superseded) | Admin `updateStatus` or `sendQuote` |
| `disputed` | Chargeback initiated | Webhook `charge.dispute.created` |
| `refunded` | Full refund processed | Webhook `charge.refunded` |
| `archived_in_collective` | Consolidated into a collective invoice | Org collective invoice generation |

### 1.1 State Transitions

```
cart → [payment_method=stripe]  → pending_payment → paid
     → [payment_method=invoice] → invoice_created  → paid (manual)
     → [is_quote_request]       → pending          → cancelled (when admin sends quote link)
     → [delivery_note Org]   → delivery_note    → archived_in_collective

paid → disputed → refunded
paid → refunded (direct)
```

## 2. Complete Checkout Lifecycle

### 2.1 Cart → Checkout

1. Client builds a cart (photos + license selections or volume items).
2. POST `/api/orders/checkout` (authenticated) triggers `OrderController::checkout()`.
3. **Pre-flight checks:**
   - Bank details (holder, IBAN, street) must be configured — otherwise 400.
   - `purchase-upgrades` gate and `purchase-on-invoice` gate are enforced.
   - Digital goods require `withdrawal_waived` flag (except quote-only carts).
4. `CheckoutService::processCheckout()` is called with the validated request, user, and payment method.

### 2.2 Checkout Service

The service runs inside a `DB::transaction`:

1. **Item validation:** Each item is looked up (`Photo::with('gallery')`). If the gallery is non-public, `canAccessGallery()` is checked (403 on failure). For RP (scope licensing), `LicenseUseCase::find()` validates use-case existence and brand consistency (defense-in-depth).
2. **Pricing:** `PricingStrategy::calculateCart()` is called once with all items. The strategy is brand-injected via `AppServiceProvider`:
   - **RP (ScopeLicensingStrategy):** Item-by-item pricing via `LicenseUseCase` + `LicenseModifier` surcharges.
   - **SRP (VolumeLicensingStrategy):** Retroactive volume tier (`srp_tier_threshold1`/`srp_tier_threshold2`) with configurable per-tier prices.
3. **Order creation:** Order is created with calculated `total_amount` and appropriate status. Coupon adjustments (`coupon_id`, `coupon_discount_cents`) are persisted when applicable.
4. **Invoice snapshot:** An `InvoiceSnapshot` record freezes all customer details, line items, price breakdown, and the invoice number (generated via `InvoiceSequence::getNextInvoiceNumber` with `P-` or `L-` prefix).
5. **Stripe PaymentIntent:** For Stripe payments, a `PaymentIntent` is created with `amount` (in cents), `currency=eur`, `metadata.order_id`, and `receipt_email`. **Idempotency key** `pi_{orderId}` ensures safe retries.
6. **Response:** Returns `client_secret`, `order_id`, and `invoice_number` to the frontend.

### 2.3 Frontend Confirmation

The frontend uses `@stripe/react-stripe-js` to confirm the payment with `stripe.confirmCardPayment(client_secret)`. The `client_secret` is used directly with Stripe.js — the secret key never leaves the server.

### 2.4 Payment → Webhook → Order

1. Stripe sends a `payment_intent.succeeded` event to `POST /api/webhooks/stripe`.
2. `WebhookController::handleStripe()` processes the event (see §3).
3. Order status transitions to `paid`. Stripe fee is extracted from `latest_charge.balance_transaction.fee`.
4. Invoice email is queued via `InvoiceMail`.

### 2.5 RP vs SRP Differences

| Aspect | RP (Scope Licensing) | SRP (Volume Licensing) |
|---|---|---|
| Pricing strategy | `ScopeLicensingStrategy` | `VolumeLicensingStrategy` |
| Per-item price | Based on `LicenseUseCase` + modifiers | Retroactive volume tier |
| Catalog | Full license use-case/modifier catalog | No catalog — pure quantity-based |
| `guardBrand()` | Active (defense-in-depth) | Inactive (no brand mismatch possible) |
| Coupons | Not applicable | Supported via `CouponService` |

## 3. Webhook Event Handling

The endpoint `POST /api/webhooks/stripe` accepts raw JSON payloads. Signature verification is mandatory.

### 3.1 Signature Verification

1. Reads `Stripe-Signature` header and payload.
2. Supports **comma-separated multiple secrets** (for multi-domain setup where different brands use different Stripe accounts).
3. Each secret is tried in order via `Stripe\Webhook::constructEvent()`. The first valid match breaks the loop.
4. Local development fallback: if no `services.stripe.webhook_secret` is configured, reads `storage/app/private/stripe_secret.txt` (auto-populated by Stripe CLI auto-tunneler).
5. Returns 400 on signature mismatch or invalid payload.

### 3.2 Event Routing

| Stripe Event | Action |
|---|---|
| `payment_intent.succeeded` | Find order by `metadata.order_id` → update `status=paid`, `stripe_fee_cents` (via `paymentIntents.retrieve` with expanded `latest_charge.balance_transaction`). Queue `InvoiceMail`. |
| `charge.dispute.created` | Find order by `stripe_payment_intent_id` → update `status=disputed`. Email `ACCOUNTING_EMAIL` with dispute notification. |
| `charge.refunded` | Find order by `stripe_payment_intent_id` → update `status=refunded`. |

### 3.3 PaymentIntent → Order Linking

- PaymentIntent `metadata.order_id` maps to the order UUID.
- The PaymentIntent ID is stored as `orders.stripe_payment_intent_id` for dispute/refund lookups.
- This dual mapping (order_id in metadata + PI ID on the order) ensures robust linking even if metadata is lost.

### 3.4 Fee Tracking

The actual Stripe fee is retrieved live from the API after a successful payment:

```php
$intent = $stripe->paymentIntents->retrieve($paymentIntent->id, [
    'expand' => ['latest_charge.balance_transaction']
]);
$feeCents = $intent->latest_charge->balance_transaction->fee ?? 0;
```

If `fee` is 0, a warning is logged. The fee is stored in `orders.stripe_fee_cents` for reconciliation.

## 4. Idempotency Handling

| Layer | Mechanism |
|---|---|
| Stripe API (PaymentIntent creation) | Idempotency key `pi_{orderId}` prevents duplicate PaymentIntents |
| Webhook processing | Order status check: webhook only updates if `$order->status !== 'paid'`. Idempotent — re-delivered events are no-ops |
| Database | Transactional: order creation, snapshot creation, and all state changes are wrapped in `DB::transaction` |

## 5. Error Recovery Scenarios

| Scenario | Behavior |
|---|---|
| PaymentIntent creation fails (network error) | Transaction is rolled back. Client receives error and can retry. Idempotency key prevents duplicate charges. |
| Card declined during frontend confirmation | Stripe.js returns error to frontend. No webhook fires. Order remains `pending_payment`. Client can retry with different card. |
| Webhook delivery delayed | Client sees "pending" status. Next page load checks order status. Webhook eventually arrives and updates. |
| Webhook not delivered (Stripe outage) | Manual reconciliation: admin can verify payment in Stripe Dashboard and set `paid` status via management API. |
| Duplicate webhook delivery | Order status check (`!== 'paid'`) ensures webhook is processed exactly once. Fee retrieval is repeated but idempotent. |
| Dispute/chargeback | Order set to `disputed`. Downloads blocked (access gates check order status). Admin notified via email. |
| Signature verification failure | 400 returned. Stripe retries with exponential backoff. All configured secrets are tried; if none match, logs error with secret count. |

## 6. Non-Stripe Payment Paths

- **Quote requests** (`is_quote_request=true`): Status `pending`. Photographer sets custom price in backend, generates a JWT-based quote link (see `features/infrastructure/18-jwt-offer-tokens.md`). Client opens link, cart is restored, checkout proceeds with the locked price.
- **Invoice (B2B):** Status `invoice_created`. PDF invoice sent immediately. Payment collected offline (bank transfer). Admin marks as `paid` manually.
- **Delivery notes (collective invoice Org):** Status `delivery_note`. Orders are consolidated into a monthly collective invoice. Individual orders are marked `archived_in_collective`.
