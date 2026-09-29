---
title: Card-Testing Monitoring & Operations Runbook
status: active
scope: backend/app/Services/CheckoutRiskService.php, backend/app/Http/Controllers/WebhookController.php, backend/app/Services/PaymentIntentReconciliationService.php, backend/app/Services/CheckoutIdempotencyService.php, backend/app/Console/Commands/CancelStalePaymentIntents.php, backend/config/app.php, backend/database/migrations/V036__card_testing_defenses.php
related: features/security/card-testing-protection.md
---

# Card-Testing Monitoring & Operations Runbook

This is the operator-facing half of [`card-testing-protection.md`](./card-testing-protection.md).
That document is the SOLD state (design, data model, defence order); this one is
the runbook: **where each signal is emitted, what number triggers a decision,
the command that reproduces that number, and what the operator does next.**

It implements the monitoring checklist items from
`card-testing-protection.md` § "Monitoring and response": new-PI rate,
idempotent-replay rate, user/IP 429s, failure-code velocity, webhook identity
mismatches, quarantined events, stale-PI cleanup counts, account-age rejections
and Turnstile outcomes.

---

## 0. How to read this runbook

**Evidence convention.** Every anchor is `file:line` *plus the quoted line*, per
the repo rule in `AGENTS.md` §3 (evidence in `features/`). An anchor without
the quoted text is worthless because a shifted line looks exactly like a correct
one.

**Every number carries the command that reproduces it.** A number without a
command cannot be refuted later and therefore rots silently. Where a threshold
is *code-enforced*, that is stated and the enforcing line is quoted. Where a
threshold is *operator-chosen* (an alert boundary), it is labelled as such and
tied to the code-enforced number it derives from — it is never presented as a
measurement.

**The single most important fact before anything else:** most *denials* in this
system are never written to any log. Read §1.4 before building an alert on a
signal that does not exist.

---

## 1. Telemetry sources

### 1.1 The log stream is short-lived

| Property | Value | Evidence |
|---|---|---|
| Log channel (production) | `stderr` | `deployment/docker-compose.yml:146` → `- LOG_CHANNEL=stderr` |
| Docker log driver | `json-file`, `max-size: "10m"`, **no `max-file`** | `deployment/docker-compose.yml:87-90` (backend service): `driver: "json-file"` / `options:` / `max-size: "10m"` |

Consequence for the operator: `docker logs` gives you the recent tail only.
Anything that must survive longer than the rotation needs to be scraped into a
durable sink. Every command in this runbook therefore works on a **window** that
you choose explicitly (`--since`), and §4 states which signals cannot be
reconstructed retroactively.

```bash
# the log stream itself
docker logs portal_backend --since 24h 2>&1 | tail -n 50
```

### 1.2 The cache table is the primary handle for every limiter

All checkout limiter and failure-velocity state is `RateLimiter` state in the
configured cache store, which in production is the **database** store:

- `backend/config/cache.php:20` → `'default' => env('CACHE_STORE', env('APP_ENV') === 'production' ? 'database' : 'file'),`
- `backend/config/cache.php:47` → `'table' => env('DB_CACHE_TABLE', 'cache'),`
- `backend/config/cache.php:117` → `'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),`
- Table shape, `backend/database/migrations/V001__initial_portal_schema.php:14-17`:
  `$table->string('key')->primary();` / `$table->mediumText('value');` / `$table->bigInteger('expiration')->index();`

The keys are **not** secret and **not** opaque: they are
`{namespace}:{user|ip}:{hmac-sha256}` with the namespace written literally, so
they are greppable — only the trailing identity digest is irreversible.

- `backend/app/Support/CheckoutKey.php:22` → `return $namespace.':user:'.self::digest($identifier);`
- `backend/app/Support/CheckoutKey.php:32` → `return $namespace.':ip:'.self::digest(strtolower($normalized));`
- `backend/app/Support/CheckoutKey.php:42` → `return hash_hmac('sha256', $value, $key);`

**Why that matters:** you can read the *current* value of every quota and
failure counter, and you can see *which* namespaces are active, but you cannot
map a digest back to a user or an IP. The digest is keyed with `config('app.key')`
(`CheckoutKey.php:37` → `$key = (string) config('app.key');`) and is deliberately
non-reversible — see `CheckoutKey.php:10`:
` * Raw IP addresses are evidence data and must not be persisted in cache keys.`
To correlate a digest with a concrete actor you must fall back to the durable
`orders` columns (§1.3), not to the cache.

**Reproducing every cache query in this runbook** (the password is read inside
the container, so nothing sensitive enters your shell history):

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT 1;
SQL
```

Container and credentials are from `deployment/docker-compose.yml:6`
(`container_name: portal_db`), `:33` (`MYSQL_DATABASE: portal_db`), `:34`
(`MYSQL_USER: portal_user`), `:35` (`MYSQL_PASSWORD: ${DB_PASSWORD}`). If the
client binary is not called `mariadb` in your image, use `mysql`.

`value` is a `mediumText` column, so it sorts **lexicographically** — every
ordering below casts explicitly. Reproduce the whole set of active namespaces:

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(cache_key, ':timer', 1), ':', 2) AS namespace,
       COUNT(*)                                                     AS keys
FROM   cache
WHERE  cache_key LIKE '%checkout%' OR cache_key LIKE '%stripe-webhook-event%'
GROUP  BY namespace
ORDER  BY namespace;
SQL
```

### 1.3 The orders table is the durable record

V036 is the schema contract for every durable checkout risk field —
`backend/database/migrations/V036__card_testing_defenses.php:53-69`:

```php
if (! Schema::hasColumn('orders', 'payment_failure_count')) { … $table->unsignedSmallInteger('payment_failure_count')->default(0); }
if (! Schema::hasColumn('orders', 'last_payment_failure_at')) { … $table->timestamp('last_payment_failure_at')->nullable(); }
if (! Schema::hasColumn('orders', 'last_payment_decline_code')) { … $table->string('last_payment_decline_code')->nullable(); }
```

and the order-identity pair at `:35-45`
(`checkout_idempotency_key`, `checkout_fingerprint`) plus
`payment_intent_generation` at `:47-51`. The `stripe_payment_intent_id` lookup
index is `:77-82` (`$table->index('stripe_payment_intent_id', 'orders_stripe_payment_intent_idx');`).

The order status vocabulary is
`backend/app/Models/Order.php:30-41` → `ALLOWED_STATUSES = ['pending', 'invoice_created', 'pending_payment', 'paid', 'overdue', 'cancelled', 'disputed', 'refunded', 'delivery_note', 'archived_in_collective'];`
All SQL in this runbook filters on that list rather than on a bare string.

### 1.4 Denials are mostly unlogged — read this before you alert on anything

`CheckoutRiskService` signals a denial by throwing a response exception and
returns; it writes no log line:

- `backend/app/Services/CheckoutRiskService.php:167-174` → `private function rejectQuota(string $key): never` … `'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),` … `429`
- `backend/app/Services/CheckoutRiskService.php:176-182` → `private function rejectMissingToken(): never` … `'turnstile_required' => true,` … `403`
- `backend/app/Services/TurnstileService.php:188-194` → `private function rejectInvalidToken(): never` … `403`
- `backend/app/Services/TurnstileService.php:196-201` → `private function rejectUnavailable(): never` … `503`
- `backend/app/Console/Commands` is not involved; likewise
  `backend/app/Services/CheckoutEligibilityService.php:22-27` → `private function denied(): HttpResponseException` … `403`

