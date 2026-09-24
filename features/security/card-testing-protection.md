---
domain: security
topic: card-testing-protection
status: approved-soll
---

# Card-Testing Protection

## Status

**Approved SOLL architecture (2026-09-23).** This document is the
implementation contract for the checkout/card-testing defense. V036 and the
implementation sources are present in the current tree; V036 remains the
separate Card-Testing migration, while V038 is the current repository frontier
(V037 is the preceding Guest-Ownership migration). Operational rollout, final
review, and any current-tree verification remain tracked in `AGENTS.todo.md`; CI
references in that board are historical evidence, not a claim about every
uncommitted change. The existing checkout flow in
[`features/ecommerce/09-stripe-checkout-flow.md`](../ecommerce/09-stripe-checkout-flow.md)
remains the reference for order states and Stripe integration; the rules below
take precedence where they add idempotency, identity, or abuse controls.

## 1. Goal and threat model

Immediate Stripe checkout must not become an inexpensive way to test stolen or
otherwise invalid card numbers, or a way to generate a large number of
PaymentIntents. The defense is server-authoritative and defense-in-depth:

- an attacker cannot create an unbounded number of PaymentIntents;
- a retry, refresh, timeout, or concurrent request cannot create a second
  PaymentIntent for the same checkout;
- a valid Stripe event cannot mark an unrelated order as paid;
- a declined attempt produces useful, bounded risk telemetry without storing
  card data;
- legitimate customers retain the normal PaymentIntent and 3DS experience;
- optional Cloudflare Turnstile can add friction only when the risk signal
  requires it.

Card testing is not the only fraud scenario. Stripe Radar, the PaymentIntent
state machine, webhook signature verification, accounting evidence, and
ordinary authorization remain in force. This feature does not replace Stripe
Radar or 3DS and does not attempt to make a client-side signal authoritative.

### Non-goals

- Storing PAN, CVC, magnetic-stripe data, or a reusable card number.
- Replacing Stripe's hosted/embedded payment UI or disabling 3DS.
- Treating a Turnstile token, browser fingerprint, or client-supplied score as
  proof of identity.
- Applying Stripe limits to free, quote, or invoice-only paths that do not
  create a PaymentIntent. Those paths retain their existing controls.
- Adding a second ad-hoc checkout limiter to the existing application
  throttles. Checkout limits are a dedicated, separately configurable defense.

## 2. Data model — separate V036 Card-Testing migration

All schema changes for this feature belong in the separate migration
`backend/database/migrations/V036__card_testing_defenses.php`. Do not amend
V035 or an already deployed migration. V036 remains the Card-Testing migration
(current repository frontier: V038; new changes start at V039) and must be
followed by the normal seed step in development/CI (`migrate --seed` or
`migrate:fresh --seed`).

### 2.1 User-to-Stripe-customer mapping

V036 adds a nullable, unique `users.stripe_customer_id` column as the
authoritative one-to-one mapping between a portal user and a Stripe Customer.
There is no second customer mapping table and no local payment-method store.
The value is an identifier only; PAN, CVC, and card details never enter the
portal database.

`StripeCustomerService` creates the Customer lazily on the first eligible
immediate Stripe checkout and reuses it on later checkouts. Creation is
serialized with a user row lock and uses a deterministic Stripe idempotency
key, so concurrent requests cannot create two Customers for one user. The
service may be disabled in tests or non-Stripe environments through the
existing feature configuration; disabling it must not silently change the
identity checks for a live Stripe account.

When a user is deleted, the local identifier is removed or anonymized subject
to the accounting retention rules. A Stripe Customer is not silently deleted
from the Dashboard as a side effect of a normal account deletion unless a
separately approved retention/deletion procedure says so.

### 2.2 Order checkout identity and PaymentIntent state

V036 adds the following authoritative fields to `orders`:

