# Stripe Checkout Flow — State Machine & Webhook Architecture

> **Status:** Current SOLL (reviewed 2026-09-24). Legacy `SRP` wording below is
> retained only where it names an old compatibility key; the live pricing modes
> are scope and volume licensing.
> Describes the complete checkout lifecycle from cart to order fulfillment via Stripe Payment Intents.
> References: `features/ecommerce/03-custom-quotes-and-stripe.md`, `features/security/card-testing-protection.md`, `features/infrastructure/16-srp-volume-pricing.md`, `features/infrastructure/17-pricing-strategy-pattern.md`.

## 1. Order State Machine

Orders track the following states, enforced by `Order::booted()`:

| State | Meaning | Entry Point |
|---|---|---|
| `pending` | Reactive quote request — no payment expected | Checkout with `is_quote_request=true` |
| `invoice_created` | Payment via invoice (B2B) | Checkout with `payment_method=invoice` |
| `delivery_note` | Collective invoice Org — no immediate payment | Checkout with Org `invoice_frequency !== immediate` |
| `pending_payment` | Payable Stripe PaymentIntent created, awaiting confirmation | Positive-value immediate Stripe checkout, including a payable quote link |
| `paid` | Stripe payment confirmed or non-Stripe order settled | Strict Stripe reconciliation, manual settlement, or settled-free checkout |
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
     → [valid discount to 0] → paid (settled free, immediate mail)

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
   - Transient guest JWTs remain valid for invite/gallery access but are **not admitted to checkout** in this release. The checkout endpoint fails closed with `403` and `guest_checkout_unsupported`; no shadow `users` row, Stripe Customer, or ownerless order is created. Guest-owned order access is reserved for rows that carry a signed `orders.guest_id`; legacy rows with both owner fields null remain inaccessible to customer actors.
4. `CheckoutService::processCheckout()` is called with the validated request, user, and payment method.

### 2.2 Checkout Service

Order and invoice-snapshot creation run inside a `DB::transaction`. Every new
authenticated checkout persists the server-validated checkout key/fingerprint;
the positive-value immediate Stripe path additionally uses per-user checkout
identity locks and a final row lock before the external Stripe call. The
identity namespace is shared by non-immediate invoice, delivery-note,
settled-free, and reactive-quote orders, while the PI/card-testing gates remain
limited to positive-value immediate Stripe:

1. **Item validation:** Each item is looked up (`Photo::with('gallery')`). If the gallery is non-public, `canAccessGallery()` is checked (403 on failure). For scope licensing, `LicenseUseCase::find()` validates use-case existence and brand consistency (defense-in-depth).
2. **Pricing:** If `quote_token` is present, `CheckoutService` verifies its signature and expiry, requires a positive token price, and uses that price as the server-authoritative total; client item prices are ignored and the standard pricing strategies are bypassed. Otherwise `PricingStrategy::calculateCart()` is called on the server-authoritative groups:
   - **Scope licensing (`ScopeLicensingStrategy`):** item-by-item pricing via `LicenseUseCase` + `LicenseModifier` surcharges.
   - **Volume licensing (`VolumeLicensingStrategy`):** retroactive tiers from the effective `VolumePreset`; gallery/preset overrides are grouped separately for mixed carts.
3. **Order creation:** Order is created with the server-calculated `total_amount` and appropriate status. Coupon adjustments (`coupon_id`, `coupon_discount_cents`) are persisted when applicable.
4. **Invoice snapshot:** An `InvoiceSnapshot` record freezes all customer details, line items, price breakdown, and the invoice number (generated via `InvoiceSequence::getNextInvoiceNumber` with `P-` or `L-` prefix). A quote offer's `rights_text` is stored as `customer_details.custom_conditions`.
5. **Stripe PaymentIntent:** For a positive-value immediate Stripe checkout that needs a new or replacement generation, a `PaymentIntent` is created with the authoritative `amount` (in cents), `currency=eur`, mapped Stripe Customer, `receipt_email`, and server-owned order, user, checkout key, fingerprint, and generation metadata. The generation-aware Stripe **idempotency key** is `pi_{orderId}_{payment_intent_generation}`.
6. **Response:** A new or reusable actionable PaymentIntent returns `client_secret`, `order_id`, and `invoice_number`. A paid replay returns the settled result. If a retrieved PI is remotely succeeded before strict success reconciliation, checkout returns `payment_pending` with a poll URL and no client secret; it does not grant paid access itself.
7. **Pending recovery:** A matching user/key/fingerprint retry reuses the existing `pending_payment` order and actionable PI without creating another PI. If an ambiguous create left the local PI link null, retry uses the same generation and deterministic Stripe key. A terminal stale PI is replaced only after identity validation and a generation increment. Positive/PI-backed replay additionally requires an explicit order brand equal to the active host brand; a null-brand claim is never a legacy shortcut.

