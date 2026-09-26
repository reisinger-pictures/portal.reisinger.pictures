<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CheckoutRiskService;
use App\Services\DisputeMailDispatcher;
use App\Services\PaymentIntentReconciliationService;
use App\Services\StripePaymentService;
use App\Support\ActorIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe webhook ingress.
 *
 * Replay tolerance is layered: the durable database compare-and-swap on the
 * order status (and the invoice-snapshot mail claim) is the real money/access
 * guard. The app-cache event claim is a defense-in-depth short-circuit that
 * requires a shared cache store (database/redis/memcached/dynamodb) to
 * deduplicate concurrent duplicates across containers; a per-container cache
 * degrades it to at-least-once processing, which the durable guards absorb.
 * The shared-cache requirement for webhook containers is currently enforced
 * only indirectly (see ProductionOperationsPolicy scheduler rule) and should
 * be promoted to an explicit production invariant.
 */
class WebhookController extends Controller
{
    private const WEBHOOK_EVENT_CLAIM_TTL_SECONDS = 120;

    private const WEBHOOK_EVENT_DEDUPE_TTL_DAYS = 7;

    private const WEBHOOK_MISSING_ID_COALESCE_SECONDS = 300;

    private StripePaymentService $stripePayment;

    private CheckoutRiskService $checkoutRisk;

    private PaymentIntentReconciliationService $paymentReconciliation;

    private DisputeMailDispatcher $disputeMailDispatcher;

    public function __construct(
        ?StripePaymentService $stripePayment = null,
        ?CheckoutRiskService $checkoutRisk = null,
        ?PaymentIntentReconciliationService $paymentReconciliation = null,
        ?DisputeMailDispatcher $disputeMailDispatcher = null,
    ) {
        $this->stripePayment = $stripePayment ?? app(StripePaymentService::class);
        $this->checkoutRisk = $checkoutRisk ?? app(CheckoutRiskService::class);
        $this->paymentReconciliation = $paymentReconciliation
            ?? app(PaymentIntentReconciliationService::class);
        $this->disputeMailDispatcher = $disputeMailDispatcher
            ?? app(DisputeMailDispatcher::class);
    }

