<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\ActorIdentity;
use App\Support\BrandRegistry;
use App\Support\CheckoutKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\HttpClient\CurlClient;

class CheckoutIdempotencyService
{
    /**
     * One locked checkout operation can perform four sequential Stripe
     * requests: retrieve, cancel, Customer creation, and PI creation. The
     * Stripe PHP SDK currently defaults each request to 80 seconds, so keep
     * the cache lease longer than that complete call budget plus DB/queue
     * overhead. Stripe idempotency and the order CAS remain the correctness
     * backstops if a process is terminated unexpectedly.
     */
    private const MAX_STRIPE_REQUESTS_PER_IDENTITY_OPERATION = 4;

    private const IDENTITY_LOCK_TTL_MARGIN_SECONDS = 30;

    public function __construct(private readonly StripePaymentService $stripePayment) {}

    /**
     * @return array{key: string, fingerprint: string}
     */
    public function identify(Request $request, User $user, ?int $amountCents = null, ?string $paymentMethod = null): array
    {
        $fingerprint = $this->fingerprint($request, $user, $amountCents, $paymentMethod);
        $providedKey = trim((string) $request->header('Idempotency-Key', ''));

        if ($providedKey !== '' && ! preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $providedKey)) {
            throw new HttpResponseException(response()->json([
                'error' => 'Der Idempotency-Key ist ungültig.',
            ], 422));
        }