| Field | Contract |
| --- | --- |
| `checkout_idempotency_key` | Opaque key for one checkout session; supplied by the `Idempotency-Key` header or generated as `auto-{checkout_fingerprint}`; unique together with `user_id` and non-null for immediate Stripe orders |
| `checkout_fingerprint` | 64-character SHA-256 hash of the server-canonicalized checkout intent, including user/brand, item and option identity, billing/quote/coupon context, withdrawal consent, and server-calculated amount; never raw client input |
| `payment_intent_generation` | Monotonically increasing generation for each intentionally new PI; starts at `1` and changes when a stale/terminal PI is replaced |
| `payment_failure_count` | Bounded, saturating counter incremented by accepted `payment_intent.payment_failed` events; it must never overflow or trigger an unbounded retry loop |
| `last_payment_failure_at` | Timestamp of the most recent accepted failure event |
| `last_payment_decline_code` | Sanitized, truncated Stripe failure/decline code (maximum 64 characters), never a raw error object |

The existing `orders.stripe_payment_intent_id` and `orders.ip_address` columns
remain the PI linkage and accounting-evidence fields. Add the composite
unique/index support needed for `(user_id, checkout_idempotency_key)` and
stale-PI selection. A checkout fingerprint is an order/cart fingerprint, not a
cross-site browser fingerprint. A lost-key fingerprint fallback does not use
`orders.created_at` as an authoritative freshness cutoff: a pending order may
have received a freshly replaced PI after its row was created. The server
retrieves the persisted PI and lets its remote timestamp/identity decide reuse
versus replacement.

Every new PI carries server-owned identity in Stripe metadata:
`order_id`, `portal_user_id`, `generation` (and the checkout key/fingerprint
contract), plus the server-authoritative amount and EUR currency. The local
`payment_intent_generation` and Stripe metadata must remain in lockstep.

### 2.3 Bounded PaymentIntent failure telemetry

V036 intentionally uses the bounded order fields above rather than an
unbounded event table. `payment_failure_count`, `last_payment_failure_at`, and
the sanitized `last_payment_decline_code` provide enough signal for risk
scoring and Stripe/Radar correlation while keeping the data model small.
Webhook event IDs are deduplicated in the existing short-lived cache (seven
days); a retry must not increment the counter twice.

The telemetry contract is bounded as follows:

1. The counter saturates at a fixed small maximum (target `255`) and the
   decline code is capped at 64 characters.
2. Only coarse error type/code/decline values are retained; raw Stripe error
   bodies, client secrets, card data, and browser fingerprint payloads are not
   stored.
3. User/IP failure-velocity counters are separate, bounded rate-limit state;
   they expire with their configured window and are not copied into the order.
4. The existing raw order IP snapshot remains available only for the approved
   invoice/evidence purpose. Risk keys use a privacy-preserving hash where a
   durable key is needed.

A verified, identity-matched `payment_intent.payment_failed` event is the only
thing allowed to increment this telemetry. An unknown, obsolete, or mismatched
PI is logged/quarantined without changing the order counters.

For the customer-IP failure-velocity bucket, the trusted source is the
persisted `orders.ip_address` snapshot captured during checkout. The Stripe
webhook request IP is transport/ingress metadata and must never populate the
customer IP bucket. The trusted checkout-request IP is used separately for
Turnstile Siteverify `remoteip`; these two sources are not interchangeable.

## 3. Checkout admission and decision order

The immediate Stripe path is the only path that creates a PaymentIntent. The
server executes these checks in a deterministic order:

1. Authenticate the user and resolve the trusted client IP. Only the known
   reverse proxy may supply forwarding headers; arbitrary `X-Forwarded-For`
   values are not trusted.
2. Validate payment method, cart access, withdrawal consent, coupon/quote
   rules, and server-side pricing. Never use the client amount to authorize a
   PI.
3. Enforce the minimum account-age policy below. A rejected new account does
   not cause a Stripe API call.
4. Resolve the idempotency key and canonical checkout fingerprint. An exact
   replay of an existing pending checkout is resolved before any new PI or
   new-PI state change; the route-level request budget may still count the
   replay, but it never creates a second PaymentIntent.
5. Apply the dedicated per-user and per-IP checkout limits. Both buckets are
   checked independently and atomically; passing one never bypasses the other.
6. Ask the risk service whether a Turnstile challenge is required. If required,
   verify the server-side challenge before creating or confirming a PI.
7. Create a new PI only if no reusable pending PI exists. Set Stripe's
   `customer` to the user's mapped Customer, set server-authoritative amount
   and currency, and include `order_id`, `checkout_idempotency_key`,
   `checkout_fingerprint`, and `generation` in PI metadata.
   Persist the PI ID and generation in the same transaction/locking strategy
   as the order state.