Laravel does not report these, because the response exception is on the
framework's do-not-report list:

- `backend/vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:175` → `HttpResponseException::class,` (inside `protected $internalDontReport`)
- `backend/vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:427-429` → `$e = $this->mapException($e);` / `if ($this->shouldntReport($e)) {`

The same holds for the route throttle middleware, whose exception
(`ThrottleRequestsException` → `Symfony … \TooManyRequestsHttpException` →
`HttpExceptionInterface`) is mapped by `mapException` into the same
do-not-report class.

**Therefore:** user/IP 429s, the missing-Turnstile 403, the failed-Turnstile 403,
the Turnstile-outage 503 and the account-age 403 have **no log line**. Their
observable trace is the limiter key in the cache table (§1.2) or the HTTP
response the client received. §4 lists this as *not measurable* per signal
rather than pretending otherwise.

---

## 2. Signals

### S1 — New PaymentIntent rate

**Where it is emitted.** There is no application log line for a *successful*
PaymentIntent creation. The durable emit is the conditional write of the PI id
onto the order — `backend/app/Services/CheckoutService.php:1321-1323`:

```php
$updated = $query->update([
    'stripe_payment_intent_id' => $paymentResult['id'],
]);
```

i.e. **one order row with a non-null `stripe_payment_intent_id` = one created
PaymentIntent.** `created_at` is the *order row's* creation time, not the
intent's, and Stripe's `created` timestamp is read remotely but never persisted
(`backend/app/Services/StripePaymentService.php:298` →
`'created' => is_numeric($paymentIntent->created ?? null)`).
Use `created_at` as the time axis with two known biases, both named:

- a resumed checkout binds the intent to an *older* order row, so the hour
  bucket is the hour the order was created, not the hour the intent was;
- the missing-link reconciliation binds an intent id to an older order after the
  fact (`backend/app/Services/PaymentIntentReconciliationService.php:124` →
  `$updates['stripe_payment_intent_id'] = $paymentIntentId;`, guarded by `:123`
  → `if ($allowMissingLink) {`).

Both biases move a row *earlier* than the true event; neither invents one. If
exact intent timestamps are ever required, the column has to be added — that is
an application change, deliberately not made here.

Failed creates *are* logged, at
`backend/app/Services/CheckoutService.php:1332-1335`:

```php
Log::error('Stripe payment failed for order {order_id}', [
    'order_id' => $order->id,
    'exception_class' => $e::class,
]);
```

followed by a `502` at `:1348` → `return response()->json(['error' => 'Die Zahlung konnte nicht verarbeitet werden. Bitte versuche es später erneut.'], 502);`

**Code-enforced bound.** A single user may not exceed 5 classified immediate
checkouts per hour and a single IP not 10 per hour / 30 per day
(`backend/config/app.php:146-148`):

```php
'checkout_throttle_user_per_hour' => (int) env('CHECKOUT_THROTTLE_USER_PER_HOUR', 5),
'checkout_throttle_ip_per_hour' => (int) env('CHECKOUT_THROTTLE_IP_PER_HOUR', 10),
'checkout_throttle_ip_per_day' => (int) env('CHECKOUT_THROTTLE_IP_PER_DAY', 30),
```

enforced in `backend/app/Services/CheckoutRiskService.php:38-50`. Note the
comparison is strictly greater-than, so denial starts on attempt **6** per user
per hour (`if ($userAttempts > $userLimit) {`), **11** per IP per hour and
**31** per IP per day.

**Reproducing command — global new-PI rate per hour (last 24 h):**

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') AS hour_utc,
       COUNT(*)                                AS new_payment_intents
FROM   orders
WHERE  stripe_payment_intent_id IS NOT NULL
AND    created_at >= NOW() - INTERVAL 24 HOUR
GROUP  BY hour_utc
ORDER  BY hour_utc;
SQL
```

**Reproducing command — per user, which is the axis the 5/hour limit applies to:**

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT user_id, COUNT(*) AS new_payment_intents
FROM   orders
WHERE  stripe_payment_intent_id IS NOT NULL
AND    created_at >= NOW() - INTERVAL 1 HOUR
GROUP  BY user_id
HAVING new_payment_intents > 5
ORDER  BY new_payment_intents DESC;
SQL
```

The `HAVING … > 5` uses the code-enforced per-user hourly limit verbatim, so
this query needs no invented threshold. For the per-IP axis, replace the
grouping with the raw snapshot IP (do **not** export it — it is evidence data,
see §1.2 and `card-testing-protection.md` §10):

```sql
SELECT ip_address, COUNT(*) AS new_payment_intents
FROM   orders
WHERE  stripe_payment_intent_id IS NOT NULL
AND    ip_address IS NOT NULL
AND    created_at >= NOW() - INTERVAL 24 HOUR
GROUP  BY ip_address
HAVING new_payment_intents > 30
ORDER  BY new_payment_intents DESC;
```

(30 = `checkout_throttle_ip_per_day`, `backend/config/app.php:148`.)

**Operator action.**
1. One user above 5/hour while their order rows show `payment_failure_count = 0`
   and status `paid` → legitimate power user / retry loop on the client. Contact
   before touching a limit; do **not** lower `CHECKOUT_THROTTLE_USER_PER_HOUR`
   for one user (the config is global, `backend/config/app.php:146`).
2. One IP above 30/day with many distinct `user_id`s → shared NAT or an
   attacker. Check §S3 and §S4 for the same IP before deciding.
3. `Stripe payment failed for order {order_id}` spiking → a Stripe-side or
   integration failure, not card testing. Compare against the
   `exception_class` distribution:
   `docker logs portal_backend --since 1h 2>&1 | grep -c "Stripe payment failed for order"`; the
   classes themselves are in the log context, not aggregated by the app.
4. Rate of change on the global hourly series: a step change of the same
   magnitude as the previous total, inside one hour bucket, is the shape of an
   automated run.

### S2 — Idempotent-replay rate

**What a replay is.** A client retry with the same `Idempotency-Key` resolves
back to the existing order instead of creating a new PI. The client key is
validated at `backend/app/Services/CheckoutIdempotencyService.php:46`:
`if ($providedKey !== '' && ! preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $providedKey)) {`
and resolved to `auto-{fingerprint}` when absent (`:53`). The replay lookup
runs **before** the quota, which is why replays do not consume budget —
`backend/app/Services/CheckoutService.php:330-333`:
`if ($replayResponse !== null) { … return $replayResponse; }` and the docblock
`backend/app/Services/CheckoutRiskService.php:21-23`:
`* Exact idempotent replays return before this method and therefore do not consume the PI-attempt budget`.

**Where rejections are emitted** (a *successful* replay is silent — see §4):

- `backend/app/Services/CheckoutIdempotencyService.php:482` → `Log::warning('Stripe idempotency replay could not retrieve PaymentIntent', [`
- `backend/app/Services/CheckoutIdempotencyService.php:495` → `Log::warning('Stripe idempotency replay received a pending PaymentIntent without created timestamp', [`
- `backend/app/Services/CheckoutIdempotencyService.php:511` → `Log::warning('Stripe idempotency replay rejected mismatched stale PaymentIntent', [`
- `backend/app/Services/CheckoutIdempotencyService.php:562` → `Log::warning('Stripe idempotency replay rejected mismatched PaymentIntent', [`
- `backend/app/Services/CheckoutIdempotencyService.php:530` → `Log::warning('Stripe stale PaymentIntent cancellation failed', [`
- `backend/app/Services/CheckoutIdempotencyService.php:542` → `Log::warning('Stripe stale PaymentIntent cancellation returned an invalid response', [`
- `backend/app/Services/CheckoutIdempotencyService.php:206` → `Log::warning('Non-immediate checkout replay finalizer failed', [`

