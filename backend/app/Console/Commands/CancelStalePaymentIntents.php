<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Services\PaymentIntentReconciliationService;
use App\Services\StripePaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Throwable;

class CancelStalePaymentIntents extends Command
{
    protected $signature = 'stripe:cancel-stale-payment-intents {--hours= : Override the configured stale age in hours} {--limit=500 : Maximum number of pending orders to inspect}';

    protected $description = 'Cancel stale incomplete Stripe PaymentIntents for pending orders.';

    private const CANCELABLE_STATUSES = [
        PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
        PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
        PaymentIntent::STATUS_REQUIRES_ACTION,
        PaymentIntent::STATUS_REQUIRES_CAPTURE,
    ];

    public function handle(
        StripePaymentService $stripePayment,
        PaymentIntentReconciliationService $paymentReconciliation,
    ): int {
        $hoursOption = $this->option('hours');
        $hours = $hoursOption === null
            ? (int) config('app.stripe.stale_payment_intent_hours', 2)
            : (int) $hoursOption;

        if ($hours < 1) {
            $this->error('The stale PaymentIntent age must be at least one hour.');

            return self::INVALID;
        }

        $limitOption = $this->option('limit');
        $limit = $limitOption === null ? 500 : (int) $limitOption;
        if ($limit < 1 || $limit > 10000) {
            $this->error('The stale PaymentIntent limit must be between 1 and 10000.');

            return self::INVALID;
        }

        $counters = [
            'cancelled' => 0,
            'reconciled' => 0,
            'skipped' => 0,
            'failed' => 0,
            'missing_intent' => 0,
        ];
        $cutoff = now()->subHours($hours);
        $cutoffTimestamp = $cutoff->getTimestamp();

        $orders = Order::query()
            ->where('status', 'pending_payment')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            $this->processOrder($order, $stripePayment, $paymentReconciliation, $counters, $cutoffTimestamp);
        }

        $this->info(sprintf(
            'Stale PaymentIntent sweep complete: %d cancelled, %d reconciled, %d skipped, %d missing PI, %d failed.',
            $counters['cancelled'],
            $counters['reconciled'],
            $counters['skipped'],
            $counters['missing_intent'],
            $counters['failed'],
        ));