8. Return the order ID and the PI client secret only to the authenticated
   owner. The secret is never written to the database or logs.

The response for a policy rejection must not disclose whether an account, card,
Stripe Customer, or IP exists. Rate-limit responses include a generic message
and `Retry-After`; idempotency conflicts use a stable, non-sensitive error
code.

#### 3.0.1 Incident-response kill switch

`STRIPE_CHECKOUT_ENABLED` is a server-only configuration flag (default `true`).
When it is `false`, only a positive-value immediate Stripe checkout that would
need a new or ambiguous PaymentIntent is blocked with a generic retryable `503`
before Customer or PaymentIntent creation. Exact replays of an already usable
pending PI remain readable, while invoice, quote, Lieferschein, and settled-free
paths are unaffected. No client field can override this flag. Invalid runtime
configuration fails closed and is logged without request or payment data.

## 3.1 Dedicated per-user/per-IP limits

The immediate checkout endpoint uses a dedicated named limiter, separate from
login, registration, password-reset, API, and model-registration limits. The
route-level buckets are independent: a user bucket cannot be bypassed by
changing IP, and an IP bucket protects multiple users behind one address.
The initial approved policy is:

| Bucket | Checkout operation | Limit | Window/configuration |
| --- | --- | ---: | --- |
| Authenticated user | Immediate Stripe checkout requests/PI attempts | 5 | per hour / `CHECKOUT_THROTTLE_USER_PER_HOUR` |
| Client IP | Immediate Stripe checkout requests/PI attempts | 10 | per hour / `CHECKOUT_THROTTLE_IP_PER_HOUR` |
| Client IP | Immediate Stripe checkout requests/PI attempts | 30 | per day / `CHECKOUT_THROTTLE_IP_PER_DAY` |

The values are configuration, not magic numbers in a controller. Missing
configuration uses these safe defaults and is visible in health/metrics
output. A deployment may tune values only through a reviewed configuration
change and must preserve independent user/IP enforcement. Responses use a
generic message and `Retry-After`; an IP rejection never identifies a user.

The limiter is race-safe across web workers and uses the trusted client IP
resolved by the application. It is intentionally conservative at the request
boundary: an exact idempotent replay may consume an HTTP budget, but the
idempotency layer below still guarantees that it cannot create a second
PaymentIntent. A key reused with a different fingerprint is a conflict and is
counted as suspicious in risk metrics.

### 3.2 Minimum account age for immediate checkout

Immediate Stripe checkout requires an activated account that is at least
`STRIPE_CHECKOUT_NEW_ACCOUNT_HOURS` old (initial default: **24 hours**),
measured against `users.created_at` in UTC. This check is server-side and
applies before Stripe Customer or PaymentIntent creation.

The policy is deliberately limited to immediate Stripe checkout. It must not
silently turn a legitimate invoice, quote, support, or free-order path into a
Stripe attempt. If the business later needs an exception, it must be an
explicit, audited policy rather than a hidden client flag. A global kill switch
for immediate Stripe checkout remains available for incident response.

## 4. Persistent, idempotent reuse of pending PaymentIntents

The browser may generate one opaque `checkout_idempotency_key` for a checkout
session and send it on every retry. The server derives the fingerprint from the
validated cart; the client cannot choose the amount, user, brand, or order
identity.

For a matching `(user, checkout_idempotency_key, checkout_fingerprint)`:

- return the existing `pending_payment` order and the same Stripe PI;
- do not call `PaymentIntents.create` again;
- do not increment the generation or create a new-card attempt (the route-level
  request budget may still count the replay);
- return a fresh client secret only if Stripe still exposes it and the caller
  is the authenticated order owner; never persist or log the secret.
- If a newly created PaymentIntent response has no non-empty client secret, treat
  it as an ambiguous create failure: return a retryable `502`, leave the order
  pending without persisting the PI ID or incrementing the generation, and reuse
  the same deterministic Stripe idempotency key on retry. Every initial response
  is validated before persistence/return: non-empty PI ID and client secret, a
  non-terminal actionable status, a positive integer Stripe `created`
  timestamp, exact amount/currency, and the complete server-owned V036 metadata (`order_id`, key, fingerprint, generation,
  `portal_user_id`, account-age evidence) plus the mapped Stripe Customer. The
  safe Stripe view exposes only these validation fields and never raw payment
  method/details. Legacy three-argument service fixtures may omit new metadata,
  but the production CheckoutService V036 path does not.