**Code-enforced bound.** At most 4 Stripe requests per identity operation —
`backend/app/Services/CheckoutIdempotencyService.php:32`:
`private const MAX_STRIPE_REQUESTS_PER_IDENTITY_OPERATION = 4;`
with a lock-TTL margin of 30 s at `:34`
(`private const IDENTITY_LOCK_TTL_MARGIN_SECONDS = 30;`). The staleness gate for
*replacing* an intent is
`backend/app/Services/CheckoutIdempotencyService.php:1216-1219`:
`$ttlMinutes = max(5, (int) config('app.checkout_idempotency_ttl_minutes', 30));` … `return $createdAt < now()->subMinutes($ttlMinutes)->getTimestamp();`
(config default at `backend/config/app.php:149`:
`'checkout_idempotency_ttl_minutes' => max(5, (int) env('CHECKOUT_IDEMPOTENCY_TTL_MINUTES', 30)),`).

**Reproducing command — all replay-rejection lines, by message, last 24 h:**

```bash
docker logs portal_backend --since 24h 2>&1 \
  | grep -oE "Stripe (idempotency replay|stale PaymentIntent cancellation)[^']*" \
  | sort | uniq -c | sort -rn
```

**Reproducing command — the durable replay footprint** (how many orders carry a
V036 identity key at all, i.e. how much of the checkout traffic is
replay-capable):

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT COUNT(*) FILTER (WHERE checkout_idempotency_key IS NOT NULL) AS keyed_orders,
       COUNT(*) FILTER (WHERE checkout_fingerprint  IS NOT NULL) AS fingerprinted_orders,
       COUNT(*) FILTER (WHERE payment_intent_generation > 1)       AS regenerated_intents
FROM   orders
WHERE  created_at >= NOW() - INTERVAL 24 HOUR;
SQL
```

`payment_intent_generation > 1` is the readable proxy for "this checkout was
retried with a *replacement* PI rather than a replay" — the counter is the
column V036 added at
`backend/database/migrations/V036__card_testing_defenses.php:47-51` and is
part of the identity check (see S5).

**Operator action.**
1. `rejected mismatched PaymentIntent` / `rejected mismatched stale PaymentIntent`
   in volume → someone is retrying a key against a **changed** cart or a
   **replaced** PI. That is the one replay message that also carries order
   identity; escalate via S5.
2. `could not retrieve PaymentIntent` / `received a pending PaymentIntent without
   created timestamp` → Stripe read path or a genuinely new intent; check
   `stripe:cancel-stale-payment-intents` (S6) before touching checkout.
3. `stale PaymentIntent cancellation failed` → a replacement could not be
   cancelled at Stripe. The old intent stays live; this is a **money-path**
   condition, not noise. Go to S6 and then to the Stripe dashboard.
4. `regenerated_intents` far above `keyed_orders` → a client that regenerates
   instead of replaying. That is a client bug producing avoidable PI volume; the
   fix belongs in the frontend, not in the limits.

### S3 — User/IP 429

**Where it is emitted.** Exactly one enforcement point,
`backend/app/Services/CheckoutRiskService.php:42-50`:

```php
if ($userAttempts > $userLimit) {
    $this->rejectQuota($userKey);
}
if ($ipHourAttempts > $ipHourLimit) {
    $this->rejectQuota($ipHourKey);
}
```

with the three buckets hit at `:34-36` (`RateLimiter::hit($userKey, 3600);` /
`RateLimiter::hit($ipHourKey, 3600);` / `RateLimiter::hit($ipDayKey, 86400);`).
Every classified attempt is counted against **all three** buckets before any of
them is checked — the intent is stated at `CheckoutRiskService.php:31-33`:
`// Count every classified attempt against every bucket before checking any one of them, so changing IP cannot bypass the user bucket and changing user cannot bypass the IP buckets.`

The response is `429` with `Retry-After`
(`CheckoutRiskService.php:169-173`, body
`'error' => 'Checkout ist vorübergehend ausgelastet. Bitte versuche es später erneut.',`).

**Two distinct 429 sources on the same endpoint — do not confuse them.**

| Source | Body | Headers | Where |
|---|---|---|---|
| Checkout quota (this signal) | `{"error":"Checkout ist vorübergehend ausgelastet. …"}` | `Retry-After` only | `CheckoutRiskService.php:169-173` |
| Route middleware `throttle:api` | Laravel standard rate-limit message | `X-RateLimit-Limit` / `X-RateLimit-Remaining` | `backend/routes/api.php:147` → `Route::middleware(['auth:api', 'throttle:api'])->group(function () {`, limit at `backend/app/Providers/AppServiceProvider.php:157` → `RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('app.throttle_api', 120))->by(`, header list at `vendor/…/ThrottleRequests.php:308-309` → `'X-RateLimit-Limit' => $maxAttempts,` |

The discriminator is the `X-RateLimit-Limit` header: present ⇒ middleware,
absent ⇒ checkout quota. The checkout route is inside the auth+throttle group at
`backend/routes/api.php:163` →
`Route::post('/orders/checkout', [CheckoutController::class, 'checkout'])->name('api.orders.checkout');`

**There is a third, currently unused, limiter.** A named `checkout` limiter *is*
registered — `backend/app/Providers/AppServiceProvider.php:163` →
`RateLimiter::for('checkout', function (Request $request): array {` — but no
route applies it. That is pinned by a test, not by convention:
`backend/tests/Feature/CheckoutRateLimitTest.php:51` →
`$this->assertNotContains('throttle:checkout', $checkoutRoute->gatherMiddleware());`
and `:53` → `$this->assertNotContains('throttle:checkout', $ordersRoute->gatherMiddleware());`

Do **not** "fix" this by attaching the limiter: it would double-count against
the same three quotas, and the service is the intentional single enforcement
point (its counters are consumed only *after* route classification, which the
middleware cannot do).

**Reproducing command — who is currently over quota.** The `value` column of
the counter row is the running count, `expiration` the reset moment:

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT cache_key, value AS attempts, FROM_UNIXTIME(expiration) AS resets_at
FROM   cache
WHERE  (cache_key LIKE '%checkout-quota:user:%'
     OR cache_key LIKE '%checkout-quota-hour:ip:%'
     OR cache_key LIKE '%checkout-quota-day:ip:%')