        return $counters['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{cancelled: int, reconciled: int, skipped: int, failed: int, missing_intent: int}  $counters
     */
    private function processOrder(
        Order $candidate,
        StripePaymentService $stripePayment,
        PaymentIntentReconciliationService $paymentReconciliation,
        array &$counters,
        int $cutoffTimestamp,
    ): void {
        $paymentIntentId = $candidate->stripe_payment_intent_id;
        $generation = (int) $candidate->payment_intent_generation;

        if (! is_string($paymentIntentId) || $paymentIntentId === '') {
            $counters['missing_intent']++;
            Log::info('stripe.stale_payment_intent.missing_payment_intent', [
                'order_id' => (string) $candidate->getKey(),
                'payment_intent_generation' => $generation,
            ]);

            return;
        }

        // Reload before remote work. The final compare-and-set below protects
        // against a replacement that races this worker after this read.
        $order = Order::query()->find($candidate->getKey());
        if ($order === null
            || $order->status !== 'pending_payment'
            || $order->stripe_payment_intent_id !== $paymentIntentId
            || (int) $order->payment_intent_generation !== $generation) {
            $counters['skipped']++;
            Log::info('stripe.stale_payment_intent.identity_changed_before_remote_work', [
                'order_id' => (string) $candidate->getKey(),
                'payment_intent_generation' => $generation,
            ]);

            return;
        }

        try {
            $paymentIntent = $stripePayment->retrievePaymentIntent($paymentIntentId);
            if (! $this->paymentIntentMatchesOrder($paymentIntent, $order, $paymentIntentId)) {
                $counters['skipped']++;
                Log::warning('stripe.stale_payment_intent.remote_identity_mismatch', [
                    'order_id' => (string) $order->getKey(),
                    'payment_intent_id' => $paymentIntentId,
                    'payment_intent_generation' => $generation,
                ]);

                return;
            }

            $status = $paymentIntent['status'] ?? null;
            $created = $paymentIntent['created'] ?? null;

            // A verified remote success is reconciled before the age gate. A
            // fresh/succeeded PI must never be cancelled merely because the
            // order row is old or its replacement timestamp is unavailable.
            if ($this->isSuccessfulStatus($status)) {
                $result = $paymentReconciliation->reconcileSucceeded($order, $paymentIntent);
                if (in_array($result['status'], ['paid', 'already_paid'], true)) {
                    $counters['reconciled']++;
                    Log::info('stripe.stale_payment_intent.succeeded_reconciled', [
                        'order_id' => (string) $order->getKey(),
                        'payment_intent_id' => $paymentIntentId,
                        'payment_intent_generation' => $generation,
                    ]);
                } elseif (($result['status'] ?? null) === 'retryable') {
                    $counters['failed']++;
                    Log::warning('stripe.stale_payment_intent.succeeded_reconciliation_retryable', [
                        'order_id' => (string) $order->getKey(),
                        'payment_intent_id' => $paymentIntentId,
                        'payment_intent_generation' => $generation,
                        'reason' => $result['reason'] ?? null,
                    ]);
                } else {
                    $counters['skipped']++;
                    Log::warning('stripe.stale_payment_intent.succeeded_identity_mismatch', [
                        'order_id' => (string) $order->getKey(),
                        'payment_intent_id' => $paymentIntentId,
                        'payment_intent_generation' => $generation,
                        'reason' => $result['reason'] ?? null,
                    ]);
                }

                return;
            }

            // The order row can be older than the current PI generation. The
            // Stripe PI timestamp is authoritative for cancellation age.
            if ((! is_int($created) && ! is_numeric($created)) || (int) $created > $cutoffTimestamp) {
                $counters['skipped']++;
                Log::info('stripe.stale_payment_intent.fresh_or_unknown_age', [
                    'order_id' => (string) $order->getKey(),
                    'payment_intent_id' => $paymentIntentId,
                    'payment_intent_generation' => $generation,
                ]);

                return;
            }

            if ($status === PaymentIntent::STATUS_CANCELED) {
                $this->markOrderCancelled($order, $paymentIntentId, $generation, $counters);

                return;
            }

            if (! in_array($status, self::CANCELABLE_STATUSES, true)) {
                $counters['skipped']++;

                return;
            }

            $cancelledIntent = $stripePayment->cancelPaymentIntent($paymentIntentId);
            if (($cancelledIntent['id'] ?? null) !== $paymentIntentId
                || ($cancelledIntent['status'] ?? null) !== PaymentIntent::STATUS_CANCELED) {
                Log::warning('stripe.stale_payment_intent.unexpected_cancel_status', [
                    'order_id' => (string) $order->getKey(),
                    'payment_intent_id' => $paymentIntentId,
                    'payment_intent_generation' => $generation,
                    'status' => $cancelledIntent['status'] ?? null,
                ]);
                $counters['failed']++;

                return;
            }

            $this->markOrderCancelled($order, $paymentIntentId, $generation, $counters);
        } catch (Throwable $exception) {
            Log::warning('stripe.stale_payment_intent.failed', [
                'order_id' => (string) $order->getKey(),
                'payment_intent_id' => $paymentIntentId,
                'payment_intent_generation' => $generation,
                'exception_type' => $exception::class,
            ]);
            $counters['failed']++;
        }
    }

    private function isSuccessfulStatus(mixed $status): bool
    {
        return $status === PaymentIntent::STATUS_SUCCEEDED;
    }

    private function paymentIntentMatchesOrder(array $paymentIntent, Order $order, string $expectedPaymentIntentId): bool
    {
        if ((string) ($paymentIntent['id'] ?? '') !== $expectedPaymentIntentId
            || ! is_numeric($paymentIntent['amount'] ?? null)
            || (int) $paymentIntent['amount'] !== (int) $order->total_amount
            || strtolower(trim((string) ($paymentIntent['currency'] ?? ''))) !== 'eur') {
            return false;
        }

        $metadata = $paymentIntent['metadata'] ?? null;
        if ($metadata !== null
            && ! is_array($metadata)
            && ! ($metadata instanceof \ArrayAccess)
            && ! is_object($metadata)) {
            return false;
        }

        $orderId = $this->metadataString($metadata, 'order_id');
        $metadataAmount = $this->metadataString($metadata, 'amount_cents');
        $metadataCurrency = $this->metadataString($metadata, 'currency');
        $key = $this->metadataString($metadata, 'checkout_idempotency_key');
        $fingerprint = $this->metadataString($metadata, 'checkout_fingerprint');
        $generation = $this->metadataString($metadata, 'generation');
        $portalUserId = $this->metadataString($metadata, 'portal_user_id');
        $accountCreatedAt = $this->metadataString($metadata, 'account_created_at');
        $hasIdentityMetadata = $orderId !== null
            || $metadataAmount !== null
            || $metadataCurrency !== null
            || $key !== null
            || $fingerprint !== null
            || $generation !== null
            || $portalUserId !== null
            || $accountCreatedAt !== null;
        $legacyOrder = $this->isLegacyOrder($order);

        // A genuinely absent metadata object is tolerated only for a clearly
        // legacy order. Every V036 candidate must carry the complete linkage.
        if (! $hasIdentityMetadata) {
            return $legacyOrder && $this->customerMatchesOrder($order, $paymentIntent['customer'] ?? null, true);
        }
        if ($orderId !== (string) $order->getKey()) {
            return false;
        }

        $expected = [
            'checkout_idempotency_key' => $order->checkout_idempotency_key,
            'checkout_fingerprint' => $order->checkout_fingerprint,
            'generation' => (string) ((int) $order->payment_intent_generation),
            'portal_user_id' => (string) $order->user_id,
            'amount_cents' => (string) $order->total_amount,
            'currency' => 'eur',
        ];
        $actual = [
            'checkout_idempotency_key' => $key,
            'checkout_fingerprint' => $fingerprint,
            'generation' => $generation,
            'portal_user_id' => $portalUserId,
            'amount_cents' => $metadataAmount,
            'currency' => $metadataCurrency,
        ];
        foreach ($expected as $metadataKey => $expectedValue) {
            $actualValue = $actual[$metadataKey];
            if ($legacyOrder) {
                if ($actualValue !== null && $actualValue !== (string) $expectedValue) {
                    return false;
                }

                continue;
            }
            if ($actualValue === null || $actualValue !== (string) $expectedValue) {
                return false;
            }
        }

        $expectedAccountCreatedAt = $order->user?->created_at?->getTimestamp();
        if ($accountCreatedAt !== null
            && ($expectedAccountCreatedAt === null || $accountCreatedAt !== (string) $expectedAccountCreatedAt)) {
            return false;
        }

        return $this->customerMatchesOrder($order, $paymentIntent['customer'] ?? null, $legacyOrder);
    }

    private function customerMatchesOrder(Order $order, mixed $customer, bool $legacyOrder): bool
    {
        $mappedCustomer = $order->user?->stripe_customer_id;
        $mappedCustomer = is_string($mappedCustomer) && $mappedCustomer !== ''
            ? $mappedCustomer
            : null;
        $suppliedCustomer = $this->customerId($customer);

        if ($suppliedCustomer === null) {
            if ($legacyOrder) {
                return true;
            }

            return $mappedCustomer === null && ! app()->environment('production');
        }
        if ($mappedCustomer === null || ! hash_equals($mappedCustomer, $suppliedCustomer)) {
            return false;
        }

        return ! User::query()
            ->where('stripe_customer_id', $suppliedCustomer)
            ->where('id', '!=', $order->user_id)
            ->exists();
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

    private function isLegacyOrder(Order $order): bool
    {
        return $order->checkout_idempotency_key === null
            && $order->checkout_fingerprint === null;
    }

    private function metadataString(mixed $metadata, string $key): ?string
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

    /**
     * @param  array{cancelled: int, skipped: int, failed: int, missing_intent: int}  $counters
     */
    private function markOrderCancelled(
        Order $order,
        string $paymentIntentId,
        int $generation,
        array &$counters,
    ): void {
        $updated = Order::query()
            ->whereKey($order->getKey())
            ->where('status', 'pending_payment')
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->where('payment_intent_generation', $generation)
            ->update(['status' => 'cancelled']);

        if ($updated === 0) {
            $counters['skipped']++;
            Log::info('stripe.stale_payment_intent.identity_changed_before_local_update', [
                'order_id' => (string) $order->getKey(),
                'payment_intent_id' => $paymentIntentId,
                'payment_intent_generation' => $generation,
            ]);

            return;
        }

        $counters['cancelled']++;
        Log::info('stripe.stale_payment_intent.cancelled', [
            'order_id' => (string) $order->getKey(),
            'payment_intent_id' => $paymentIntentId,
            'payment_intent_generation' => $generation,
        ]);
    }
}