The following cases are explicit:

| Situation | Required behavior |
| --- | --- |
| Same key, same fingerprint, pending PI | Idempotent replay; reuse the PI |
| Same key, different fingerprint | `409` idempotency conflict; no new PI |
| Same fingerprint, pending PI with a lost client key | Reuse only for the same user and authorized checkout context; before pricing, the server may recompute a bounded candidate fingerprint using each persisted order total so coupon expiry/usage cannot strand recovery. It never trusts a client amount and remains user/brand scoped. When the recovery
path resumes or replaces a PI, Stripe metadata and the deterministic API key
come from the persisted order identity, never from the new recovery key |
| PI is `succeeded` or order is `paid` | Return the paid result; never create a replacement |
| PI is canceled, expired, or order is closed | Start a new generation only after confirming the old PI is terminal; retain the order's key for audit but require a new checkout session for a new order |
| Concurrent identical requests | Unique constraints, row locking, and the Stripe idempotency key ensure one PI |

The cache identity lock is a lease, not merely a short de-duplication hint. Its
TTL must cover the complete locked operation, including the Stripe PHP SDK's
current per-request timeout (80 seconds by default) and the worst-case sequence
of retrieve, cancel, Customer creation, and PaymentIntent creation. The
implementation therefore uses `4 × Stripe timeout + 30 seconds` (350 seconds
with the current SDK default) and keeps the five-second lock wait/409 behavior.
The deterministic Stripe idempotency key and conditional order updates remain
required backstops; the lease prevents a second request from entering a still
active create path and issuing a concurrent duplicate Stripe request.

The browser's `Idempotency-Key` is stored per user and is paired with the
canonical fingerprint. The Stripe API idempotency key is a separate
server-derived value, for example `pi_{order_id}_{payment_intent_generation}`.
It is never a secret and contains no card data. A canceled or expired
generation cannot be reused because Stripe idempotency keys are not a
substitute for a new payment attempt. A legacy three-argument
`createPaymentIntent` call without explicit V036 context retains the
historical `pi_{order_id}` key so an ambiguous pre-deployment create can be
retried without becoming a second PI.

The canonical fingerprint must change when the validated cart, selected
license/options, coupon/offer context, currency, or server-calculated amount
changes. A client that changes the payload after receiving a key cannot attach
that key to a different order.

## 5. PaymentIntent lifecycle and stale-PI cleanup

Pending PIs must not accumulate indefinitely. A scheduled cleanup job/command
runs at least hourly (the exact scheduler is operational configuration) and
selects `pending_payment` orders whose Stripe PI `created` timestamp is older
than `STRIPE_STALE_PAYMENT_INTENT_HOURS` (initial default: **2 hours**). The
idempotent replay path may use a shorter `CHECKOUT_IDEMPOTENCY_TTL_MINUTES`
window (initial default: 30 minutes) to replace an abandoned PI; both paths
must use the same generation/identity rules.

For each candidate, under a row lock and with Stripe as the source of truth:

1. Retrieve the PI by the stored ID.
2. Verify the returned PI ID, `metadata.order_id`, amount, and EUR currency
   against the candidate order before inspecting its lifecycle state. For a
   V036 order, require complete server-owned metadata (`order_id`,
   `checkout_idempotency_key`, `checkout_fingerprint`, `generation`,
   `amount_cents`, `currency`, and `portal_user_id`) and validate the mapped
   Stripe Customer when the safe PI view returns one. Missing metadata is
   allowed only for a clearly legacy order; any mismatch skips the candidate
   without a remote cancel.
3. If it is `succeeded` or otherwise already in a terminal successful state,
   pass the retrieved PI through the shared strict reconciliation service. It
   revalidates identity, amount/received amount, customer, and generation,
   conditionally transitions the still-pending order to `paid`, enriches the
   fee when available, and queues invoice mail idempotently. The signed webhook
   remains the normal authority; the command never creates or substitutes a PI
   and never grants paid on an identity mismatch.