AND    cache_key NOT LIKE '%:timer'
AND    CAST(value AS UNSIGNED) >= 5
ORDER  BY CAST(value AS UNSIGNED) DESC;
SQL
```

The `>= 5` filter is the lowest code-enforced limit
(`checkout_throttle_user_per_hour = 5`, `backend/config/app.php:146`); it is a
lower bound for "someone is near a 429", not an invented threshold. Read
`attempts` against the three limits: `> 5` user, `> 10` ip-hour, `> 31` ip-day
means that bucket is *already denying*.

`:timer` rows are excluded because `RateLimiter::hit()` stores the counter and
its window in two rows (`Illuminate\Cache\RateLimiter::hit` →
`return $this->increment($key, $decaySeconds);`,
`vendor/laravel/framework/src/Illuminate/Cache/RateLimiter.php:148-151`).

**Reproducing command — the boundary, without touching production.** The
enforced values and the exact first-denied attempt are pinned by tests:

```bash
cd backend && php artisan test --filter=CheckoutRateLimitTest
```

`backend/tests/Feature/CheckoutRateLimitTest.php:100-114`
(`test_checkout_user_hour_quota_returns_429_after_limit`) sets the user limit to
2 and asserts the **third** call is denied — which is the `> $userLimit`
comparison at `CheckoutRiskService.php:42` in test form.

**Operator action.**
1. A single user at `> 5` attempts/hour with `paid` orders: growth or a retry
   loop. Look at S2 first — a client that regenerates instead of replaying looks
   exactly like an attacker here and is not one.
2. An IP over the **day** limit (30) while the **hour** count is low → a slow,
   distributed single-IP run. This is the shape that the hour limit cannot see.
3. Rising `throttle:api` 429s *with* `X-RateLimit-Limit` present → the global
   API budget, not card testing. `API_THROTTLE_LIMIT` is 120 by default
   (`backend/config/app.php:142` → `'throttle_api' => (int) env('API_THROTTLE_LIMIT', 120),`),
   60 in `.env.example` and 1000 in `.env.ci`. Do not lower it in response to a
   checkout alert.
4. Any legitimate customer hitting a 429: the `Retry-After` value is the exact
   wait. Never delete a limiter row to "unstick" someone — the row is the audit
   trail for the current window; the bucket expires on its own.

### S4 — Failure velocity

**Where it is emitted.** Two places, and only after a fully verified failure.

The durable per-order telemetry is written in the locked transaction,
`backend/app/Http/Controllers/WebhookController.php:398-405`:

```php
$currentCount = max(0, (int) $lockedOrder->payment_failure_count);
$nextCount = min(255, $currentCount + 1);