### 2.3 Frontend Confirmation

For an actionable response, the frontend uses `@stripe/react-stripe-js` to confirm the payment with `stripe.confirmCardPayment(client_secret)`. The `client_secret` is used directly with Stripe.js — the secret key never leaves the server. A `payment_pending` recovery response has no client secret; the frontend preserves the checkout recovery session and polls the order while strict success reconciliation is pending.

### 2.4 Payment → Webhook → Order

1. Stripe sends a `payment_intent.succeeded` event to `POST /api/webhooks/stripe`.
2. `WebhookController::handleStripe()` verifies the event and delegates to the strict identity checks in §3.
3. Only after those checks pass does a locked, conditional `pending_payment → paid` transition grant access. The Stripe fee is enriched from `latest_charge.balance_transaction.fee` when available.
4. `InvoiceMail` is durably enqueued at most once after the paid transition; SMTP delivery remains subject to queue-worker retries.

### 2.5 Scope vs Volume Licensing Differences

The old RP/SRP labels are historical. The current strategies are selected per
brand and may be overridden per gallery:

| Aspect | Scope licensing | Volume licensing |
|---|---|---|
| Pricing strategy | `ScopeLicensingStrategy` | `VolumeLicensingStrategy` |
| Per-item price | Based on `LicenseUseCase` + modifiers | Retroactive tier from `VolumePreset` |
| Catalog | License use-case/modifier catalog | No license catalog; quantity/preset based |
| `guardBrand()` | Active (defense-in-depth) | Gallery/brand preset resolution is scoped |
| Coupons | Not applied by the strategy | Supported via `CouponService` |
| Gallery override | `licensing_mode`/`volume_preset_id` may select the mode/preset | Same |

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
| `payment_intent.succeeded` | Use `metadata.order_id` only to locate a candidate. For V036 orders, require the event PI ID, complete server-owned metadata (checkout key, fingerprint, generation, user/account evidence, amount/currency), received amount, and mapped Customer to match, then conditionally transition `pending_payment → paid`, enrich `stripe_fee_cents` when available, and durably enqueue `InvoiceMail` at most once. A signed event may bind a still-null local PI ID only in the same validated transition. Clearly pre-V036 orders retain only the explicit legacy compatibility defined by the security feature. |
| `payment_intent.payment_failed` | Require the stored PI ID and current generation plus the applicable V036 identity to match; deduplicate by event ID and record only bounded failure telemetry. The order remains recoverable and no fulfillment is granted. |
| `charge.dispute.created` | Find order by `stripe_payment_intent_id` → update `status=disputed`. Email `ACCOUNTING_EMAIL` with dispute notification. |
| `charge.refunded` | Find order by `stripe_payment_intent_id` → update `status=refunded` only for a full refund. |

### 3.3 PaymentIntent → Order Linking

- `metadata.order_id` locates the candidate order; it is never sufficient by itself to mark it paid.
- For a V036 order, the event PI ID must equal `orders.stripe_payment_intent_id`; its checkout key, fingerprint, generation, user/account evidence, amount/currency, received amount, and Customer mapping must all match. Clearly pre-V036 orders may omit only the fields allowed by the security feature's explicit legacy compatibility rules, while conflicting supplied identity is always rejected.
- For a signed success event whose matching V036 order still has no local PI ID, all checks must pass before the PI ID is bound under the same row lock and conditional `pending_payment → paid` transition.
- The stored PI ID remains the linkage for dispute/refund handling and strict reconciliation.

### 3.4 Fee Tracking

The actual Stripe fee is retrieved separately from the API after identity validation:

```php
$intent = $stripe->paymentIntents->retrieve($paymentIntent->id, [
    'expand' => ['latest_charge.balance_transaction']
]);
$feeCents = $intent->latest_charge?->balance_transaction?->fee;
```

If fee expansion is unavailable, the verified success still transitions the order to `paid` and stores `stripe_fee_cents = null`; payout uses its explicit fallback. A genuine fee of `0` remains an explicit persisted value and is logged distinctly.

## 4. Idempotency Handling

| Layer | Mechanism |
|---|---|
| Checkout identity | The browser `Idempotency-Key` and a server-canonical fingerprint identify every new authenticated checkout. A same-key/different-fingerprint request receives `409`. Immediate Stripe can additionally recover a lost key only through the authorized same-user/active-brand fingerprint path; non-immediate paths are exact-key only. |
| Stripe API (PaymentIntent creation) | Generation-aware key `pi_{orderId}_{payment_intent_generation}` prevents duplicate PIs within a generation. A terminal replacement increments the generation; a same-generation ambiguous create retries the same key. |
| Pending recovery | A matching immediate retry retrieves and revalidates the persisted PI. Reusable pending PIs are returned with their existing client secret; remotely succeeded PIs return the non-destructive `payment_pending` recovery response until strict success reconciliation grants paid. |
| Webhook processing | Stripe event IDs are deduplicated; strict identity checks and a locked conditional `pending_payment → paid` update make matching retries idempotent. Invoice mail uses the durable `InvoiceSnapshot::MAIL_DISPATCH_KEY` claim, written in the same database transaction as the queue insert; it provides at-most-once durable enqueue, not exactly-once SMTP delivery. |
| Database | Order/snapshot creation and state transitions use `DB::transaction` plus row/identity locks where required; external Stripe calls are not held inside a database transaction. |