4. If it is still actionable, cancel it through Stripe and record the cleanup
   generation/event.
5. Transition the order to the approved closed/expired state, retain the
   checkout key and PI/order IDs for accounting/audit, and make a new checkout
   session explicit before another order is created.
6. If the order or PI is missing, record a bounded reconciliation event and do
   not create a replacement automatically.

The cleanup is idempotent, bounded per run, and safe against a success webhook
racing with cancellation. It must not cancel a fresh PI, a paid PI, a disputed
or refunded order, or a PI whose returned identity does not belong to the
candidate order. A crash between the Stripe cancellation and the local update
is recovered by the next run using the PI ID and generation.

## 6. Stripe webhook identity and failure handling

The existing Stripe signature verification remains mandatory. Webhook event
IDs are stored/deduplicated so Stripe retries and duplicate deliveries are
safe. The webhook is not allowed to trust a client return URL or an arbitrary
`metadata.order_id` alone.

### 6.1 `payment_intent.payment_failed`

On a verified `payment_intent.payment_failed` event:

1. Confirm that the event PI ID equals the order's stored
   `stripe_payment_intent_id` and that the generation still matches. If not,
   quarantine the event as an identity mismatch and do not alter checkout
   state.
2. Deduplicate by Stripe event ID (short-lived cache) and transactionally
   increment the saturating `payment_failure_count`, update
   `last_payment_failure_at`, and sanitize `last_payment_decline_code`.
3. Write only the bounded, coarse failure telemetry described in section 2.3.
   The failure may be an issuer decline, authentication/3DS failure, invalid
   request, or another normalized category; do not store PAN/CVC or a raw
   Stripe error object.
4. Feed the user/IP failure counters into the risk signal. A high failure
   velocity can require Turnstile, reduce available checkout quota, or trigger
   an operational alert; it never silently changes a successful order.
5. Leave a genuinely pending order in its recoverable state. The client may
   retry the same PI according to Stripe's normal Payment Element/3DS flow, but
   the server keeps the generation and identity checks intact.

A card decline observed only by Stripe.js must be handled by the same bounded
client retry policy; it must not cause an unbounded loop of new PaymentIntents.
The exact decline code is for Stripe/Radar correlation, not for exposing issuer
details to the customer.

### 6.2 Stricter `payment_intent.succeeded` identity

A success webhook may transition an order to `paid` only when **all** of these
checks pass in one locked/idempotent operation:

- the Stripe signature is valid and the event is a supported type;
- `event.data.object.id` equals `orders.stripe_payment_intent_id`;
- `metadata.order_id` identifies the same order;
- `metadata.checkout_idempotency_key` and
  `metadata.checkout_fingerprint` match the persisted values;
- `metadata.generation` equals the order's current `payment_intent_generation`;
- amount and currency match the server-authoritative order, and the received
  amount is not below the order total;
- a supplied Stripe Customer ID equals the user's mapped
  `stripe_customer_id` (and is not mapped to another user). For a clearly
  pre-V036 order, an absent PI customer remains compatible even if the user
  acquired a mapping later; an explicitly supplied conflicting customer is
  still rejected;
- for a signed success event whose matching pending V036 order still has no
  local PI ID, all metadata/identity/customer/amount checks must pass before the
  event PI is bound under the row lock; the binding and `pending_payment → paid`
  transition are one conditional update. An invalid event is ignored and never
  creates a replacement PI;
- the order is still `pending_payment` (an already-paid identical event is a
  no-op; a conflicting state is quarantined).

Only the conditional `pending_payment -> paid` transition is allowed to grant
download access or queue fulfillment mail. The order is not considered paid
because metadata contains an order ID, because the amount happens to match, or
because a client reports success. A mismatch creates a security/audit event
and an operator alert, but no payment or fulfillment state change. Valid
duplicate events are acknowledged idempotently; invalid identity events are
quarantined according to the webhook runbook.

Fee expansion is accounting enrichment, not payment identity. If
`latest_charge.balance_transaction.fee` is unavailable, the verified event
still transitions the order to `paid` with `stripe_fee_cents = null`; payout
calculation uses its explicit percentage fallback. A genuine fee of `0` remains
an explicit persisted value and is logged distinctly. The handled event claim
completes after this paid transition rather than retrying indefinitely.

