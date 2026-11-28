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

class CheckoutIdempotencyService
{
    /**
     * One locked checkout operation can perform several sequential provider
     * requests (create, retrieve, cancel, resume). The provider HTTP client
     * defaults each request to 80 seconds, so keep the cache lease longer than
     * that complete call budget plus DB/queue overhead. Provider idempotency
     * and the order CAS remain the correctness backstops if a process is
     * terminated unexpectedly.
     */
    private const MAX_PROVIDER_REQUESTS_PER_IDENTITY_OPERATION = 4;

    private const PROVIDER_REQUEST_TIMEOUT_SECONDS = 80;

    private const IDENTITY_LOCK_TTL_MARGIN_SECONDS = 30;

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
     * Find only the exact-key, positive-value online order of one provider
     * that may safely be resumed before coupon revalidation. Invoice, quote,
     * free and legacy orders do not enter this fast path, and a provider never
     * resumes another provider's claim.
     */
    public function findPositiveOnlineOrderByKey(User $user, string $key, string $paymentMethod): ?Order
    {
        $query = Order::query()
            ->ownedBy($user)
            ->with('invoiceSnapshot')
            ->where('checkout_idempotency_key', $key)
            ->whereIn('status', ['pending_payment', 'paid'])
            ->where('is_quote_request', false)
            ->where('total_amount', '>', 0)
            ->whereNotNull('checkout_fingerprint');
        $this->scopeToProvider($query, $paymentMethod);
        // A positive immediate claim is actionable only inside the active
        // host brand. Legacy null-brand rows must not be promoted into a
        // current-brand replay; the resolver applies the same fail-closed rule
        // for exact-key and fingerprint-fallback paths.
        $this->scopeToActiveBrand($query);

        return $query->first();
    }