    public function handleStripe(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        // Local development fallback: read the live secret from the
        // auto-tunneler's private file without ever logging its contents.
        // This artifact only exists for local/tunnel workflows; it must never
        // become an accepted signing secret on a production-like host with an
        // unset env var.
        $secretFile = storage_path('app/private/stripe_secret.txt');
        if (empty($endpointSecret)
            && app()->environment(['local', 'testing'])
            && file_exists($secretFile)
        ) {
            $endpointSecret = trim(file_get_contents($secretFile));
        }

        // Accept comma-separated secrets for multi-domain infrastructure.
        $secrets = array_values(array_filter(array_map('trim', explode(',', (string) $endpointSecret))));
        $event = null;
        $lastException = null;

        foreach ($secrets as $secret) {
            try {
                $event = Webhook::constructEvent($payload, $sigHeader, $secret);
                $lastException = null;
                break;
            } catch (SignatureVerificationException $exception) {
                $lastException = $exception;
            } catch (\UnexpectedValueException $exception) {
                Log::error('Stripe Webhook Error: Invalid payload', [
                    'exception_class' => $exception::class,
                ]);

                return response()->json(['error' => 'Invalid payload'], 400);
            }
        }

        if ($lastException || ! $event) {
            Log::error('Stripe Webhook Error: Invalid signature across all configured secrets', [
                'exception_class' => $lastException ? $lastException::class : null,
                'configured_secrets_count' => count($secrets),
            ]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $eventType = $this->valueString($this->objectValue($event, 'type'));
        if ($eventType === 'payment_intent.succeeded') {
            return $this->handlePaymentIntentSucceeded($event);
        }

        if ($eventType === 'payment_intent.payment_failed') {
            return $this->recordPaymentFailure($event);
        } elseif ($eventType === 'charge.dispute.created') {
            $dispute = $this->eventObject($event);

            return $this->withWebhookEventClaim(
                $this->webhookEventKey($this->eventId($event), $dispute, 'charge.dispute.created'),
                function () use ($dispute): JsonResponse {
                    $piId = $this->customerOrPaymentIntentId($dispute, 'payment_intent');
                    if ($piId === null) {
                        Log::warning('Webhook: dispute with null payment_intent, skipping', [
                            'dispute_id' => $this->valueString($this->objectValue($dispute, 'id')),
                        ]);

                        return response()->json(['status' => 'success']);
                    }

                    $order = Order::where('stripe_payment_intent_id', $piId)->first();
                    if ($order === null) {
                        return response()->json(['status' => 'success']);
                    }

                    $disputedOrder = $this->transitionTerminalPaymentStatus($order, 'disputed');

                    // A late event may be ignored, but any stale positive cache
                    // is still unsafe to retain for a negative payment signal.
                    $this->clearPurchasedCache($order);

                    // The status transition is a lifecycle guard, not the mail
                    // idempotency key. If the first enqueue fails after the
                    // order is already `disputed`, a retry still sees the
                    // terminal status; the durable snapshot claim keeps the
                    // alert at-most-once after a successful enqueue.
                    $currentStatus = $disputedOrder?->status ?? $order->fresh()?->status;
                    if ($currentStatus === 'disputed') {
                        $this->disputeMailDispatcher->queueOnce($order);
                    }

                    return response()->json(['status' => 'success']);
                },
            );
        } elseif ($eventType === 'charge.refunded') {
            $charge = $this->eventObject($event);

            return $this->withWebhookEventClaim(
                $this->webhookEventKey($this->eventId($event), $charge, 'charge.refunded'),
                function () use ($charge): JsonResponse {
                    $piId = $this->customerOrPaymentIntentId($charge, 'payment_intent');
                    if ($piId === null) {
                        Log::warning('Webhook: refund with null payment_intent, skipping', [
                            'charge_id' => $this->valueString($this->objectValue($charge, 'id')),
                        ]);

                        return response()->json(['status' => 'success']);
                    }

                    // `charge.refunded` fires for partial refunds too. In this
                    // domain the `refunded` order state means a FULL refund (see
                    // features/ecommerce/09-stripe-checkout-flow.md), so partial
                    // refunds must not revoke the customer's download access.
                    if (! $this->isFullRefund($charge)) {
                        Log::warning('Webhook: partial refund received, order access preserved', [
                            'charge_id' => $this->valueString($this->objectValue($charge, 'id')),
                            'amount' => $this->valueInt($this->objectValue($charge, 'amount')),
                            'amount_refunded' => $this->valueInt($this->objectValue($charge, 'amount_refunded')),
                        ]);

                        return response()->json(['status' => 'success']);
                    }

                    $order = Order::where('stripe_payment_intent_id', $piId)->first();
                    if ($order !== null) {
                        $this->transitionTerminalPaymentStatus($order, 'refunded');
                        // Keep the cache fail-closed even when the terminal-state
                        // guard intentionally leaves a pending/cancelled/refunded
                        // row unchanged.
                        $this->clearPurchasedCache($order);
                    }

                    return response()->json(['status' => 'success']);
                },
            );
        }

        return response()->json(['status' => 'success']);
    }

    private function handlePaymentIntentSucceeded(object $event): JsonResponse
    {
        $paymentIntent = $this->eventObject($event);
        $eventId = $this->eventId($event);
        $orderId = $this->metadataString(
            $this->objectValue($paymentIntent, 'metadata'),
            'order_id',
        );

        if (! $this->isObjectLike($paymentIntent) || $orderId === null) {
            Log::warning('Stripe Webhook: success without a usable order identity', [
                'event_id' => $eventId,
                'payment_intent_id' => $this->valueString($this->objectValue($paymentIntent, 'id')),
            ]);

            return response()->json(['status' => 'success']);
        }

        $order = Order::with(['user', 'invoiceSnapshot'])->find($orderId);
        if ($order === null) {
            Log::warning('Stripe Webhook: success for unknown order', [
                'event_id' => $eventId,
                'order_id' => $orderId,
            ]);

            return response()->json(['status' => 'success']);
        }

        if ($order->stripe_payment_intent_id === null) {
            // A signed success event may be the only durable PI record after
            // an ambiguous create. Reconcile it through the same strict
            // identity/fee/mail path; the shared service binds the PI ID in
            // the locked pending_payment -> paid transition. Invalid events
            // remain ordinary ignored responses and cannot create a new PI.
            $dedupeKey = $this->webhookEventKey($eventId, $paymentIntent, 'payment_intent.succeeded');

            return $this->withWebhookEventClaim($dedupeKey, function () use (
                $eventId,
                $order,
                $orderId,
                $paymentIntent,
            ): JsonResponse {
                return $this->reconciliationResponse(
                    $this->paymentReconciliation->reconcileSucceededWithMissingLink($order, $paymentIntent),
                    $eventId,
                    $orderId,
                );
            });
        }

        $mismatch = $this->paymentReconciliation->identityMismatch($order, $paymentIntent, true);
        if ($mismatch !== null) {
            Log::warning('Stripe Webhook: success identity mismatch', [
                'event_id' => $eventId,
                'order_id' => $orderId,
                'payment_intent_id' => $this->valueString($this->objectValue($paymentIntent, 'id')),
                'reason' => $mismatch,
            ]);

            return response()->json(['status' => 'ignored', 'reason' => $mismatch]);
        }

        if ($order->status === 'paid') {
            // A previous event may have transitioned the order before mail
            // enqueue failed. Re-enter the shared durable mail path on every
            // paid retry instead of acknowledging solely from order status.
            // The claim prevents a second enqueue; SMTP delivery retries remain
            // the queue worker's responsibility.
            return $this->reconciliationResponse(
                $this->paymentReconciliation->reconcileSucceeded($order, $paymentIntent),
                $eventId,
                $orderId,
            );
        }

        if ($order->status !== 'pending_payment') {
            Log::warning('Stripe Webhook: success ignored for non-pending order', [
                'event_id' => $eventId,
                'order_id' => $orderId,
                'order_status' => $order->status,
            ]);

            return response()->json(['status' => 'ignored', 'reason' => 'order_not_pending']);
        }

        $dedupeKey = $this->webhookEventKey($eventId, $paymentIntent, 'payment_intent.succeeded');

        return $this->withWebhookEventClaim($dedupeKey, function () use (
            $eventId,
            $order,
            $orderId,
            $paymentIntent,
        ): JsonResponse {
            return $this->reconciliationResponse(
                $this->paymentReconciliation->reconcileSucceeded($order, $paymentIntent),
                $eventId,
                $orderId,
            );
        });
    }

    private function reconciliationResponse(array $result, ?string $eventId, string $orderId): JsonResponse
    {
        if (in_array($result['status'], ['paid', 'already_paid'], true)) {
            return response()->json(['status' => 'success']);
        }
        if (($result['status'] ?? null) === 'retryable') {
            return response()->json([
                'error' => 'PaymentIntent reconciliation is temporarily unavailable.',
            ], 503);
        }

        $reason = $result['reason'] ?? 'reconciliation_not_applied';
        Log::warning('Stripe Webhook: success transition was not applied', [
            'event_id' => $eventId,
            'order_id' => $orderId,
            'reason' => $reason,
        ]);

        return response()->json(['status' => 'ignored', 'reason' => $reason]);
    }

    private function recordPaymentFailure(object $event): JsonResponse
    {
        $paymentIntent = $this->eventObject($event);
        $eventId = $this->eventId($event);
        $orderId = $this->metadataString(
            $this->objectValue($paymentIntent, 'metadata'),
            'order_id',
        );
        $context = $this->paymentFailureContext($eventId, $paymentIntent, $orderId);

        if (! $this->isObjectLike($paymentIntent) || $orderId === null) {
            Log::warning('Stripe Webhook: payment failure without order metadata', $context);

            return response()->json(['status' => 'success']);
        }

        $order = Order::with('user')->find($orderId);
        if ($order === null) {
            Log::warning('Stripe Webhook: payment failure for unknown order', $context);

            return response()->json(['status' => 'success']);
        }

        $mismatch = $this->paymentReconciliation->identityMismatch($order, $paymentIntent, false);
        if ($mismatch !== null) {
            Log::warning('Stripe Webhook: payment failure identity mismatch', [
                ...$context,
                'reason' => $mismatch,
            ]);

            return response()->json(['status' => 'ignored', 'reason' => $mismatch]);
        }

        if ($order->status !== 'pending_payment') {
            Log::warning('Stripe Webhook: payment failure ignored for non-pending order', [
                ...$context,
                'order_status' => $order->status,
            ]);

            return response()->json(['status' => 'ignored', 'reason' => 'order_not_pending']);
        }

        $dedupeKey = $this->webhookEventKey($eventId, $paymentIntent, 'payment_intent.payment_failed');

        return $this->withWebhookEventClaim($dedupeKey, function () use (
            $context,
            $eventId,
            $order,
            $paymentIntent,
        ): JsonResponse {
            $updatedOrder = DB::transaction(function () use ($eventId, $order, $paymentIntent): ?Order {
                $lockedOrder = Order::query()
                    ->with('user')
                    ->lockForUpdate()
                    ->find($order->getKey());

                if ($lockedOrder === null
                    || $lockedOrder->status !== 'pending_payment'
                    || $this->paymentReconciliation->identityMismatch($lockedOrder, $paymentIntent, false) !== null) {
                    return null;
                }

                $lastError = $this->objectValue($paymentIntent, 'last_payment_error');
                $declineCode = $this->sanitizeFailureCode(
                    $this->objectValue($lastError, 'decline_code'),
                    $this->objectValue($lastError, 'code'),
                    $this->objectValue($lastError, 'type'),
                );

                if ($eventId === null && $this->isRecentMissingIdFailure($lockedOrder, $declineCode)) {
                    return null;
                }

                $currentCount = max(0, (int) $lockedOrder->payment_failure_count);
                $nextCount = min(255, $currentCount + 1);

                $lockedOrder->update([
                    'payment_failure_count' => $nextCount,
                    'last_payment_failure_at' => now(),
                    'last_payment_decline_code' => $declineCode,
                ]);

                return $lockedOrder;
            });

            if ($updatedOrder !== null) {
                // The locked order carries the persisted customer-IP snapshot.
                // Never derive failure velocity from the Stripe webhook ingress IP.
                $this->checkoutRisk->recordVerifiedPaymentFailure($updatedOrder);
                Log::warning('Stripe Webhook: card payment attempt failed', $context);
            }

            return response()->json(['status' => 'success']);
        });
    }

    /**
     * Claim an event before doing external/database work, but mark it as
     * processed only after the callback succeeds. A short-lived processing
     * claim prevents concurrent duplicates without permanently swallowing a
     * retry after a transient failure.
     *
     * @param  \Closure(): JsonResponse  $callback
     */
    private function withWebhookEventClaim(string $key, \Closure $callback): JsonResponse
    {
        try {
            $state = $this->claimWebhookEvent($key);
        } catch (\Throwable $exception) {
            Log::warning('Stripe Webhook: event claim failed', [
                'exception_class' => $exception::class,
            ]);

            return response()->json(['error' => 'Webhook processing is temporarily unavailable.'], 503);
        }

        if ($state === 'processed') {
            return response()->json(['status' => 'success']);
        }
        if ($state !== 'claimed') {
            return response()->json(['error' => 'Webhook processing is temporarily busy.'], 503);
        }

        try {
            $response = $callback();
        } catch (\Throwable $exception) {
            try {
                $this->releaseWebhookEvent($key);
            } catch (\Throwable) {
                // The short claim TTL is the fallback if the cache is down.
            }

            Log::warning('Stripe Webhook: event processing failed', [
                'exception_class' => $exception::class,
            ]);

            return response()->json(['error' => 'Webhook processing is temporarily unavailable.'], 503);
        }

        if ($response->getStatusCode() >= 500) {
            try {
                $this->releaseWebhookEvent($key);
            } catch (\Throwable $exception) {
                // The short claim TTL is the fallback if the cache is down.
                Log::warning('Stripe Webhook: retryable response claim release failed', [
                    'exception_class' => $exception::class,
                ]);
            }

            Log::warning('Stripe Webhook: retryable response left event claim open', [
                'status' => $response->getStatusCode(),
            ]);

            return $response;
        }

        try {
            $this->completeWebhookEvent($key);
        } catch (\Throwable $exception) {
            // The database callback already completed. Do not turn a cache
            // outage into a replay that can duplicate failure telemetry.
            Log::warning('Stripe Webhook: event completion cache failed', [
                'exception_class' => $exception::class,
            ]);
        }

        return $response;
    }

    private function claimWebhookEvent(string $key): string
    {
        $current = Cache::get($key);
        if ($this->isProcessedWebhookMarker($current)) {
            return 'processed';
        }

        if (Cache::add($key, 'processing', now()->addSeconds(self::WEBHOOK_EVENT_CLAIM_TTL_SECONDS))) {
            return 'claimed';
        }

        $current = Cache::get($key);

        return $this->isProcessedWebhookMarker($current) ? 'processed' : 'in_progress';
    }

    private function completeWebhookEvent(string $key): void
    {
        Cache::put($key, 'processed', now()->addDays(self::WEBHOOK_EVENT_DEDUPE_TTL_DAYS));
    }

    private function releaseWebhookEvent(string $key): void
    {
        Cache::forget($key);
    }

    private function isProcessedWebhookMarker(mixed $value): bool
    {
        return $value === true
            || $value === 'processed'
            || (is_array($value) && ($value['state'] ?? null) === 'processed');
    }

    private function webhookEventKey(?string $eventId, object|array $paymentIntent, string $eventType): string
    {
        if ($eventId !== null) {
            return 'stripe-webhook-event:'.$eventId;
        }

        $lastError = $this->objectValue($paymentIntent, 'last_payment_error');
        $identity = [
            'type' => $eventType,
            'payment_intent_id' => $this->valueString($this->objectValue($paymentIntent, 'id')),
            'order_id' => $this->metadataString($this->objectValue($paymentIntent, 'metadata'), 'order_id'),
            'generation' => $this->metadataString($this->objectValue($paymentIntent, 'metadata'), 'generation'),
            'status' => $this->valueString($this->objectValue($paymentIntent, 'status')),
            'amount_cents' => $this->valueInt($this->objectValue($paymentIntent, 'amount')),
            'currency' => $this->valueString($this->objectValue($paymentIntent, 'currency')),
            'created' => $this->valueInt($this->objectValue($paymentIntent, 'created')),
            'error_type' => $this->sanitizeFailureCode($this->objectValue($lastError, 'type')),
            'error_code' => $this->sanitizeFailureCode($this->objectValue($lastError, 'code')),
            'decline_code' => $this->sanitizeFailureCode($this->objectValue($lastError, 'decline_code')),
        ];

        return 'stripe-webhook-event:missing:'.hash(
            'sha256',
            json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    private function isRecentMissingIdFailure(Order $order, ?string $declineCode): bool
    {
        $lastFailureAt = $order->last_payment_failure_at;
        if ($lastFailureAt === null) {
            return false;
        }

        return $lastFailureAt->greaterThanOrEqualTo(
            now()->subSeconds(self::WEBHOOK_MISSING_ID_COALESCE_SECONDS),
        ) && (string) $order->last_payment_decline_code === (string) $declineCode;
    }

    private function metadataString(mixed $metadata, string $key): ?string
    {
        $value = $this->objectValue($metadata, $key);

        return $this->valueString($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentFailureContext(?string $eventId, mixed $paymentIntent, ?string $orderId): array
    {
        $lastError = $this->objectValue($paymentIntent, 'last_payment_error');
        $declineCode = $this->sanitizeFailureCode(
            $this->objectValue($lastError, 'decline_code'),
            $this->objectValue($lastError, 'code'),
            $this->objectValue($lastError, 'type'),
        );
        $errorType = $this->sanitizeFailureCode($this->objectValue($lastError, 'type'));
        $errorCode = $this->sanitizeFailureCode($this->objectValue($lastError, 'code'));

        return [
            'event_id' => $eventId,
            'order_id' => $orderId,
            'payment_intent_id' => $this->valueString($this->objectValue($paymentIntent, 'id')),
            'status' => $this->valueString($this->objectValue($paymentIntent, 'status')),
            'amount' => $this->valueInt($this->objectValue($paymentIntent, 'amount')),
            'currency' => $this->valueString($this->objectValue($paymentIntent, 'currency')),
            'error_type' => $errorType,
            'error_code' => $errorCode,
            'decline_code' => $declineCode,
        ];
    }

    private function sanitizeFailureCode(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $candidate = $this->valueString($value);
            if ($candidate === null) {
                continue;
            }

            $candidate = preg_replace('/[^A-Za-z0-9_.:-]/', '', $candidate) ?? '';
            if ($candidate !== '') {
                return substr($candidate, 0, 64);
            }
        }

        return null;
    }

    private function eventId(object $event): ?string
    {
        return $this->valueString($this->objectValue($event, 'id'));
    }

    private function eventObject(object $event): mixed
    {
        return $this->objectValue($this->objectValue($event, 'data'), 'object');
    }

    private function customerOrPaymentIntentId(mixed $source, string $key): ?string
    {
        return $this->valueString($this->objectValue($source, $key));
    }

    private function isObjectLike(mixed $value): bool
    {
        return is_object($value) || is_array($value);
    }

    private function objectValue(mixed $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }
        if ($source instanceof \ArrayAccess && $source->offsetExists($key)) {
            return $source[$key];
        }
        if (is_object($source) && isset($source->{$key})) {
            return $source->{$key};
        }

        return null;
    }

    private function valueString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function valueInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * A Stripe Charge is only fully refunded when `refunded` is true. Partial refunds
     * keep the order's download access intact.
     */
    private function isFullRefund(mixed $charge): bool
    {
        if ($this->isObjectLike($charge) && $this->objectValue($charge, 'refunded') !== null) {
            return (bool) $this->objectValue($charge, 'refunded');
        }

        // Fallback for payloads that omit the `refunded` flag: only treat the
        // charge as fully refunded when the refunded amount covers the charge.
        $amount = $this->valueInt($this->objectValue($charge, 'amount')) ?? 0;
        $amountRefunded = $this->valueInt($this->objectValue($charge, 'amount_refunded')) ?? 0;

        return $amount > 0 && $amountRefunded >= $amount;
    }

    /**
     * Apply only documented payment-terminal transitions under a row lock.
     *
     * `refunded` and `cancelled` are terminal for dispute/refund events, and
     * neither event may turn a pending quote or pending payment into a
     * negative-access state. A dispute may follow a settled status, and a full
     * refund may follow either a settled status or `disputed` (paid → disputed
     * → refunded). The conditional update also prevents a late event from
     * winning a concurrent status transition.
     */
    private function transitionTerminalPaymentStatus(Order $order, string $targetStatus): ?Order
    {
        $allowedSourceStatuses = match ($targetStatus) {
            'disputed' => [
                'paid',
                'invoice_created',
                'overdue',
                'delivery_note',
                'archived_in_collective',
            ],
            'refunded' => [
                'paid',
                'invoice_created',
                'overdue',
                'delivery_note',
                'archived_in_collective',
                'disputed',
            ],
            default => [],
        };

        if ($allowedSourceStatuses === []) {
            return null;
        }

        return DB::transaction(function () use ($order, $targetStatus, $allowedSourceStatuses): ?Order {
            $lockedOrder = Order::query()
                ->with('invoiceSnapshot')
                ->lockForUpdate()
                ->find($order->getKey());
            if ($lockedOrder === null
                || ! in_array($lockedOrder->status, $allowedSourceStatuses, true)) {
                return null;
            }

            $updated = Order::query()
                ->whereKey($lockedOrder->getKey())
                ->where('status', $lockedOrder->status)
                ->update(['status' => $targetStatus]);
            if ($updated !== 1) {
                return null;
            }

            $lockedOrder->status = $targetStatus;

            return $lockedOrder;
        });
    }

    private function clearPurchasedCache(Order $order): void
    {
        $snapshot = $order->invoiceSnapshot;
        if (! $snapshot) {
            return;
        }

        $items = $snapshot->customer_details['items'] ?? [];
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['photoId'])) {
                continue;
            }
            if (! is_string($item['photoId']) && ! is_int($item['photoId'])) {
                continue;
            }

            $photoId = trim((string) $item['photoId']);
            if ($photoId === '') {
                continue;
            }

            foreach (['web', 'print', 'original'] as $tier) {
                $cacheKey = ActorIdentity::purchaseCacheKeyForOrder($order, $photoId, $tier);
                if ($cacheKey !== null) {
                    Cache::forget($cacheKey);
                }
            }
        }
    }
}