A checkout replay may observe a remote PI with status `succeeded` before the
signed webhook arrives. Because that read path is not the strict paid path, it
returns a non-destructive `payment_pending` response with the order ID and poll
URL, `requires_action=false`, and no client secret. The signed webhook remains
the only path that grants paid access or fulfillment.

## 7. Risk-based, optional Cloudflare Turnstile

Turnstile is an optional second signal, not the primary rate limiter. It is
active only when **both** `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET` are
configured. If either key is missing, the feature is **inactive**: no widget
is requested, no Siteverify call is made, and the normal PaymentIntent flow
continues. This is the required unconfigured behavior for local, CI, and
environments that have not enabled Cloudflare.

Configuration is split as follows:

- `TURNSTILE_SITE_KEY` (safe to expose to the browser);
- `TURNSTILE_SECRET` (server-only, never exposed or logged);
- `TURNSTILE_ALLOWED_HOSTNAMES` (comma-separated; empty falls back to the
  configured frontend host and then the application host);
- `TURNSTILE_USER_THRESHOLD_PER_HOUR` (initial default `3`);
- `TURNSTILE_IP_THRESHOLD_PER_HOUR` (initial default `5`).

The server applies the user and IP risk counters independently only when
Turnstile is active. Calls below both thresholds continue without a challenge;
the call that reaches either threshold requires a token. A high
`payment_intent.payment_failed` velocity, a near-limit checkout bucket, or
another bounded risk signal may lower the effective threshold. The frontend
renders the widget only for the `turnstile_required` response and includes
action `checkout` and the bounded cdata value `checkout-{user_id}` (the
Cloudflare 32-character limit is enforced by truncating the user-ID suffix to
23 characters). Client-side success, a widget token, or a client-supplied score
is never sufficient.

When a challenge is required, the server calls Cloudflare's Siteverify endpoint
as a form POST with the secret, response token, and trusted client IP. It uses
a short timeout and requires `success=true`, action `checkout`, the expected
user cdata, and an allowed hostname. A missing/invalid/expired token is a
`403` policy response with `turnstile_required=true`; a Siteverify transport or
service failure is a `503` and fails closed while Turnstile is active. No PI
is created or confirmed when verification does not succeed. The raw token and
full Siteverify response are not persisted or logged; only a short-lived
outcome/reason may be retained for operations.

When Turnstile is inactive, the server still enforces account age, dedicated
checkout limits, idempotency, Stripe identity, and webhook controls. An
operator can disable a live challenge only through a reviewed configuration
change and alert; it is not a client-side bypass.

## 8. Stripe.js and shopping-page integration

The Stripe.js loader must be available across every shopping surface that can
lead to immediate payment: cart, gallery/product purchase, checkout, quote or
offer purchase, and payment return/recovery pages. The route manifest and
checkout entry points must not rely on Stripe.js having been loaded on a
previous page.

Implementation must use the official Stripe.js loader and the configured
publishable key, load it once per application, and handle direct navigation,
refresh, back/forward navigation, and a failed network load. Only the PI
client secret crosses the API boundary; the secret key never reaches the
browser. Do not put a client secret or PI identifier in local storage, and do
not log it.

The existing PaymentIntent/Payment Element flow remains the payment surface.
Do not hardcode `payment_method_types: ['card']`; payment method selection and
3DS remain Stripe/Dashboard-driven. The frontend must handle asynchronous
authentication and return flows, including a cancelled or expired 3DS
challenge, without creating a fresh PI on every render. Stripe.js is loaded
through the shared bounded loader when the checkout-capable shopping surface
mounts; unrelated routes must not eagerly load it. This is a reliability
requirement, not permission to bypass the server-side checks in section 3.

Turnstile widget code is separate from Stripe.js and is loaded only when the
server requests it. Both integrations must respect the application's CSP and
must not introduce inline secrets.

## 9. Stripe Dashboard, Radar, and 3DS operations

This checklist is part of the release contract. It is completed separately for
test and live Stripe accounts; credentials and dashboard values are never
committed.

### Before enabling the feature

- [ ] Create or verify a least-privilege Stripe API key/RAK for the operations
      used by customer creation, PaymentIntents, retrieval/cancellation, and
      webhook handling. Use separate test and live credentials and protect
      them through the deployment secret store.