    /**
     * Find a positive online order of one provider by recomputing the
     * canonical fingerprint with each persisted server total.
     */
    public function findPositiveOnlineOrderByFingerprint(
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
        $this->scopeToProvider($query, $paymentMethod);

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

    /**
     * Resolve an exact-key non-immediate claim before coupon revalidation.
     *
     * The persisted server total is used only to reconstruct the canonical
     * fingerprint. This lets a retry of a finite/free coupon or invoice claim
     * succeed even when the coupon has since been exhausted or deactivated.
     * A positive immediate online claim is deliberately left to the immediate
     * checkout replay path.
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
        if ($candidate === null || $this->isImmediateOnlineClaim($candidate)) {
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
                    if ($order === null || $this->isImmediateOnlineClaim($order)) {
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
     * Execute a checkout that does not create a provider payment.
     *
     * Invoice, settled-free, delivery-note, and reactive-quote orders still
     * create exactly one Order + InvoiceSnapshot pair. They intentionally share
     * the V036 key/fingerprint namespace with the immediate online checkout, but
     * the resolver never contacts the payment provider and only resumes the
     * settlement transition when a previous request stopped before its final
     * response. A lost browser key is not recovered by fingerprint: exact-key
     * replay is the deliberate safe boundary for these non-immediate paths.
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
     * Execute a positive-value online checkout whose provider state lives in a
     * dedicated attempt ledger (Mollie).
     *
     * The order identity (owner, brand, key, fingerprint, persisted amount) is
     * the only replay boundary. How an existing pending order is resumed is
     * decided by the provider service, never by this resolver.
     *
     * @param  Closure(?Order): JsonResponse  $creator
     */
    public function executeOnline(
        User $user,
        string $key,
        string $fingerprint,
        int $amountCents,
        string $paymentMethod,
        Closure $creator,
        bool $allowFingerprintFallback = true,
    ): JsonResponse {
        try {
            return $this->withIdentityLocks(
                $user,
                $key,
                $fingerprint,
                function () use ($user, $key, $fingerprint, $amountCents, $paymentMethod, $creator, $allowFingerprintFallback): JsonResponse {
                    $order = $this->findOrderForIdentity($user, $key, $fingerprint, $allowFingerprintFallback);

                    if ($order === null) {
                        try {
                            return $creator(null);
                        } catch (UniqueConstraintViolationException $exception) {
                            $claimedOrder = $this->findOrderForIdentity($user, $key, $fingerprint, false);
                            if ($claimedOrder === null) {
                                if ($this->hasOwnedKeyOutsideCurrentBrand($user, $key)) {
                                    return $this->conflict('Dieser Checkout-Versuch gehört zu einer anderen Marke.');
                                }

                                throw $exception;
                            }

                            return $this->resolveOnlineOrder(
                                $claimedOrder,
                                $fingerprint,
                                $amountCents,
                                $paymentMethod,
                                $creator,
                            );
                        }
                    }

                    return $this->resolveOnlineOrder($order, $fingerprint, $amountCents, $paymentMethod, $creator);
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
    private function resolveOnlineOrder(
        Order $order,
        string $fingerprint,
        int $amountCents,
        string $paymentMethod,
        Closure $creator,
    ): JsonResponse {
        if (! $this->positiveOnlineOrderMatchesActiveBrand($order)
            || ! is_string($order->checkout_fingerprint)
            || ! hash_equals($order->checkout_fingerprint, $fingerprint)
            || (int) $order->total_amount !== $amountCents) {
            return $this->conflict('Dieser Checkout-Versuch wurde bereits mit anderen Daten verwendet.');
        }

        if ($order->status === 'paid') {
            return $this->successfulReplay($order);
        }

        if ($order->status !== 'pending_payment') {
            return $this->conflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb und versuche es erneut.');
        }

        // A Mollie attempt ledger belongs exclusively to the online checkout
        // state machine. A resume must never reinterpret another payment path's
        // claim as an invoice, delivery note, quote, or settled-free order.
        if ($paymentMethod !== 'mollie' && $order->molliePaymentAttempts()->exists()) {
            return $this->conflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb und versuche es erneut.');
        }

        return $creator($order);
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

        // A claim that owns a provider attempt belongs exclusively to the
        // online checkout state machine. Never reinterpret it as an invoice,
        // delivery note, quote, or settled-free order if the current org or
        // request classification changes while the browser key is reused.
        if ($order->molliePaymentAttempts()->exists()) {
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
            && in_array($order->status, ['pending_payment', 'invoice_created'], true)) {
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
     * Lock an incomplete free-order claim before its final paid transition.
     * The retry path needs the durable identity checks for the non-payment
     * free flow without ever reopening a closed or provider-backed order.
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
                || $lockedOrder->molliePaymentAttempts()->exists()
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
        return self::PROVIDER_REQUEST_TIMEOUT_SECONDS
            * self::MAX_PROVIDER_REQUESTS_PER_IDENTITY_OPERATION
            + self::IDENTITY_LOCK_TTL_MARGIN_SECONDS;
    }

    /**
     * Scope a positive immediate online lookup to the explicit active brand.
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

    /**
     * Scope a positive online lookup to the online checkout path. An unsupported
     * payment method never matches a stored claim.
     */
    private function scopeToProvider(Builder $query, string $paymentMethod): Builder
    {
        if ($paymentMethod === 'mollie') {
            // Mollie is the only online provider. The retired Stripe marker was
            // dropped in V054, and no other provider can create an online claim,
            // so every positive pending order is a Mollie claim. The Mollie
            // attempt ledger — not a stored provider id — separates the online
            // state machine from the non-immediate (invoice) scope below.
            return $query;
        }

        if ($paymentMethod === 'invoice') {
            // Mirror image for the non-immediate scope: an order that owns a
            // Mollie attempt stays in the online state machine.
            return $query->whereDoesntHave('molliePaymentAttempts');
        }

        return $query->whereRaw('1 = 0');
    }

    private function positiveOnlineOrderMatchesActiveBrand(Order $order): bool
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

    private function isImmediateOnlineClaim(Order $order): bool
    {
        if ((bool) $order->is_quote_request || (int) $order->total_amount <= 0) {
            return false;
        }

        return $order->status === 'pending_payment'
            || ($order->status === 'paid' && $order->molliePaymentAttempts()->exists());
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
        // reliable freshness signal: the online attempt may have been freshly
        // replaced long after the order was created. Keep the matching pending
        // order discoverable and let the provider service decide resume versus
        // replacement.
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
            'currency' => 'ea',
            'payment_method' => $paymentMethod,
        ];
        if ($paymentMethod === 'mollie') {
            // The customer's Mollie choice is part of the checkout identity: a
            // retry with another method must not resume a payment the customer
            // did not pick. Non-Mollie fingerprints stay byte-identical.
            $canonical['mollie_method'] = $request->input('mollie_method');
        }

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
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
