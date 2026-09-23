<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\BrandRegistry;
use App\Support\CheckoutKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    public function __construct(private readonly StripePaymentService $stripePayment)
    {
    }

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
        return Order::with('invoiceSnapshot')
            ->where('user_id', $user->getKey())
            ->where('checkout_idempotency_key', $key)
            ->whereIn('status', ['pending_payment', 'paid'])
            ->where('is_quote_request', false)
            ->where('total_amount', '>', 0)
            ->whereNotNull('checkout_fingerprint')
            ->first();
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
        $brand = BrandRegistry::current()?->value;
        $query = Order::with('invoiceSnapshot')
            ->where('user_id', $user->getKey())
            ->where('status', 'pending_payment')
            ->where('is_quote_request', false)
            ->where('total_amount', '>', 0)
            ->whereNotNull('checkout_idempotency_key')
            ->whereNotNull('checkout_fingerprint')
            ->latest('created_at')
            ->latest('id')
            ->limit(50);
        if ($brand === null) {
            $query->whereNull('brand');
        } else {
            $query->where('brand', $brand);
        }

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
                        return $creator(null);
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
     * @param  Closure(?Order): JsonResponse|null  $creator
     */
    private function resolveExistingOrder(
        Order $order,
        string $fingerprint,
        int $amountCents,
        ?Closure $creator,
    ): ?JsonResponse
    {
        if (! hash_equals((string) $order->checkout_fingerprint, $fingerprint)) {
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
        $lockKeys = [
            CheckoutKey::user($user->getKey().'|'.$key, 'checkout-idempotency-key'),
            CheckoutKey::user($user->getKey().'|'.$fingerprint, 'checkout-idempotency-fingerprint'),
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

    private function findOrderForIdentity(
        User $user,
        string $key,
        string $fingerprint,
        bool $allowFingerprintFallback,
    ): ?Order {
        $order = Order::with('invoiceSnapshot')
            ->where('user_id', $user->getKey())
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
        return Order::with('invoiceSnapshot')
            ->where('user_id', $user->getKey())
            ->where('checkout_fingerprint', $fingerprint)
            ->where('status', 'pending_payment')
            ->latest('created_at')
            ->latest('id')
            ->first();
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

        $canonical = [
            'user_id' => $user->getKey(),
            'brand' => BrandRegistry::current()?->value,
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
        if ($metadataAmount !== null && (!ctype_digit($metadataAmount) || (int) $metadataAmount !== $amountCents)) {
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