- [ ] Register the production and test webhook endpoints and verify the
      signing secret for each environment. Subscribe to
      `payment_intent.succeeded`, `payment_intent.payment_failed`,
      `charge.refunded`, and dispute events required by the existing order
      state machine. Test signature failure and replay handling.
- [ ] Configure Radar rules and review thresholds for card-testing velocity,
      high-risk countries/BINs, repeated declines, device/IP anomalies, and
      high-value or high-frequency attempts. Start with review/monitoring where
      appropriate, test false positives, and document every rule change.
- [ ] Configure Radar block/review behavior so application limits, Radar, and
      webhook telemetry do not silently contradict one another. Keep a
      rollback path for a rule that blocks legitimate customers.
- [ ] Enable and test 3DS/SCA, including challenge, frictionless flow,
      authentication failure, timeout, mobile/browser return, and recovery.
      Do not disable 3DS merely to reduce declines.
- [ ] Verify Stripe's dynamic payment-method settings and the existing
      PaymentIntent/Payment Element configuration. Do not force a card-only
      request in application code.
- [ ] Verify the Stripe Customer mapping behavior in test mode: one customer
      per portal user, reuse on checkout, and no local card data.

### Monitoring and response

- [ ] Alert on new-PI rate, idempotent-replay rate, user/IP 429s, failure-code
      velocity, webhook identity mismatches, quarantined events, stale-PI
      cleanup counts, account-age rejections, and Turnstile outcomes.
- [ ] Review Radar queues and PaymentIntent/charge activity daily during the
      initial rollout, then at a risk-based cadence. Keep logs free of PAN,
      CVC, client secrets, and raw Siteverify tokens.
- [ ] Maintain a runbook for a Radar false positive, a compromised API key, a
      webhook outage, a Turnstile outage/configuration error, and an unexpected
      failure-velocity spike. A key compromise follows Stripe's key-rotation
      and incident-response procedure.
- [ ] Run the full test-mode card, 3DS, duplicate-webhook, stale-PI, and
      idempotency scenarios before enabling live enforcement. Roll out limits
      in monitoring mode first where available, then enforce.

## 10. Privacy, security, and retention

The privacy notice and internal data inventory must describe this defense
before production rollout. The following data is processed for fraud
prevention, payment execution, and evidence:

- portal user ID, account creation time, brand, and checkout/order ID;
- Stripe Customer ID and PaymentIntent ID (identifiers, not card data);
- the canonical checkout fingerprint hash;
- the existing order IP snapshot and a keyed IP hash used for rate limiting;
- coarse PaymentIntent failure/decline codes and bounded event IDs;
- Cloudflare Turnstile verification outcome, action, hostname, and possibly
  the trusted remote IP required for Siteverify;
- timestamps, generation, and user-agent/device risk signals only when strictly
  necessary and bounded.

### Required controls

- State the purposes and legal basis in the privacy notice: contract
  performance for completing a purchase, legitimate security interests for
  fraud prevention, and legal/accounting obligations for transaction evidence.
  Legal/DPO review is required for the final wording and retention periods.
- Name Stripe and Cloudflare as processors/sub-processors where applicable and
  maintain the required DPAs, transfer assessments, and processor settings.
- Do not store PAN, CVC, magnetic-stripe data, a client secret, a raw Turnstile
  token, or a full Siteverify response. Do not send card data to Cloudflare or
  application analytics.
- Explain the purpose and retention of IP-derived risk keys, the bounded
  failure telemetry, and any Turnstile cookies/device signals in the privacy
  and cookie documentation. Provide the required notice/consent analysis;
  security processing may not be presented as optional checkout functionality
  when it is part of the approved contract/security process.
- Keep raw order/IP evidence only for the already-approved accounting and
  dispute-evidence retention period. Prune the separate risk telemetry on its
  shorter retention schedule, aggregate where possible, and honor deletion or
  access requests subject to legal retention.
- Rotate or erase keyed IP hashes when the risk key is retired. Do not use the
  checkout fingerprint as a cross-site advertising or browser-tracking
  identifier.
- Restrict telemetry and Stripe identifiers to staff/support roles that need
  them, log access, and keep exports free of unnecessary personal data.