$lockedOrder->update([
    'payment_failure_count' => $nextCount,
    'last_payment_failure_at' => now(),
    'last_payment_decline_code' => $declineCode,
]);
```

(`255` is the saturation ceiling; `min(255, $currentCount + 1)`. The column is
`unsignedSmallInteger` per `V036__card_testing_defenses.php:53-57`.)

The log line for one accepted failure is `WebhookController.php:414`:
`Log::warning('Stripe Webhook: card payment attempt failed', $context);`
and the context fields are built at `WebhookController.php:587-597`:
`'error_type' => $errorType,` / `'error_code' => $errorCode,` / `'decline_code' => $declineCode,`
— sanitized and capped at 64 chars by `sanitizeFailureCode()`
(`WebhookController.php:600-615`, `substr($candidate, 0, 64)`).

The rate-limit half is fed **after** the transaction succeeds,
`WebhookController.php:410-413`:

```php
if ($updatedOrder !== null) {
    // The locked order carries the persisted customer-IP snapshot.
    // Never derive failure velocity from the Stripe webhook ingress IP.
    $this->checkoutRisk->recordVerifiedPaymentFailure($updatedOrder);
```

and hits two buckets in `backend/app/Services/CheckoutRiskService.php:70` and
`:74` → `RateLimiter::hit(CheckoutKey::user($user, 'checkout-failure'), $window);` /
`RateLimiter::hit(CheckoutKey::ip($customerIp, 'checkout-failure'), $window);`

**Code-enforced bounds.** At **3** failures per user or **5** per IP inside the
window, the *next* checkout requires Turnstile
(`backend/app/Services/CheckoutRiskService.php:144-152`):

```php
$userThreshold = max(1, (int) config('app.turnstile_failure_user_threshold_per_hour', 3));
$ipThreshold = max(1, (int) config('app.turnstile_failure_ip_threshold_per_hour', 5));
```

Config at `backend/config/app.php:152-154`:
`'turnstile_failure_user_threshold_per_hour' => max(1, (int) env('TURNSTILE_FAILURE_USER_THRESHOLD_PER_HOUR', 3)),` /
`'turnstile_failure_ip_threshold_per_hour' => max(1, (int) env('TURNSTILE_FAILURE_IP_THRESHOLD_PER_HOUR', 5)),` /
`'turnstile_failure_window_seconds' => max(60, (int) env('TURNSTILE_FAILURE_WINDOW_SECONDS', 3600)),`

The window is `max(60, …)` (`CheckoutRiskService.php:162-165`), so the
**effective minimum window is 60 s** and the default is 3600 s. Read the
`expiration` column before assuming an hour.

**Reproducing command — live velocity counters, over the code-enforced bounds:**

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT cache_key, value AS failures, FROM_UNIXTIME(expiration) AS window_ends
FROM   cache
WHERE  (cache_key LIKE '%checkout-failure:user:%'
     OR cache_key LIKE '%checkout-failure:ip:%')
AND    cache_key NOT LIKE '%:timer'
AND    (cache_key LIKE '%:user:%' AND CAST(value AS UNSIGNED) >= 3
     OR cache_key LIKE '%:ip:%'   AND CAST(value AS UNSIGNED) >= 5)
ORDER  BY CAST(value AS UNSIGNED) DESC;
SQL
```

`3` and `5` are the code-enforced thresholds, not chosen alert values.

**Reproducing command — failure-code velocity (the Radar correlation axis), 24 h:**

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT last_payment_decline_code AS code,
       COUNT(*)                   AS orders,
       SUM(payment_failure_count) AS failures,
       COUNT(DISTINCT user_id)    AS distinct_users
FROM   orders
WHERE  last_payment_failure_at >= NOW() - INTERVAL 24 HOUR
GROUP  BY code
ORDER  BY failures DESC;
SQL
```

**Reproducing command — per-user spikes, 24 h:**

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT user_id, SUM(payment_failure_count) AS failures, COUNT(DISTINCT order_id) AS orders
FROM   orders
WHERE  last_payment_failure_at >= NOW() - INTERVAL 24 HOUR
GROUP  BY user_id
HAVING failures >= 3
ORDER  BY failures DESC;
SQL
```

`>= 3` is `turnstile_failure_user_threshold_per_hour`
(`backend/config/app.php:152`) applied over a day instead of an hour — a
deliberate, stated change of window, not a new threshold.

**Reproducing command — how many accepted failures reached the log at all:**

```bash
docker logs portal_backend --since 24h 2>&1 \
  | grep -c "Stripe Webhook: card payment attempt failed"
```

**Operator action.**
1. `distinct_users` high with a low `failures` per order → distributed
   card testing: many accounts, one decline code. This is the primary
   card-testing signature and belongs in Stripe Radar as well
   (`card-testing-protection.md` § "Stripe-side configuration").
2. One user with `payment_failure_count` far above the 255 saturation → a
   single card-testing loop against one account; the counter stops growing at
   255 (`WebhookController.php:399`), so treat `= 255` as "saturated, count is a
   lower bound", never as an exact total.
3. One dominant `last_payment_decline_code` across many users → a shared card /
   BIN list. Hand the code distribution to Radar; do not add it to application
   logic, and never surface issuer detail to the customer
   (`card-testing-protection.md` § 6.1).
4. Velocity counters rising while the log line count does not → the durable
   counter and the cache counter have diverged. Check the two warning lines
   below before assuming a bug.
5. Two counters exist and both mean "the counter could not be written/read":
   - `backend/app/Services/CheckoutRiskService.php:77` → `Log::warning('Checkout failure velocity counter unavailable', [`
   - `backend/app/Services/CheckoutRiskService.php:154` → `Log::warning('Checkout failure velocity lookup unavailable', [`
   Any occurrence means the velocity gate is **failing open** — the checkout
   proceeds without the challenge it should have required. That is an incident,
   not a warning: check the cache store first (§1.2).

### S5 — Webhook identity mismatch / quarantine

**First, the honest definition of "quarantine" in this codebase.** There is no
quarantine table, flag or queue. A mismatching event is *ignored*: the order
counters are not touched and a `Log::warning` with a machine-readable `reason`
is written. Prove the absence in one command:

```bash
grep -rniE 'quarant' backend/app | wc -l    # → 0
```

The word appears only in documentation (`card-testing-protection.md` § 2.3 and
§ 6). So the runbook signal is **the log line plus its `reason`**, and it is
transient — see §4.

**Where it is emitted — success path.** `backend/app/Http/Controllers/WebhookController.php:253-262`:

```php
$mismatch = $this->paymentReconciliation->identityMismatch($order, $paymentIntent, true);
if ($mismatch !== null) {
    Log::warning('Stripe Webhook: success identity mismatch', [
        'event_id' => $eventId,
        'order_id' => $orderId,
        'payment_intent_id' => …,
        'reason' => $mismatch,
    ]);

    return response()->json(['status' => 'ignored', 'reason' => $mismatch]);
}
```

A success event that passes identity but is refused for another reason lands in
`WebhookController.php:316-320`:
`Log::warning('Stripe Webhook: success transition was not applied', [ … 'reason' => $reason,`

**Where it is emitted — failure path.** `WebhookController.php:348-355`:

```php
$mismatch = $this->paymentReconciliation->identityMismatch($order, $paymentIntent, false);
if ($mismatch !== null) {
    Log::warning('Stripe Webhook: payment failure identity mismatch', [
        ...$context,
        'reason' => $mismatch,
    ]);
```

Note the third call, inside the locked transaction, at `WebhookController.php:383`:
`|| $this->paymentReconciliation->identityMismatch($lockedOrder, $paymentIntent, false) !== null) {`
— identity is re-checked under the row lock, so a race cannot slip a mismatch
into the counters.

**The reason vocabulary** (all from
`backend/app/Services/PaymentIntentReconciliationService.php`, all quoted):

| `reason` | Line | Quoted line |
|---|---|---|
| `obsolete_payment_intent` | `:171` | `return 'obsolete_payment_intent';` |
| `metadata_order_mismatch` | `:176` | `return 'metadata_order_mismatch';` |
| `metadata_key_mismatch` | `:190` | `'checkout_idempotency_key' => 'metadata_key_mismatch',` |
| `metadata_fingerprint_mismatch` | `:191` | `'checkout_fingerprint' => 'metadata_fingerprint_mismatch',` |
| `generation_mismatch` | `:192` | `'generation' => 'generation_mismatch',` |
| `user_mismatch` | `:193` | `'portal_user_id' => 'user_mismatch',` |
| `metadata_account_mismatch` | `:218` | `'account_created_at' => 'metadata_account_mismatch',` |
| `metadata_amount_mismatch` | `:219` / `:230` | `'amount_cents' => 'metadata_amount_mismatch',` / `return 'metadata_amount_mismatch';` |
| `metadata_currency_mismatch` | `:220` / `:234` | `'currency' => 'metadata_currency_mismatch',` / `return 'metadata_currency_mismatch';` |
| `amount_mismatch` | `:239` | `return 'amount_mismatch';` |
| `currency_mismatch` | `:243` | `return 'currency_mismatch';` |
| `underpaid` | `:249` | `return 'underpaid';` |
| `customer_mismatch` | `:254` | `return 'customer_mismatch';` |
| `order_not_pending` | `:73` / `:285` | `return ['status' => 'ignored', 'reason' => 'order_not_pending'];` |

Two reasons deserve operator attention beyond the rest:

- **`underpaid`** is the money guard, `PaymentIntentReconciliationService.php:246-250`:
  `if ($requireReceivedAmount) { $received = $this->valueInt(…); if ($received === null || $received < (int) $order->total_amount) { return 'underpaid'; } }`
  Any occurrence is a **payment incident**, not a fraud statistic. Verify the
  order was not granted access and reconcile manually.
- **`customer_mismatch`** checks that a Stripe Customer is not mapped to two
  portal users — `PaymentIntentReconciliationService.php:329-332`:
  `return ! User::query()->where('stripe_customer_id', $suppliedCustomer)->where('id', '!=', $order->user_id)->exists();`
  That is the single-user-per-Stripe-customer invariant of V036
  (`backend/database/migrations/V036__card_testing_defenses.php:29-33`,
  `$table->unique('stripe_customer_id');`). Repetition means the mapping column
  is being crossed — investigate the customer-mapping path, do not just count.

**The two dedupe/claim TTLs that bound this signal.**
`backend/app/Http/Controllers/WebhookController.php:34` →
`private const WEBHOOK_EVENT_CLAIM_TTL_SECONDS = 120;`
`:36` → `private const WEBHOOK_EVENT_DEDUPE_TTL_DAYS = 7;`
applied at `:512` → `Cache::put($key, 'processed', now()->addDays(self::WEBHOOK_EVENT_DEDUPE_TTL_DAYS));`
and `:38` → `private const WEBHOOK_MISSING_ID_COALESCE_SECONDS = 300;`
used at `:561-563` to collapse a repeated failure without an event id
(`$lastFailureAt->greaterThanOrEqualTo(now()->subSeconds(self::WEBHOOK_MISSING_ID_COALESCE_SECONDS)) && (string) $order->last_payment_decline_code === (string) $declineCode`).

**Reproducing command — mismatch rate by reason, last 24 h:**

```bash
docker logs portal_backend --since 24h 2>&1 \
  | grep -E "Stripe Webhook: (success|payment failure) identity mismatch" \
  | grep -oE '"reason":"[a-z_]+"' | sort | uniq -c | sort -rn
```

**Reproducing command — the durable residue of quarantined events.** A quarantined
event changes no order column, so the *only* durable trace of a PI that reached
the webhook with a conflicting identity is a `payment_intent.payment_failed`
that never incremented, or a success that never flipped the order to `paid`:

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT id, user_id, status, payment_failure_count, last_payment_failure_at,
       last_payment_decline_code, payment_intent_generation, created_at
FROM   orders
WHERE  status = 'pending_payment'
AND    stripe_payment_intent_id IS NOT NULL
AND    created_at < NOW() - INTERVAL 2 HOUR
ORDER  BY created_at;
SQL
```

The 2-hour bound is the stale-PI cutoff
(`backend/config/app.php:137` →
`'stale_payment_intent_hours' => max(1, (int) env('STRIPE_STALE_PAYMENT_INTENT_HOURS', 2)),`).
Anything still `pending_payment` past it will be swept by S6 — so run this query
**after** the sweep, and treat what remains as the quarantined residue.

**Reproducing command — signature rejections (a different attack, same file):**

```bash
docker logs portal_backend --since 24h 2>&1 \
  | grep -E "Stripe Webhook Error: (Invalid payload|Invalid signature across all configured secrets)" \
  | wc -l
```

Emitted at `WebhookController.php:94` and `:103`. A non-zero count with a
matching count of zero legitimate events is a forged-delivery attempt; a non-zero
count **during** a legitimate deploy is a webhook-secret rotation overlap — the
ingress accepts comma-separated secrets for that
(`WebhookController.php:82` → `$secrets = array_values(array_filter(array_map('trim', explode(',', (string) $endpointSecret))));`).

**Operator action.**
1. **Any** `underpaid` → money incident. Confirm the order is not `paid`, confirm
   no access was granted, and reconcile with Stripe before the customer is
   contacted.
2. `customer_mismatch` repeated on the same `order_id`/`payment_intent_id` →
   mapping defect or an attempt to attach someone else's PI to an order. Stop
   and investigate; do not retry the event.
3. `metadata_*` and `generation_mismatch` in a burst → a client that changed its
   cart between attempts while reusing an intent, i.e. exactly the S2
   `rejected mismatched` shape. Correlate S2 and S5 by `order_id`.
4. `obsolete_payment_intent` alone is usually benign (a late event for a
   replaced intent). In volume it is a replay storm — check the event-claim
   state next.
5. Signature rejections in volume → rotate the webhook secret and review
   `configured_secrets_count` in the log context
   (`WebhookController.php:105`). Never paste a signing secret into a ticket.

**Event-claim health — this signal depends on it.** Four warning lines indicate
the dedupe/claim machinery is degraded, and each one is a *replay* risk:

- `WebhookController.php:434` → `Log::warning('Stripe Webhook: event claim failed', [`
- `WebhookController.php:457` → `Log::warning('Stripe Webhook: event processing failed', [`
- `WebhookController.php:469` → `Log::warning('Stripe Webhook: retryable response claim release failed', [`
- `WebhookController.php:474` → `Log::warning('Stripe Webhook: retryable response left event claim open', [`
- `WebhookController.php:486` → `Log::warning('Stripe Webhook: event completion cache failed', [`

The design caveat is stated in the controller docblock
(`WebhookController.php:24-30`): `The app-cache event claim is a defense-in-depth
short-circuit that requires a shared cache store … a per-container cache degrades
it to at-least-once processing, which the durable guards absorb.`
Verify the store is shared before trusting any replay conclusion:

```bash
docker exec portal_backend php artisan tinker --execute="echo config('cache.default'), PHP_EOL;"
```

`database` (or `redis`/`memcached`/`dynamodb`) on **every** container is the
invariant. A `file` store here means the app cache claim is per-container and
replay telemetry is understated.

### S6 — Stale PaymentIntent sweep (cleanup counts)

**Where it is emitted.** The command is scheduled hourly —
`backend/routes/console.php:33`:
`Schedule::command('stripe:cancel-stale-payment-intents')->hourly()->withoutOverlapping()->onOneServer();`
and its signature is
`backend/app/Console/Commands/CancelStalePaymentIntents.php:16`:
`protected $signature = 'stripe:cancel-stale-payment-intents {--hours= : Override the configured stale age in hours} {--limit=500 : Maximum number of pending orders to inspect}';`

**Code-enforced bounds.**
- Stale age: `CancelStalePaymentIntents.php:32-34` →
  `$hours = $hoursOption === null ? (int) config('app.stripe.stale_payment_intent_hours', 2) : (int) $hoursOption;`
  config at `backend/config/app.php:137` (quoted in S5); a value `< 1` is
  rejected at `:36` → `if ($hours < 1) {`
- Selection window: `:57` → `$cutoff = now()->subHours($hours);` with
  `:60-66` selecting `status = 'pending_payment' AND created_at <= $cutoff`, ordered and
  capped by `--limit=500`.
- Only these remote states are cancelled, `CancelStalePaymentIntents.php:20-25`:
  `private const CANCELABLE_STATUSES = [ PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD, PaymentIntent::STATUS_REQUIRES_CONFIRMATION, PaymentIntent::STATUS_REQUIRES_ACTION, PaymentIntent::STATUS_REQUIRES_CAPTURE, ];`
  A remote success is reconciled **before** the age gate (`:142-143`).
- The one summary line, `CancelStalePaymentIntents.php:72-79`, whose format
  string is at `:73`:
  `'Stale PaymentIntent sweep complete: %d cancelled, %d reconciled, %d skipped, %d missing PI, %d failed.',`
  The command exits non-zero when `failed > 0` (`:81` →
  `return $counters['failed'] > 0 ? self::FAILURE : self::SUCCESS;`) — so a
  failing sweep is visible to the scheduler, not only in the log.

**Per-order log lines, all greppable by the `stripe.stale_payment_intent.` prefix:**

| Level | Line | Message |
|---|---|---|
| info | `:99` | `stripe.stale_payment_intent.missing_payment_intent` |
| info | `:115` | `stripe.stale_payment_intent.identity_changed_before_remote_work` |
| warning | `:127` | `stripe.stale_payment_intent.remote_identity_mismatch` |
| info | `:146` | `stripe.stale_payment_intent.succeeded_reconciled` |
| warning | `:153` | `stripe.stale_payment_intent.succeeded_reconciliation_retryable` |
| warning | `:161` | `stripe.stale_payment_intent.succeeded_identity_mismatch` |
| info | `:176` | `stripe.stale_payment_intent.fresh_or_unknown_age` |
| warning | `:200` | `stripe.stale_payment_intent.unexpected_cancel_status` |
| warning | `:213` | `stripe.stale_payment_intent.failed` |
| info | `:397` | `stripe.stale_payment_intent.identity_changed_before_local_update` |
| info | `:407` | `stripe.stale_payment_intent.cancelled` |

**Reproducing command — the summary line for every scheduled run, last 24 h:**

```bash
docker logs portal_backend --since 24h 2>&1 | grep "Stale PaymentIntent sweep complete"
```

**Reproducing command — per-outcome counts:**

```bash
docker logs portal_backend --since 24h 2>&1 \
  | grep -oE "stripe\.stale_payment_intent\.[a-z_]+" | sort | uniq -c | sort -rn
```

**Reproducing command — the run by hand, read-only equivalent.** Do **not** run
the sweep manually against production to "check" it: it cancels Stripe intents.
To see what it *would* touch, query the same selection:

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT COUNT(*) AS sweepable_now
FROM   orders
WHERE  status = 'pending_payment'
AND    created_at <= NOW() - INTERVAL 2 HOUR;
SQL
```

**Reproducing command — verify the schedule actually ran** (a missed hourly run
is itself an incident; `withoutOverlapping()` can silently skip):

```bash
docker logs portal_backend --since 6h 2>&1 | grep -c "Stale PaymentIntent sweep complete"
```

Six hours' expected count is 6. Below that, the scheduler or the worker is not
running — check the Laravel scheduler before concluding anything from the
counts.

**Operator action.**
1. `cancelled` climbing steadily while `new_payment_intents` (S1) is flat →
   abandoned carts, not an attack. Healthy.
2. `missing PI` climbing → orders without `stripe_payment_intent_id` are piling
   up; check the create path (S1's `Stripe payment failed for order …`).
3. `remote_identity_mismatch`, `succeeded_identity_mismatch` or
   `identity_changed_before_remote_work` → **the S5 signal from the other
   direction**: the remote PI does not belong to that order. Escalate as S5.
4. `unexpected_cancel_status`, `failed`, or a sweep exit code of 1 → the sweep
   could not complete; stale intents stay live at Stripe. Run the command
   manually once the cause is known, and treat the first run's output as the
   operator-visible summary.
5. `fresh_or_unknown_age` in volume → intents created recently but on old order
   rows. Benign after a client retry burst; correlate with S2's
   `regenerated_intents`.

### S7 — Account-age rejections

**Where it is emitted.** `backend/app/Services/CheckoutEligibilityService.php:12-19`:

```php
if ($user->password === null || $user->created_at === null) {
    throw $this->denied();
}

$minimumAgeHours = max(0, (int) config('app.stripe.checkout_new_account_hours', 24));
if ($user->created_at->isAfter(now()->subHours($minimumAgeHours))) {
    throw $this->denied();
}
```

**Code-enforced bound.** `backend/config/app.php:138`:
`'checkout_new_account_hours' => max(0, (int) env('STRIPE_CHECKOUT_NEW_ACCOUNT_HOURS', 24)),`
— floor 0, default **24 hours**. The response is a `403` with
`'error' => 'Der Account ist noch nicht lange genug aktiviert. Bitte versuche den Checkout in wenigen Minuten erneut.'`
(`CheckoutEligibilityService.php:25`).

Called on the immediate-Stripe path at
`backend/app/Services/CheckoutService.php:177` and `:316`
(`$this->checkoutEligibility->assertImmediateStripeAllowed($user);`).

**There is no log line** — see §1.4. This is therefore an *inference*, not a
measurement, and the runbook says so rather than inventing a counter.

**Reproducing command — the population that is currently inside the window
(i.e. every account whose next checkout will be rejected):**

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT COUNT(*) AS accounts_inside_window
FROM   users
WHERE  created_at > NOW() - INTERVAL 24 HOUR
AND    password IS NOT NULL;
SQL
```

The 24 h is the config default; substitute the deployed value, which you can
read without guessing:

```bash
docker exec portal_backend php artisan tinker --execute="echo config('app.stripe.checkout_new_account_hours'), PHP_EOL;"
```

**Reproducing command — does the age rule correlate with failures?** The join is
the closest thing to an alert that exists today:

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT u.id, u.created_at, o.id AS order_id, o.payment_failure_count, o.last_payment_decline_code
FROM   orders o
JOIN   users u ON u.id = o.user_id
WHERE  o.stripe_payment_intent_id IS NOT NULL
AND    u.created_at > NOW() - INTERVAL 24 HOUR
ORDER  BY o.created_at DESC
LIMIT  50;
SQL
```

If accounts inside the window still have orders with a PI, they got through a
path that is not the immediate-Stripe one, or the window is 0 in the deployed
config. Resolve that before drawing a conclusion.

**Operator action.**
1. A growing `accounts_inside_window` is normal sign-up behaviour, not an alert.
2. New accounts with `payment_failure_count > 0` inside the window → either the
   window is 0 in production, or the account is being driven by someone else.
   Confirm the deployed value first (§above), then escalate as S4.
3. Support reports "checkout says the account is too new" → this is a deliberate
   24-hour anti-card-testing hold, not a bug. Do not shorten
   `STRIPE_CHECKOUT_NEW_ACCOUNT_HOURS` for one customer; the floor is `0` and
   the value is global.

### S8 — Turnstile outcomes

**Where it is emitted.** Nowhere in the log. `TurnstileService` throws and
returns; the only observable traces are the counter keys and the HTTP response.

**Code-enforced bounds.**
- The gate is skipped entirely when either credential is missing —
  `backend/app/Services/TurnstileService.php:31-40` (`isEnabled()` returns
  `false`), which makes `CheckoutRiskService::assertImmediateStripeAllowed`
  return before touching any counter
  (`backend/app/Services/CheckoutRiskService.php:96-98`:
  `if (! $this->turnstile->isEnabled()) { return; }`). **If Turnstile is
  unconfigured, there is no per-attempt signal at all** — verify it is enabled
  before interpreting S8:
  ```bash
  docker exec portal_backend php artisan tinker --execute="var_export(\App\Services\TurnstileService::class); echo (new \App\Services\TurnstileService())->isEnabled() ? 'enabled' : 'disabled', PHP_EOL;"
  ```
- The challenge becomes mandatory at user attempt 3 / IP attempt 5
  (`CheckoutRiskService.php:109-118`), or immediately on the failure-velocity
  condition of S4:
  ```php
  if ($userAttempts < $userThreshold
      && $ipAttempts < $ipThreshold
      && ! $failureVelocityRequiresChallenge) {
      return;
  }
  ```
- Token bounds: `backend/app/Services/TurnstileService.php:16` →
  `private const MAX_TOKEN_LENGTH = 2048;` and `:18` → `private const CDATA_MAX_LENGTH = 32;`,
  bound server-side to `action = checkout` (`:14` → `private const ACTION = 'checkout';`)
  and checked after Siteverify at `:119-123`.
- Siteverify call bounds: `TurnstileService.php:85-88` →
  `Http::asForm()->connectTimeout(2)->timeout(3)->post(self::VERIFY_URL, $payload);`
  A connection failure yields `503`, an unsuccessful response yields `503`, and a
  non-`success` body yields `403` (`:89-107`, `:196-201`, `:188-194`).

**Reproducing command — how many challenges were required at all.** A
`checkout-risk-attempt` key exists only while an enabled Turnstile gate is being
counted, so its presence is the proxy for "the gate was live and this actor
reached it":

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT SUBSTRING_INDEX(cache_key, ':', 2) AS kind,
       COUNT(*)                          AS keys,
       MAX(CAST(value AS UNSIGNED))      AS max_attempts_seen,
       FROM_UNIXTIME(MAX(expiration))    AS last_window_end
FROM   cache
WHERE  cache_key LIKE '%checkout-risk-attempt%'
AND    cache_key NOT LIKE '%:timer'
GROUP  BY kind;
SQL
```

`max_attempts_seen` reaching **3** for a user key or **5** for an IP key means
that actor was challenged (`turnstile_user_threshold_per_hour = 3` /
`turnstile_ip_threshold_per_hour = 5`, `backend/config/app.php:150-151`).

**Reproducing command — how many failures forced a challenge** (the S4 gate,
seen from its own side):

```bash
docker exec -i portal_db sh -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<'SQL'
SELECT COUNT(*) AS failure_window_keys
FROM   cache
WHERE  cache_key LIKE '%checkout-failure%' AND cache_key NOT LIKE '%:timer';
SQL
```

**Operator action.**
1. A `503` burst with `checkout-risk-attempt` counters still moving → Siteverify
   is unreachable (or the credentials are wrong). This fails **closed** for
   checkout: legitimate customers cannot pay. Check
   `https://challenges.cloudflare.com/turnstile/v0/siteverify` reachability from
   inside the container before touching application config.
2. No `checkout-risk-attempt` keys at all while checkouts succeed → the gate is
   **disabled** (missing credential, or `isEnabled()` false). Verify with the
   `tinker` command above; this is a silent security regression.
3. A rise in 403s with `X-RateLimit` absent and the German body
   `Die Sicherheitsprüfung ist fehlgeschlagen. Bitte versuchen Sie es erneut.`
   → a widget/rendering problem on one host or a stale `cdata`
   (`checkout-{userId}`, `TurnstileService.php:49-62`), not an attack. Because
   the check is server-side on the Siteverify response
   (`TurnstileService.php:119-123`), a client-side workaround does not exist
   and must not be added.
4. Never log or export a raw token. `MAX_TOKEN_LENGTH` is 2048
   (`TurnstileService.php:16`); the app never persists it, and neither may an
   operator.

---

## 3. Escalation matrix

The **Signal** column names the runbook section; the **Trigger** column uses a
code-enforced number wherever one exists, and says so. Alert boundaries that
are *not* code-enforced are marked **[operator]** — they are starting points
tied to the enforced limit in the same row, not measurements.

| Signal | Trigger | Severity | First action |
|---|---|---|---|
| S5 `underpaid` | any single occurrence | **P1** | verify order not `paid`, no access granted, reconcile with Stripe |
| S5 `customer_mismatch` | > 1 occurrence, same `order_id` | **P1** | stop retries, inspect the Stripe-customer mapping |
| S4 velocity counters | `Checkout failure velocity counter unavailable` or `… lookup unavailable` | **P1** | the gate is failing open; check the cache store |
| S4 failure-code velocity | `distinct_users` high, `failures` per order 1 (`card-testing-protection.md` § "Stripe-side configuration") **[operator]** | **P2** | export the code distribution to Radar |
| S3 ip-day 429 | any IP with `attempts > 30` in `checkout-quota-day:ip:` | **P2** | cross-check S1/S4 for the same IP |
| S6 sweep health | fewer than 6 summary lines in 6 h, or any `failed` / exit 1 | **P2** | check the scheduler and the worker before re-running |
| S2 cancellation failure | `stale PaymentIntent cancellation failed` | **P2** | money path: the old intent is still live at Stripe |
| S8 Turnstile | `503` burst, or no `checkout-risk-attempt` keys while checkout works | **P2** | `isEnabled()` first, then reachability |
| S5 signature rejections | non-zero **and** legitimate events non-zero | **P2** | secret rotation overlap; review `configured_secrets_count` |
| S1 create failures | `Stripe payment failed for order` > 0 | **P3** | read the `exception_class` distribution before suspecting an attack |
| S3 single-user 429 | one user at `> 5`/hour, orders `paid` | **P3** **[operator]** | growth or client retry loop; check S2, do not lower a global limit |
| S7 account age | support reports, or new accounts with `payment_failure_count > 0` | **P3** | confirm the deployed `checkout_new_account_hours` before anything else |

Deliberately **not** an escalation: a 429 without the German checkout body. That
is the route middleware (S3, second row of the source table), i.e. the global API
budget, and lowering it in response to a checkout alert makes the real problem
invisible.

---

## 4. Not measurable — and why

Each item below was checked in the tree. Where the absence is re-runnable as a
command it is given so the statement can be re-verified instead of trusted
(item 4 carries one); the other items argue from quoted code, which is weaker —
that difference is stated, not hidden.

1. **Successful idempotent replays (S2).** `replay()` returns a
   `JsonResponse` and writes nothing; the only replay log lines are the
   rejection paths listed in S2. A successful replay leaves no row, no column
   and no log line — by design, because the whole point is that the second
   attempt is a no-op. **Consequence:** the replay *rate* requested by
   `card-testing-protection.md` cannot be computed from the application. Only
   its *rejections* are observable, and only inside the log window (§1.1).
   If a replay rate is genuinely needed, it requires a new counter — an
   application change, deliberately not made here.
2. **User/IP 429 counts (S3), missing-Turnstile 403s, failed-Turnstile 403s,
   Turnstile-outage 503s, account-age 403s.** Not logged, by the framework rule
   in §1.4. The rate-limit *state* is measurable (§1.2); the *number of
   denials* is not, and the two are different quantities. A counter would have
   to be added inside the throwing methods.
3. **Turnstile outcomes (S8).** Neither success, failure nor unavailability is
   written anywhere. `assertValid()` returns or throws
   (`TurnstileService.php:69-124`) with no log call, and the `reject*` methods
   (`:188-201`) are in `internalDontReport` (§1.4). Only the *challenge being
   required* is inferable, from the `checkout-risk-attempt` counter keys.
4. **"Quarantine" as a stored state (S5).** No table, column, flag or queue
   exists — `grep -rniE 'quarant' backend/app | wc -l` → `0`. A quarantined
   event is ignored and logged; the durable residue (an order that should have
   moved and did not) is what the S5 residue query surfaces. Anything claiming a
   quarantine *list* would be describing something the code does not do.
5. **Webhook replays across containers.** The claim store must be shared
   (`WebhookController.php:24-30` docblock). Whether it *is* shared is one
   `tinker` command (§S5) — but a **per-container** store makes duplicate
   deliveries invisible to the cache while the durable guards absorb them, so
   the replay count under that configuration is not a real count. There is no
   instrumentation that distinguishes "no replay happened" from "replay happened
   and was absorbed by the row lock".
6. **Historic reconstruction of any log signal.** `max-size: "10m"` with no
   `max-file` (`deployment/docker-compose.yml:87-90`) means rotation is
   size-based and single-generation. Once a line has rotated out it is gone;
   S1, S4, S5, S6 and S8 have no durable substitute. This is the strongest
   argument for shipping log output to a durable sink before relying on any
   alert in §3.
7. **Anything Stripe-internal.** Radar queues, block reasons, 3DS results and
   issuer detail are not in this repository and cannot be reproduced by any
   command here. `card-testing-protection.md` assigns them to the Stripe-side
   checklist; this runbook deliberately makes no claim about them.
8. **Per-actor attribution from cache keys (S3, S4, S8).** `CheckoutKey` is a
   keyed HMAC (`CheckoutKey.php:42` →
   `return hash_hmac('sha256', $value, $key);`) and is documented as
   deliberately non-reversible (`CheckoutKey.php:10` →
   ` * Raw IP addresses are evidence data and must not be persisted in cache keys.`).
   A `checkout-*:user:` digest cannot be turned back into a `users.id` by any
   command. Attribution
   must come from the `orders` columns (`user_id`, `ip_address`), which is why
   every actionable query in §2 starts there.

---

## 5. Log hygiene and privacy

Non-negotiable when following this runbook:

- **Never** copy a raw Turnstile token, a Stripe client secret, a `Stripe-Signature`
  header or a webhook signing secret into a ticket. The application does not log
  them; the operator must not add them. `MAX_TOKEN_LENGTH` is 2048
  (`TurnstileService.php:16`) and the app never persists a token — that property
  survives only if it is not re-introduced downstream.
- **Never** export `orders.ip_address` in bulk. It is the evidence snapshot
  (`card-testing-protection.md` § 10) and the trust anchor for the failure-velocity
  IP bucket (`CheckoutRiskService.php:72-74` → `$customerIp = trim((string) ($order->ip_address ?? ''));`).
  Run the aggregate queries in place; take single IPs into an investigation only.
- **Prefer the cache digests over raw identifiers** when a query is only asking
  "which namespace is hot" — the digests are enough for that and reveal nothing.
- The failure telemetry the app *does* keep is deliberately coarse and bounded —
  `payment_failure_count` saturating at 255 (`WebhookController.php:399`) and
  `last_payment_decline_code` capped at 64 characters
  (`WebhookController.php:610` → `return substr($candidate, 0, 64);`), with no
  raw Stripe error bodies (`sanitizeFailureCode`, `WebhookController.php:600-615`).
  Do not add columns that widen this.

---

## 6. Change log

- **2026-09-29** — Initial runbook. Covers S1–S8, the escalation matrix in §3,
  and the eight non-measurable items in §4. Every threshold is either quoted from
  the enforcing line or labelled **[operator]**.