### 4.1 Invoice-mail claim

`InvoiceMailDispatcher` locks the existing invoice snapshot and writes the reserved
`InvoiceSnapshot::MAIL_DISPATCH_KEY` metadata before enqueueing the mailable. With the
production database queue (the dispatcher fails closed if production is configured
with a non-transactional queue), that marker update and queue insert commit or roll back as
one unit. A queue-insert failure leaves no claim and is retryable by a later
checkout/webhook request. Once the claim commits, this contract guarantees
**at most one durable enqueue**, not exactly-once SMTP delivery: a worker may
retry SMTP after a transport error, and a permanently failed job remains in
`failed_jobs` while the marker prevents an automatic second enqueue. Recovery
of a terminal mail failure is an explicit operator decision and must account
for the ambiguity that SMTP may have accepted a message before its response was
lost. The marker is operational metadata only and is omitted from the public
snapshot resource. No V039+ migration is required; the existing JSON column and
transactional queue primitive are sufficient.

### 4.2 Non-immediate identity and lost browser keys

Invoice, delivery-note, settled-free, and reactive-quote checkouts use the
same persisted key/fingerprint fields as immediate Stripe, but never use those
fields to authorize a PaymentIntent. Exact-key retries revalidate the owner,
active brand, amount, and canonical fingerprint and may resume an unfinished
order. A lost browser key is intentionally not recovered by fingerprint: a
new key is treated as a new order because the server cannot safely distinguish
a retry from a deliberate second purchase of the same cart. The frontend and
backend regressions cover this accepted boundary; no replay claim is made for
that key-loss case.

## 5. Error Recovery Scenarios

| Scenario | Behavior |
|---|---|
| PaymentIntent creation fails before Stripe accepts a request, or the response is ambiguous | A known pending order remains recoverable. If no PI was persisted, retry uses the same generation and deterministic Stripe key; if Stripe accepted it, the same key recovers the same PI. A missing/ambiguous client secret never becomes actionable. |
| Card declined during frontend confirmation | Stripe.js returns an error and may emit `payment_intent.payment_failed`. The verified event records bounded telemetry only; the order remains `pending_payment`, and normal retries stay within the current PI/checkout generation. |
| Webhook delivery delayed | A checkout replay that sees a remotely succeeded PI returns `payment_pending` with a poll URL and no client secret. It cannot grant paid access; the signed webhook normally performs the strict paid transition. |
| Webhook not delivered | The signed webhook remains the normal authority. Scheduled stale-PI cleanup may reconcile a remotely succeeded PI only by its stored ID and only after the same strict identity, amount, received-amount, Customer, and generation checks. |
| Duplicate webhook delivery | Stripe event deduplication, the conditional paid transition, and the durable mail-enqueue claim prevent repeated state changes or a second enqueue. SMTP delivery retries remain queue-worker behavior. |
| Dispute/chargeback | Order set to `disputed`. Downloads blocked (access gates check order status). Admin notified via email. |
| Signature verification failure | 400 returned. Stripe retries with exponential backoff. All configured secrets are tried; if none match, logs error with secret count. |

## 6. Non-Stripe Payment Paths

- **Reactive quote requests** (`is_quote_request=true`): Status `pending`; no PaymentIntent is created. The photographer sets a positive custom price and sends a JWT-based quote link (see `features/infrastructure/18-jwt-offer-tokens.md`).
- **Payable quote links:** The signed token is verified again at checkout. Its positive `price` is the server-authoritative total, client item prices are ignored, standard pricing strategies are bypassed, and `rights_text` is frozen in the invoice snapshot. Payment then follows the normal invoice or positive-value immediate Stripe path.
- **Invoice (B2B):** Status `invoice_created`. The invoice/confirmation mail is queued immediately after checkout; no Stripe webhook is required. Payment is collected offline (bank transfer), and an admin marks the order `paid` manually.
- **Settled free orders:** A valid discount that reduces a positive cart to exactly `0` creates a `paid` order with no PaymentIntent and queues the usual invoice/confirmation immediately. A valueless cart that was not reduced by a real discount is rejected.
- **Delivery notes (collective invoice Org):** Status `delivery_note`. The order's invoice/confirmation is queued immediately, then the order is consolidated into a monthly collective invoice and marked `archived_in_collective`.