## 11. API and state contracts

The V036 PaymentIntent-abuse contract is deliberately scoped to a
**positive-value immediate Stripe checkout**: after server-side validation and
pricing, the request is not a reactive quote request (a cart item flagged
`isQuote`), selects Stripe rather than invoice/Lieferschein settlement, and has
a server-calculated total greater than zero. The V036 idempotency,
minimum-account-age, dedicated checkout quota, kill-switch, Stripe
Customer/PaymentIntent, and Turnstile contracts apply to that classified path.

The browser checkout client sends an opaque `Idempotency-Key` on every checkout
request, including invoice, settled-free, and reactive-quote submissions. Header
presence is not a global idempotency guarantee. The backend may inspect a
candidate key during the immediate-Stripe preflight, but only the in-scope path
persists and uses it as V036 checkout identity; excluded orders retain null
`checkout_idempotency_key` and `checkout_fingerprint` values. Non-PaymentIntent
invoice/Lieferschein, zero-value settled-free, and reactive quote-request paths
remain outside this PI-abuse contract. They retain their existing validation,
authorization, generic request throttles, and business-flow behavior, but do not
receive V036 replay/conflict semantics, account-age or dedicated-quota checks,
Stripe Customer/PI creation, the incident kill switch, or Turnstile gates.

Within the positive-value immediate Stripe scope, the implementation must
preserve these externally visible contracts:

- a valid matching key/fingerprint replay returns the same order/PI;
- mismatch returns a stable `409` conflict without creating a PI;
- account-age and checkout-quota rejections are generic and do not reveal risk
  data;
- Turnstile is represented by a server decision (`required` or `not_required`),
  never by trusting a client boolean;
- only the strict success webhook may make an order `paid`;
- `payment_intent.payment_failed` may add bounded telemetry but may not grant
  access or fulfillment;
- stale cleanup is observable, idempotent, and race-safe.

A client cannot override the Stripe Customer, order ID, amount, currency,
fingerprint, generation, user ID, or IP identity. Server responses must avoid
returning raw decline/provider details that are useful for card testing. The
signed webhook remains the normal authority for fulfillment. A bounded stale
cleanup may additionally reconcile a remote `succeeded` PI only after retrieving
the PI by the order's stored identifier and passing the same strict identity,
amount, received-amount, customer, and generation checks; it must never create
or substitute a PI. A signed success event for an order whose local PI link is
still null may bind that event PI under the same locked conditional transition.

## 12. Rollout and acceptance

1. Ship the separate V036 migration and backfill only safe nullable fields;
   do not create Stripe Customers for historical users in bulk.
2. Deploy telemetry and cleanup in observe-only mode, compare the new limits
   with positive-value immediate Stripe traffic, and verify that this scoped
   path creates no duplicate PIs.
3. Keep `STRIPE_CHECKOUT_ENABLED` under server-side configuration control and
   verify the disabled response blocks only new or ambiguous PaymentIntent
   attempts on that in-scope path.
4. Enable minimum account age and dedicated checkout quotas for the in-scope
   path, then enable risk-based Turnstile only after its Dashboard, CSP,
   privacy, and Siteverify checks pass. Verify that invoice/Lieferschein,
   settled-free, and reactive-quote flows remain unchanged.
5. Run the PHPUnit, Vitest, and Playwright tasks recorded in
   `AGENTS.todo.md`, followed by the full backend suite and frontend lint/build.
6. Review Radar, 3DS, webhook identity mismatches, and failure telemetry before
   declaring the feature live. Roll back configuration before rolling back
   durable identifiers; never delete a PI or order merely to make a dashboard
   look clean.

Rollout acceptance must also verify that the browser key is present on all
checkout submissions while the backend persists and uses it only for
positive-value immediate Stripe checkout. The excluded non-PaymentIntent paths
must not acquire V036 key/fingerprint persistence or PI-abuse gates. Persistent
deduplication for those non-immediate paths is a separate follow-up requiring
its own feature contract and tests; it must not be implied by this rollout.

The feature is complete only when the migration, scoped server enforcement,
cleanup, webhook identity checks, optional Turnstile behavior, Stripe.js
loading, privacy updates, operational checklist, and all required tests are
verified.