        return [
            'key' => $providedKey !== '' ? $providedKey : 'auto-'.$fingerprint,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Find only the exact-key, positive-value immediate-Stripe order that may
     * safely be resumed before coupon revalidation. Invoice, quote, free and
     * legacy orders do not enter this fast path.
     */
    public function findPositiveStripeOrderByKey(User $user, string $key): ?Order
    {
        $query = Order::query()
            ->ownedBy($user)
            ->with('invoiceSnapshot')
            ->where('checkout_idempotency_key', $key)
            ->whereIn('status', ['pending_payment', 'paid'])
            ->where('is_quote_request', false)
            ->where('total_amount', '>', 0)
            ->whereNotNull('checkout_fingerprint');
        // A positive immediate claim is actionable only inside the active
        // host brand. Legacy null-brand rows must not be promoted into a
        // current-brand replay; the resolver applies the same fail-closed rule
        // for exact-key and fingerprint-fallback paths.
        $this->scopeToActiveBrand($query);

        return $query->first();
    }

    /**
     * Find a positive immediate-Stripe order by recomputing the canonical
     * fingerprint with each persisted server total. This deliberately never
     * trusts a client-supplied amount and is bounded before coupon/pricing
     * revalidation can block a lost-key recovery.
     */
    public function findPositiveStripeOrderByFingerprint(
        User $user,
        Request $request,
        string $paymentMethod,
    ): ?Order {
        $brand = BrandRegistry::currentIdOrNull();
        if ($brand === null) {
            return null;
        }

        $query = Order::query()
            ->ownedBy($user)
            ->with('invoiceSnapshot')
            ->where('status', 'pending_payment')
            ->where('is_quote_request', false)
            ->where('total_amount', '>', 0)
            ->whereNotNull('checkout_idempotency_key')
            ->whereNotNull('checkout_fingerprint')
            ->latest('created_at')
            ->latest('id')
            ->limit(50);
        $query->where('brand', $brand);

        foreach ($query->get() as $order) {
            $fingerprint = $this->fingerprint(
                $request,
                $user,
                (int) $order->total_amount,
                $paymentMethod,
            );
            if (hash_equals((string) $order->checkout_fingerprint, $fingerprint)) {
                return $order;
            }
        }

        return null;
    }

    public function replay(
        User $user,
        string $key,
        string $fingerprint,
        ?int $amountCents = null,
        bool $allowFingerprintFallback = true,
    ): ?JsonResponse {
        $order = $this->findOrderForIdentity($user, $key, $fingerprint, $allowFingerprintFallback);

        if ($order === null) {
            return null;
        }

        return $this->resolveExistingOrder(
            $order,
            $fingerprint,
            $amountCents ?? (int) $order->total_amount,
            null,
        );
    }

    /**
     * Resolve an exact-key non-immediate claim before coupon revalidation.
     *
     * The persisted server total is used only to reconstruct the canonical
     * fingerprint. This lets a retry of a finite/free coupon or invoice claim
     * succeed even when the coupon has since been exhausted or deactivated.
     * A positive PaymentIntent claim is deliberately left to the existing
     * immediate-Stripe replay path.
     *
     * @param  Closure(Order): JsonResponse  $finalizer
     */
    public function replayNonImmediateByKey(
        User $user,
        Request $request,
        string $paymentMethod,
        Closure $finalizer,
    ): ?JsonResponse {
        $providedKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($providedKey === '') {
            return null;
        }

        // Validate the opaque key before doing any exact-key lookup.
        $seed = $this->identify($request, $user, null, $paymentMethod);
        $candidate = $this->findOrderForIdentity($user, $seed['key'], '', false);
        if ($candidate === null || $this->isImmediateStripeClaim($candidate)) {
            return null;
        }

        $amountCents = (int) $candidate->total_amount;
        $identity = $this->identify($request, $user, $amountCents, $paymentMethod);

        try {
            return $this->withIdentityLocks(
                $user,
                $identity['key'],
                $identity['fingerprint'],
                function () use ($user, $identity, $finalizer): ?JsonResponse {
                    $order = $this->findOrderForIdentity($user, $identity['key'], $identity['fingerprint'], false);
                    if ($order === null || $this->isImmediateStripeClaim($order)) {
                        return null;
                    }

                    return $this->resolveNonImmediateExistingOrder(
                        $user,
                        $order,
                        $identity['key'],
                        $identity['fingerprint'],
                        (int) $order->total_amount,
                        function (?Order $resolvedOrder) use ($finalizer): JsonResponse {
                            if ($resolvedOrder === null) {
                                return response()->json([
                                    'error' => 'Der Checkout konnte nicht sicher fortgesetzt werden.',
                                ], 503);
                            }

                            try {
                                return $finalizer($resolvedOrder);
                            } catch (\Throwable $exception) {
                                Log::warning('Non-immediate checkout replay finalizer failed', [
                                    'order_id' => $resolvedOrder->id,
                                    'exception_class' => $exception::class,
                                ]);

                                return response()->json([
                                    'error' => 'Der Checkout konnte nicht abgeschlossen werden. Bitte versuche es erneut.',
                                ], 503);
                            }
                        },
                    );
                },
            );
        } catch (LockTimeoutException) {
            return response()->json([
                'error' => 'Ein identischer Checkout läuft bereits. Bitte versuche es in wenigen Sekunden erneut.',
            ], 409);
        }
    }

    /**
     * @param  Closure(?Order): JsonResponse  $creator
     */
    public function execute(
        User $user,
        string $key,
        string $fingerprint,
        int $amountCents,
        Closure $creator,
        bool $allowFingerprintFallback = true,
    ): JsonResponse {
        try {
            return $this->withIdentityLocks(
                $user,
                $key,
                $fingerprint,
                function () use ($user, $key, $fingerprint, $amountCents, $creator, $allowFingerprintFallback) {
                    $order = $this->findOrderForIdentity($user, $key, $fingerprint, $allowFingerprintFallback);

                    if ($order === null) {
                        try {
                            return $creator(null);
                        } catch (UniqueConstraintViolationException $exception) {
                            // A different worker can win the database unique
                            // constraint between the cache-lock lookup and the
                            // creator's order insert. Re-read while the identity
                            // lock is held, then apply the same brand/key/
                            // fingerprint/status rules as a normal replay.
                            $claimedOrder = $this->findOrderForIdentity($user, $key, $fingerprint, false);
                            if ($claimedOrder === null) {
                                if ($this->hasOwnedKeyOutsideCurrentBrand($user, $key)) {
                                    return $this->conflict('Dieser Checkout-Versuch gehört zu einer anderen Marke.');
                                }

                                throw $exception;
                            }

                            return $this->resolveExistingOrder(
                                $claimedOrder,
                                $fingerprint,
                                $amountCents,
                                $creator,
                            );
                        }
                    }

                    return $this->resolveExistingOrder($order, $fingerprint, $amountCents, $creator);
                },
            );
        } catch (LockTimeoutException) {
            return response()->json([
                'error' => 'Ein identischer Checkout läuft bereits. Bitte versuche es in wenigen Sekunden erneut.',
            ], 409);
        }
    }

    /**
     * Execute a checkout that does not create a PaymentIntent.
     *
     * Invoice, settled-free, delivery-note, and reactive-quote orders still
     * create exactly one Order + InvoiceSnapshot pair. They intentionally share
     * the V036 key/fingerprint namespace with immediate Stripe, but the
     * resolver never contacts Stripe and only resumes the settlement transition
     * when a previous request stopped before its final response. A lost browser
     * key is not recovered by fingerprint: exact-key replay is the deliberate
     * safe boundary for these non-immediate paths.
     *
     * @param  Closure(?Order): JsonResponse  $creator
     */
    public function executeNonImmediate(
        User $user,
        string $key,
        string $fingerprint,
        int $amountCents,
        Closure $creator,
        bool $allowFingerprintFallback = false,
    ): JsonResponse {
        try {
            return $this->withIdentityLocks(
                $user,
                $key,
                $fingerprint,
                function () use ($user, $key, $fingerprint, $amountCents, $creator, $allowFingerprintFallback): JsonResponse {
                    $order = $this->findOrderForIdentity($user, $key, $fingerprint, $allowFingerprintFallback);

                    if ($order !== null) {
                        return $this->resolveNonImmediateExistingOrder(
                            $user,
                            $order,
                            $key,
                            $fingerprint,
                            $amountCents,
                            $creator,
                        );
                    }

                    if ($this->hasOwnedKeyOutsideCurrentBrand($user, $key)) {
                        return $this->conflict('Dieser Checkout-Versuch gehört zu einer anderen Marke.');
                    }

                    try {
                        return $creator(null);
                    } catch (UniqueConstraintViolationException $exception) {
                        // A different worker can win the database unique
                        // constraint between the cache-lock lookup and the
                        // creator's insert. Re-read the claimed order while the
                        // identity lock is still held and resolve it through the
                        // same fingerprint/status rules.
                        $claimedOrder = $this->findOrderForIdentity($user, $key, $fingerprint, false);
                        if ($claimedOrder === null) {
                            if ($this->hasOwnedKeyOutsideCurrentBrand($user, $key)) {
                                return $this->conflict('Dieser Checkout-Versuch gehört zu einer anderen Marke.');
                            }

                            throw $exception;
                        }

                        return $this->resolveNonImmediateExistingOrder(
                            $user,
                            $claimedOrder,
                            $key,
                            $fingerprint,
                            $amountCents,
                            $creator,
                        );
                    }
                },
            );
        } catch (LockTimeoutException) {
            return response()->json([
                'error' => 'Ein identischer Checkout läuft bereits. Bitte versuche es in wenigen Sekunden erneut.',
            ], 409);
        }
    }

    /**
     * @param  Closure(?Order): JsonResponse  $creator
     */
    private function resolveNonImmediateExistingOrder(
        User $user,
        Order $order,
        string $key,
        string $fingerprint,
        int $amountCents,
        Closure $creator,
    ): JsonResponse {
        if (! ActorIdentity::ownsOrder($order, $user)
            || ! $this->orderMatchesCurrentBrand($order)
            || ! is_string($order->checkout_idempotency_key)
            || $order->checkout_idempotency_key !== $key
            || (int) $order->total_amount !== $amountCents
            || ! is_string($order->checkout_fingerprint)
            || $order->checkout_fingerprint === ''
            || ! hash_equals($order->checkout_fingerprint, $fingerprint)) {
            return $this->conflict('Dieser Checkout-Versuch wurde bereits mit anderen Daten verwendet.');
        }

        $order->loadMissing('invoiceSnapshot');

        // A claim that owns a PaymentIntent belongs exclusively to the
        // immediate-Stripe state machine. Never reinterpret it as an invoice,
        // delivery note, quote, or settled-free order if the current org or
        // request classification changes while the browser key is reused.
        if ($order->stripe_payment_intent_id !== null) {
            return $this->conflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb und versuche es erneut.');
        }

        if ((bool) $order->is_quote_request) {
            if ($order->status === 'pending') {
                return $creator($order);
            }

            return $this->conflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb und versuche es erneut.');
        }

        $isFreeOrder = (int) $order->total_amount === 0;

        // A free checkout creates its Order before the final conditional
        // pending_payment/invoice_created -> paid transition. If the process
        // failed between those writes, let the creator finish that transition
        // using the same order rather than inserting a second one.
        if ($isFreeOrder
            && in_array($order->status, ['pending_payment', 'invoice_created'], true)
            && $order->stripe_payment_intent_id === null) {
            $lockedOrder = $this->lockNonImmediateOrderForRetry(
                $user,
                $order,
                $key,
                $fingerprint,
                $amountCents,
            );
            if ($lockedOrder instanceof JsonResponse) {
                return $lockedOrder;
            }

            return $creator($lockedOrder);
        }

        if ($order->status === 'paid'
            || in_array($order->status, ['invoice_created', 'delivery_note'], true)) {
            // Re-run the idempotent finalizer for an existing claim. The
            // finalizer uses a durable order/snapshot and a mail enqueue
            // marker, so a transient failure after the DB commit can be
            // retried without creating another order or another enqueue. SMTP
            // delivery itself remains subject to queue-worker retries.
            return $creator($order);
        }

        return $this->conflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb und versuche es erneut.');
    }

    /**
     * @param  Closure(?Order): JsonResponse|null  $creator
     */
    private function resolveExistingOrder(
        Order $order,
        string $fingerprint,
        int $amountCents,
        ?Closure $creator,
    ): ?JsonResponse {
        if (! $this->positiveStripeOrderMatchesActiveBrand($order)
            || ! hash_equals((string) $order->checkout_fingerprint, $fingerprint)) {
            return $this->conflict('Dieser Checkout-Versuch wurde bereits mit anderen Daten verwendet.');
        }

        // A fingerprint fallback may be addressed with a new client key. That
        // key is only the current lookup handle; the persisted key/fingerprint
        // remain the audit identity for Stripe metadata and future exact-key
        // resolution.
        if ($order->status === 'paid') {
            return $this->successfulReplay($order);
        }

        if ($order->status !== 'pending_payment') {
            return $this->conflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb und versuche es erneut.');
        }

        if ($order->stripe_payment_intent_id === null) {
            if ($creator === null) {
                // The previous Stripe create may have timed out before the
                // order ID was persisted. Let execute() retry the same
                // generation with its deterministic idempotency key.
                return null;
            }

            $lockedOrder = $this->lockPendingOrderForRetry($order);
            if ($lockedOrder instanceof JsonResponse) {
                return $lockedOrder;
            }

            return $creator($lockedOrder);
        }

        try {
            $intent = $this->stripePayment->retrievePaymentIntent($order->stripe_payment_intent_id);
        } catch (\Throwable $exception) {
            Log::warning('Stripe idempotency replay could not retrieve PaymentIntent', [
                'order_id' => $order->id,
                'payment_intent_id' => $order->stripe_payment_intent_id,
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'error' => 'Der Zahlungsstatus konnte gerade nicht geprüft werden. Bitte versuche es in wenigen Sekunden erneut.',
            ], 502);
        }

        $status = (string) ($intent['status'] ?? '');
        if ($this->isPendingIntentStatus($status) && ! $this->hasCreatedTimestamp($intent)) {
            Log::warning('Stripe idempotency replay received a pending PaymentIntent without created timestamp', [
                'order_id' => $order->id,
                'payment_intent_id' => $intent['id'] ?? null,
                'status' => $status,
            ]);

            return response()->json([
                'error' => 'Der Zahlungsstatus konnte gerade nicht geprüft werden. Bitte versuche es in wenigen Sekunden erneut.',
            ], 502);
        }

        $replacementCandidate = $status === 'canceled'
            || ($this->isCancelable($status) && $this->isStale($intent, $status));

        if ($replacementCandidate) {
            if (! $this->canReplaceIntent($intent, $order, $amountCents, $status)) {
                Log::warning('Stripe idempotency replay rejected mismatched stale PaymentIntent', [
                    'order_id' => $order->id,
                    'payment_intent_id' => $intent['id'] ?? null,
                ]);

                return $this->conflict('Der gespeicherte Zahlungsvorgang passt nicht mehr zu diesem Checkout.');
            }

            if ($creator === null) {
                // Let execute() perform the locked cancellation and generation
                // replacement after the replay preflight has established that
                // the key/fingerprint still match.
                return null;
            }

            if ($this->isCancelable($status)) {
                try {
                    $canceledIntent = $this->stripePayment->cancelPaymentIntent($order->stripe_payment_intent_id);
                } catch (\Throwable $exception) {
                    Log::warning('Stripe stale PaymentIntent cancellation failed', [
                        'order_id' => $order->id,
                        'payment_intent_id' => $order->stripe_payment_intent_id,
                        'exception_class' => $exception::class,
                    ]);

                    return response()->json([
                        'error' => 'Der alte Zahlungsvorgang konnte nicht beendet werden. Bitte versuche es erneut.',
                    ], 502);
                }

                if (! $this->isValidCancellationResponse($canceledIntent, (string) $order->stripe_payment_intent_id)) {
                    Log::warning('Stripe stale PaymentIntent cancellation returned an invalid response', [
                        'order_id' => $order->id,
                        'payment_intent_id' => $order->stripe_payment_intent_id,
                    ]);

                    return response()->json([
                        'error' => 'Der alte Zahlungsvorgang konnte nicht beendet werden. Bitte versuche es erneut.',
                    ], 502);
                }
            }

            $replacementOrder = $this->prepareReplacement($order);
            if ($replacementOrder instanceof JsonResponse) {
                return $replacementOrder;
            }

            return $creator($replacementOrder);
        }

        if (! $this->intentMatchesOrder($intent, $order, $amountCents)) {
            Log::warning('Stripe idempotency replay rejected mismatched PaymentIntent', [
                'order_id' => $order->id,
                'payment_intent_id' => $intent['id'] ?? null,
            ]);

            return $this->conflict('Der gespeicherte Zahlungsvorgang passt nicht mehr zu diesem Checkout.');
        }

        if ($status === 'succeeded') {
            // A checkout replay is not a signed success webhook and has no
            // expanded fee evidence for the strict paid transition. Keep the
            // order recoverable, but never expose the fulfilled intent's
            // client secret as a new actionable payment.
            return $this->remotePaymentPendingResponse($order);
        }

        if ($this->isReusable($status) && ! $this->isStale($intent, $status)) {
            $clientSecret = $intent['client_secret'] ?? null;
            if (! is_string($clientSecret) || $clientSecret === '') {
                return $this->remotePaymentPendingResponse($order);
            }

            return $this->pendingResponse($order, $clientSecret);
        }

        return $this->conflict('Der Zahlungsvorgang ist in einem nicht wiederverwendbaren Status.');
    }

    /**
     * Lock both identity dimensions in a stable order: the browser key protects
     * same-key conflicts, while the fingerprint protects different lost keys
     * for the same server-priced cart.
     */
    private function withIdentityLocks(User $user, string $key, string $fingerprint, Closure $callback): mixed
    {
        $actorIdentifier = ActorIdentity::lockIdentifier($user);
        if ($actorIdentifier === null) {
            return response()->json([
                'error' => 'Der Checkout-Auftrag kann keinem Gast zugeordnet werden.',
            ], 403);
        }

        $brandScope = BrandRegistry::currentIdOrNull() ?? 'default';
        $lockKeys = [
            CheckoutKey::user($actorIdentifier.'|'.$brandScope.'|'.$key, 'checkout-idempotency-key'),
            CheckoutKey::user($actorIdentifier.'|'.$brandScope.'|'.$fingerprint, 'checkout-idempotency-fingerprint'),
        ];
        $lockKeys = array_values(array_unique($lockKeys));
        sort($lockKeys);

        $lockTtlSeconds = $this->identityLockTtlSeconds();
        $acquire = function (array $remaining) use (&$acquire, $callback, $lockTtlSeconds): mixed {
            if ($remaining === []) {
                return $callback();
            }

            $lock = Cache::lock((string) array_shift($remaining), $lockTtlSeconds);

            return $lock->block(5, fn (): mixed => $acquire($remaining));
        };

        return $acquire($lockKeys);
    }

    private function identityLockTtlSeconds(): int
    {
        return max(1, CurlClient::DEFAULT_TIMEOUT)
            * self::MAX_STRIPE_REQUESTS_PER_IDENTITY_OPERATION
            + self::IDENTITY_LOCK_TTL_MARGIN_SECONDS;
    }

    /**
     * Scope a positive immediate-Stripe lookup to the explicit active brand.
     *
     * A missing brand context is not an instruction to fall back to the
     * default brand for an actionable payment. Returning an empty query keeps
     * direct service callers fail-closed as well; only non-immediate legacy
     * resolution has a separately documented compatibility path.
     */
    private function scopeToActiveBrand(Builder $query): Builder
    {
        $currentBrand = BrandRegistry::currentIdOrNull();
        if ($currentBrand === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('brand', $currentBrand);
    }

    private function positiveStripeOrderMatchesActiveBrand(Order $order): bool
    {
        $currentBrand = BrandRegistry::currentIdOrNull();
        $orderBrand = BrandRegistry::normalizeId($order->brand);

        return $currentBrand !== null
            && $orderBrand !== null
            && hash_equals($currentBrand, $orderBrand);
    }

    private function orderMatchesCurrentBrand(Order $order): bool
    {
        $currentBrand = BrandRegistry::currentIdOrNull();
        $orderBrand = BrandRegistry::normalizeId($order->brand);

        if ($currentBrand !== null) {
            return $orderBrand === $currentBrand;
        }

        // Direct service callers in legacy tests/tools may not install a host
        // context. Preserve compatibility with both the default B2B order and
        // an explicitly ownerless fixture for the non-immediate resolver.
        return $orderBrand === null || $orderBrand === BrandRegistry::currentId();
    }

    private function hasOwnedKeyOutsideCurrentBrand(User $user, string $key): bool
    {
        $currentBrand = BrandRegistry::currentIdOrNull();
        if ($currentBrand === null) {
            return false;
        }

        // This is an explicit conflict probe, not a replay lookup. Include
        // null/foreign rows so a reused key cannot be mistaken for a new
        // checkout; the resolver will never treat them as active claims.
        return Order::query()
            ->ownedBy($user)
            ->where('checkout_idempotency_key', $key)
            ->where(function ($query) use ($currentBrand): void {
                $query->where('brand', '!=', $currentBrand)->orWhereNull('brand');
            })
            ->exists();
    }

    private function isImmediateStripeClaim(Order $order): bool
    {
        if ((bool) $order->is_quote_request || (int) $order->total_amount <= 0) {
            return false;
        }

        return $order->status === 'pending_payment'
            || ($order->status === 'paid' && $order->stripe_payment_intent_id !== null);
    }

    private function findOrderForIdentity(
        User $user,
        string $key,
        string $fingerprint,
        bool $allowFingerprintFallback,
    ): ?Order {
        // Exact-key candidates are deliberately loaded even when their brand
        // is null/foreign so the resolver can return a deterministic conflict
        // instead of allowing a same-key insert or a cross-brand replay.
        $order = Order::query()
            ->ownedBy($user)
            ->with('invoiceSnapshot')
            ->where('checkout_idempotency_key', $key)
            ->first();
        if ($order !== null) {
            return $order;
        }

        if (! $allowFingerprintFallback) {
            return null;
        }

        // A browser refresh can lose its opaque key, or a later request may
        // arrive with a newly generated key. The order row's age is not a
        // reliable freshness signal: a current PI may have been freshly
        // replaced long after the order was created. Keep the matching pending
        // order discoverable and let the remote PI timestamp/identity checks
        // decide reuse versus replacement.
        $query = Order::query()
            ->ownedBy($user)
            ->with('invoiceSnapshot')
            ->where('checkout_fingerprint', $fingerprint)
            ->where('status', 'pending_payment')
            ->latest('created_at')
            ->latest('id');
        $currentBrand = BrandRegistry::currentIdOrNull();
        if ($currentBrand === null) {
            return null;
        }

        // A fingerprint fallback is an order lookup, not a cross-brand or
        // legacy-null lookup. An explicit active brand is required before a
        // pending claim can be resumed.
        $query->where('brand', $currentBrand);

        return $query->first();
    }

    private function fingerprint(Request $request, User $user, ?int $amountCents, ?string $paymentMethod = null): string
    {
        $items = collect($request->input('items', []))
            ->map(function (array $item): array {
                $modifierIds = $item['modifierIds'] ?? [];
                sort($modifierIds);

                return [
                    'photoId' => $item['photoId'] ?? null,
                    'tier' => $item['tier'] ?? null,
                    'useCaseId' => $item['useCaseId'] ?? null,
                    'modifierIds' => array_values($modifierIds),
                    'isQuote' => (bool) ($item['isQuote'] ?? false),
                    'notes' => $item['notes'] ?? null,
                ];
            })
            ->sortBy(fn (array $item): string => (string) $item['photoId'])
            ->values()
            ->all();

        $ownerIdentity = ActorIdentity::isRegistered($user)
            ? ['user_id' => $user->getKey()]
            : ['guest_id' => ActorIdentity::guestId($user)];

        $canonical = [
            ...$ownerIdentity,
            'brand' => BrandRegistry::currentIdOrNull(),
            'items' => $items,
            'billing_name' => $request->input('billing_name'),
            'billing_company' => $request->input('billing_company'),
            'billing_street' => $request->input('billing_street'),
            'billing_zip' => $request->input('billing_zip'),
            'billing_city' => $request->input('billing_city'),
            'quote_token' => $request->input('quote_token'),
            'quote_message' => $request->input('quote_message'),
            'coupon_code' => $request->input('coupon_code'),
            'withdrawal_waived' => (bool) $request->boolean('withdrawal_waived'),
            // The amount is calculated by the server after coupon/offer pricing.
            // A client-supplied amount is never part of the identity input.
            'amount_cents' => $amountCents,
            'currency' => 'eur',
            'payment_method' => $paymentMethod,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function intentMatchesOrder(array $intent, Order $order, int $amountCents): bool
    {
        $metadata = $intent['metadata'] ?? [];
        $metadataAmount = $this->metadataStringValue($metadata, 'amount_cents');
        $metadataCurrency = $this->metadataStringValue($metadata, 'currency');
        $metadataOrderId = $this->metadataStringValue($metadata, 'order_id');
        $metadataPortalUserId = $this->metadataStringValue($metadata, 'portal_user_id');
        $metadataAccountCreatedAt = $this->metadataStringValue($metadata, 'account_created_at');
        $metadataKey = $this->metadataStringValue($metadata, 'checkout_idempotency_key');
        $metadataFingerprint = $this->metadataStringValue($metadata, 'checkout_fingerprint');
        $metadataGeneration = $this->metadataStringValue($metadata, 'generation');
        $legacyOrder = $this->isLegacyOrder($order);
        $hasMetadata = $metadataOrderId !== null
            || $metadataAmount !== null
            || $metadataCurrency !== null
            || $metadataPortalUserId !== null
            || $metadataAccountCreatedAt !== null
            || $metadataKey !== null
            || $metadataFingerprint !== null
            || $metadataGeneration !== null;

        $intentAmount = $intent['amount'] ?? null;
        if ((string) ($intent['id'] ?? '') !== (string) $order->stripe_payment_intent_id
            || ! $this->isIntegerValue($intentAmount)
            || (int) $intentAmount !== $amountCents
            || ! is_string($intent['currency'] ?? null)
            || strtolower(trim((string) $intent['currency'])) !== 'eur'
            || ($metadataAmount !== null && (! ctype_digit($metadataAmount) || (int) $metadataAmount !== $amountCents))
            || ($metadataCurrency !== null && strtolower($metadataCurrency) !== 'eur')) {
            return false;
        }

        if (! $hasMetadata) {
            return $legacyOrder
                && $this->replayCustomerMatches($order, $intent['customer'] ?? null, true);
        }
        if ($metadataOrderId !== (string) $order->getKey()) {
            return false;
        }

        $expected = [
            'checkout_idempotency_key' => $order->checkout_idempotency_key,
            'checkout_fingerprint' => $order->checkout_fingerprint,
            'generation' => (string) $order->payment_intent_generation,
            'portal_user_id' => (string) $order->user_id,
            'account_created_at' => $order->user?->created_at?->getTimestamp() === null
                ? null
                : (string) $order->user->created_at->getTimestamp(),
        ];
        $actual = [
            'checkout_idempotency_key' => $metadataKey,
            'checkout_fingerprint' => $metadataFingerprint,
            'generation' => $metadataGeneration,
            'portal_user_id' => $metadataPortalUserId,
            'account_created_at' => $metadataAccountCreatedAt,
        ];
        foreach ($expected as $key => $expectedValue) {
            $actualValue = $actual[$key];
            if ($legacyOrder) {
                if ($actualValue !== null && $actualValue !== (string) $expectedValue) {
                    return false;
                }

                continue;
            }
            if ($expectedValue === null || $actualValue === null || $actualValue !== (string) $expectedValue) {
                return false;
            }
        }

        if (! $legacyOrder && ($metadataAmount === null || $metadataCurrency === null)) {
            return false;
        }

        return $this->replayCustomerMatches($order, $intent['customer'] ?? null, $legacyOrder);
    }

    private function replayCustomerMatches(Order $order, mixed $customer, bool $legacyOrder): bool
    {
        $mappedCustomer = $order->user?->stripe_customer_id;
        $mappedCustomer = is_string($mappedCustomer) && $mappedCustomer !== ''
            ? $mappedCustomer
            : null;
        $suppliedCustomer = $this->customerId($customer);

        if ($legacyOrder) {
            if ($suppliedCustomer === null) {
                return true;
            }

            return $mappedCustomer !== null
                && hash_equals($mappedCustomer, $suppliedCustomer)
                && ! User::query()
                    ->where('stripe_customer_id', $suppliedCustomer)
                    ->where('id', '!=', $order->user_id)
                    ->exists();
        }

        if ($mappedCustomer === null) {
            return $suppliedCustomer === null && ! app()->environment('production');
        }
        if ($suppliedCustomer === null || ! hash_equals($mappedCustomer, $suppliedCustomer)) {
            return false;
        }

        return ! User::query()
            ->where('stripe_customer_id', $suppliedCustomer)
            ->where('id', '!=', $order->user_id)
            ->exists();
    }

    private function lockPendingOrderForRetry(Order $order): Order|JsonResponse
    {
        $expectedGeneration = (int) $order->payment_intent_generation;
        $result = DB::transaction(function () use ($order, $expectedGeneration): array {
            $lockedOrder = Order::query()
                ->with('invoiceSnapshot')
                ->lockForUpdate()
                ->find($order->getKey());

            if ($lockedOrder === null) {
                return ['response' => $this->conflict('Der Checkout existiert nicht mehr.')];
            }
            if ($lockedOrder->status === 'paid') {
                return ['response' => $this->successfulReplay($lockedOrder)];
            }
            if ($lockedOrder->status !== 'pending_payment'
                || $lockedOrder->stripe_payment_intent_id !== null
                || (int) $lockedOrder->payment_intent_generation !== $expectedGeneration) {
                return ['response' => $this->conflict('Der Checkout hat sich zwischenzeitlich geändert.')];
            }

            return ['order' => $lockedOrder];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }

        return $result['order'];
    }

    /**
     * Lock an incomplete free-order claim before its final paid transition.
     * The generic Stripe retry lock intentionally rejects invoice_created rows;
     * this path needs the same durable identity checks for the non-PaymentIntent
     * free flow without ever reopening a closed or PI-backed order.
     */
    private function lockNonImmediateOrderForRetry(
        User $user,
        Order $order,
        string $key,
        string $fingerprint,
        int $amountCents,
    ): Order|JsonResponse {
        $result = DB::transaction(function () use ($user, $order, $key, $fingerprint, $amountCents): array {
            $lockedOrder = Order::query()
                ->with('invoiceSnapshot')
                ->lockForUpdate()
                ->find($order->getKey());

            if ($lockedOrder === null) {
                return ['response' => $this->conflict('Der Checkout existiert nicht mehr.')];
            }

            if (! ActorIdentity::ownsOrder($lockedOrder, $user)
                || ! $this->orderMatchesCurrentBrand($lockedOrder)
                || ! is_string($lockedOrder->checkout_idempotency_key)
                || $lockedOrder->checkout_idempotency_key !== $key
                || (int) $lockedOrder->total_amount !== $amountCents
                || ! is_string($lockedOrder->checkout_fingerprint)
                || $lockedOrder->checkout_fingerprint === ''
                || ! hash_equals($lockedOrder->checkout_fingerprint, $fingerprint)) {
                return ['response' => $this->conflict('Dieser Checkout-Versuch wurde bereits mit anderen Daten verwendet.')];
            }

            $lockedOrder->loadMissing('invoiceSnapshot');

            // A concurrent finalizer may have completed the claim while this
            // request was waiting for the row lock. Return the durable order so
            // the caller can produce the same response and retry mail enqueue
            // safely; SMTP delivery itself remains worker-managed.
            if ($lockedOrder->status === 'paid' || $lockedOrder->status === 'delivery_note') {
                return ['order' => $lockedOrder];
            }

            if ((bool) $lockedOrder->is_quote_request
                || (int) $lockedOrder->total_amount !== 0
                || $lockedOrder->stripe_payment_intent_id !== null
                || ! in_array($lockedOrder->status, ['pending_payment', 'invoice_created'], true)) {
                return ['response' => $this->conflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb und versuche es erneut.')];
            }

            return ['order' => $lockedOrder];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }

        return $result['order'];
    }

    private function prepareReplacement(Order $order): Order|JsonResponse
    {
        $expectedPaymentIntentId = (string) $order->stripe_payment_intent_id;
        $expectedGeneration = (int) $order->payment_intent_generation;
        $result = DB::transaction(function () use ($order, $expectedPaymentIntentId, $expectedGeneration): array {
            $lockedOrder = Order::query()
                ->with('invoiceSnapshot')
                ->lockForUpdate()
                ->find($order->getKey());

            if ($lockedOrder === null) {
                return ['response' => $this->conflict('Der Checkout existiert nicht mehr.')];
            }
            if ($lockedOrder->status === 'paid') {
                return ['response' => $this->successfulReplay($lockedOrder)];
            }
            if ($lockedOrder->status !== 'pending_payment'
                || (string) $lockedOrder->stripe_payment_intent_id !== $expectedPaymentIntentId
                || (int) $lockedOrder->payment_intent_generation !== $expectedGeneration) {
                return ['response' => $this->conflict('Der Checkout hat sich zwischenzeitlich geändert.')];
            }

            $updated = Order::query()
                ->whereKey($lockedOrder->getKey())
                ->where('status', 'pending_payment')
                ->where('stripe_payment_intent_id', $expectedPaymentIntentId)
                ->where('payment_intent_generation', $expectedGeneration)
                ->update([
                    'payment_intent_generation' => $expectedGeneration + 1,
                    'stripe_payment_intent_id' => null,
                ]);
            if ($updated !== 1) {
                return ['response' => $this->conflict('Der Checkout hat sich zwischenzeitlich geändert.')];
            }

            $lockedOrder->refresh();
            $lockedOrder->loadMissing('invoiceSnapshot');

            return ['order' => $lockedOrder];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }

        return $result['order'];
    }

    private function isValidCancellationResponse(mixed $response, string $expectedPaymentIntentId): bool
    {
        return is_array($response)
            && (string) ($response['id'] ?? '') === $expectedPaymentIntentId
            && ($response['status'] ?? null) === 'canceled';
    }

    private function isPendingIntentStatus(string $status): bool
    {
        return $this->isReusable($status);
    }

    private function hasCreatedTimestamp(array $intent): bool
    {
        $created = $intent['created'] ?? null;

        return is_numeric($created) && (int) $created > 0;
    }

    private function isLegacyOrder(Order $order): bool
    {
        return $order->checkout_idempotency_key === null
            && $order->checkout_fingerprint === null;
    }

    private function canReplaceIntent(array $intent, Order $order, int $amountCents, string $status): bool
    {
        if ((string) ($intent['id'] ?? '') !== (string) $order->stripe_payment_intent_id
            || ! $this->isIntegerValue($intent['amount'] ?? null)
            || (int) $intent['amount'] !== $amountCents
            || ! is_string($intent['currency'] ?? null)
            || strtolower(trim((string) $intent['currency'])) !== 'eur') {
            return false;
        }

        if ($status !== 'canceled'
            && ! ($this->isCancelable($status) && $this->isStale($intent, $status))) {
            return false;
        }

        $metadata = $intent['metadata'] ?? [];
        $expectedMetadata = [
            'order_id' => (string) $order->getKey(),
            'checkout_idempotency_key' => $order->checkout_idempotency_key,
            'checkout_fingerprint' => $order->checkout_fingerprint,
            'generation' => (string) $order->payment_intent_generation,
            'portal_user_id' => (string) $order->user_id,
            'account_created_at' => $order->user?->created_at?->getTimestamp() === null
                ? null
                : (string) $order->user->created_at->getTimestamp(),
        ];
        $legacyOrder = $this->isLegacyOrder($order);
        foreach ($expectedMetadata as $key => $expected) {
            $actual = $this->metadataStringValue($metadata, $key);
            if ($legacyOrder) {
                if ($actual !== null && $actual !== (string) $expected) {
                    return false;
                }

                continue;
            }
            if ($expected === null || $actual === null || $actual !== (string) $expected) {
                return false;
            }
        }

        $metadataAmount = $this->metadataStringValue($metadata, 'amount_cents');
        $metadataCurrency = $this->metadataStringValue($metadata, 'currency');
        if (! $legacyOrder
            && ($metadataAmount === null
                || $metadataCurrency === null
                || ! ctype_digit($metadataAmount)
                || (int) $metadataAmount !== $amountCents
                || strtolower($metadataCurrency) !== 'eur')) {
            return false;
        }
        if ($metadataAmount !== null && (! ctype_digit($metadataAmount) || (int) $metadataAmount !== $amountCents)) {
            return false;
        }
        if ($metadataCurrency !== null && strtolower($metadataCurrency) !== 'eur') {
            return false;
        }

        return $this->replayCustomerMatches($order, $intent['customer'] ?? null, $legacyOrder);
    }

    private function metadataStringValue(mixed $metadata, string $key): ?string
    {
        $value = null;
        if (is_array($metadata)) {
            $value = $metadata[$key] ?? null;
        } elseif ($metadata instanceof \ArrayAccess && $metadata->offsetExists($key)) {
            $value = $metadata[$key];
        } elseif (is_object($metadata) && isset($metadata->{$key})) {
            $value = $metadata->{$key};
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isIntegerValue(mixed $value): bool
    {
        return is_int($value)
            || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1);
    }

    private function customerId(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        } elseif ($value instanceof \ArrayAccess && $value->offsetExists('id')) {
            $value = $value['id'];
        } elseif (is_object($value) && isset($value->id)) {
            $value = $value->id;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isReusable(string $status): bool
    {
        return in_array($status, [
            'requires_payment_method',
            'requires_confirmation',
            'requires_action',
            'processing',
            'requires_capture',
        ], true);
    }

    private function isCancelable(string $status): bool
    {
        return in_array($status, [
            'requires_payment_method',
            'requires_confirmation',
            'requires_action',
            'requires_capture',
        ], true);
    }

    private function isStale(array $intent, string $status): bool
    {
        if (! $this->isCancelable($status)) {
            return false;
        }

        // A missing Stripe timestamp is not evidence of staleness. The caller
        // returns a retryable 502 before replacement when a pending intent has
        // no authoritative created timestamp.
        if (! $this->hasCreatedTimestamp($intent)) {
            return false;
        }

        $ttlMinutes = max(5, (int) config('app.checkout_idempotency_ttl_minutes', 30));
        $createdAt = (int) $intent['created'];

        return $createdAt < now()->subMinutes($ttlMinutes)->getTimestamp();
    }

    private function remotePaymentPendingResponse(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'payment_pending' => true,
            'requires_action' => false,
            'order_id' => $order->id,
            'invoice_number' => $order->invoiceSnapshot?->invoice_number,
            'status' => 'pending_payment',
            'poll_url' => '/api/orders/'.$order->getKey(),
        ]);
    }

    private function pendingResponse(Order $order, string $clientSecret): JsonResponse
    {
        return response()->json([
            'success' => true,
            'requires_action' => true,
            'client_secret' => $clientSecret,
            'order_id' => $order->id,
            'invoice_number' => $order->invoiceSnapshot?->invoice_number,
        ]);
    }

    private function successfulReplay(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'invoice_number' => $order->invoiceSnapshot?->invoice_number,
        ]);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json([
            'error' => $message,
            'idempotency_conflict' => true,
        ], 409);
    }
}
