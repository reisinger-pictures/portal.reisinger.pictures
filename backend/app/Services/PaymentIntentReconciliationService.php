<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Shared strict reconciliation for a PaymentIntent that is already known to
 * be successful. Webhook signature verification remains the normal authority;
 * scheduled cleanup may call this only with a PI retrieved by its stored ID.
 */
class PaymentIntentReconciliationService
{
    public function __construct(private readonly StripePaymentService $stripePayment)
    {
    }

    /**
     * @param  object|array<string, mixed>  $paymentIntent
     * @return array{status: 'paid'|'already_paid'|'ignored'|'retryable', reason: ?string}
     */
    public function reconcileSucceeded(Order $order, object|array $paymentIntent): array
    {
        return $this->reconcileSucceededInternal($order, $paymentIntent, false);
    }

    /**
     * Reconcile a signed success event when the local PI link was not persisted
     * after an ambiguous create. The event is still treated as authoritative
     * only after every V036 identity/amount/customer check passes; the PI ID is
     * bound in the same locked conditional transition that grants paid access.
     *
     * @param  object|array<string, mixed>  $paymentIntent
     * @return array{status: 'paid'|'already_paid'|'ignored'|'retryable', reason: ?string}
     */
    public function reconcileSucceededWithMissingLink(Order $order, object|array $paymentIntent): array
    {
        if ($order->status !== 'pending_payment') {
            return ['status' => 'ignored', 'reason' => 'order_not_pending'];
        }

        return $this->reconcileSucceededInternal($order, $paymentIntent, true);
    }

    /**
     * @param  object|array<string, mixed>  $paymentIntent
     * @return array{status: 'paid'|'already_paid'|'ignored'|'retryable', reason: ?string}
     */
    private function reconcileSucceededInternal(Order $order, object|array $paymentIntent, bool $allowMissingLink): array
    {
        $mismatch = $this->identityMismatch($order, $paymentIntent, true, $allowMissingLink);
        if ($mismatch !== null) {
            return ['status' => 'ignored', 'reason' => $mismatch];
        }

        $paymentIntentId = $this->valueString($this->objectValue($paymentIntent, 'id'));
        if ($paymentIntentId === null) {
            return ['status' => 'ignored', 'reason' => 'obsolete_payment_intent'];
        }

        if ($order->status === 'paid') {
            return $this->queuePaidOrderMail($order, 'already_paid');
        }
        if ($order->status !== 'pending_payment') {
            return ['status' => 'ignored', 'reason' => 'order_not_pending'];
        }

        $feeCents = $this->retrieveOptionalFee($paymentIntentId, $order);
        $transition = DB::transaction(function () use ($order, $paymentIntent, $paymentIntentId, $feeCents, $allowMissingLink): string {
            $lockedOrder = Order::query()
                ->with(['user', 'invoiceSnapshot'])
                ->lockForUpdate()
                ->find($order->getKey());

            if ($lockedOrder === null) {
                return 'missing_order';
            }

            if ($allowMissingLink
                && Order::query()
                    ->where('stripe_payment_intent_id', $paymentIntentId)
                    ->where('id', '!=', $lockedOrder->getKey())
                    ->exists()) {
                return 'payment_intent_already_linked';
            }

            $lockedMismatch = $this->identityMismatch($lockedOrder, $paymentIntent, true, $allowMissingLink);
            if ($lockedMismatch !== null) {
                return $lockedMismatch;
            }
            if ($lockedOrder->status === 'paid') {
                return 'already_paid';
            }
            if ($lockedOrder->status !== 'pending_payment') {
                return 'order_not_pending';
            }

            $updateQuery = Order::query()
                ->whereKey($lockedOrder->getKey())
                ->where('status', 'pending_payment')
                ->where('payment_intent_generation', (int) $lockedOrder->payment_intent_generation);
            if ($allowMissingLink) {
                $updateQuery->where(function ($query) use ($paymentIntentId): void {
                    $query->whereNull('stripe_payment_intent_id')
                        ->orWhere('stripe_payment_intent_id', $paymentIntentId);
                });
            } else {
                $updateQuery->where('stripe_payment_intent_id', $paymentIntentId);
            }

            $updates = [
                'status' => 'paid',
                'stripe_fee_cents' => $feeCents,
            ];
            if ($allowMissingLink) {
                $updates['stripe_payment_intent_id'] = $paymentIntentId;
            }
            $updated = $updateQuery->update($updates);

            return $updated === 1 ? 'paid' : 'not_updated';
        });

        if ($transition === 'paid' || $transition === 'already_paid') {
            $paidOrder = Order::with(['user', 'invoiceSnapshot'])->find($order->getKey());
            if ($paidOrder === null) {
                return ['status' => 'ignored', 'reason' => 'missing_order'];
            }

            return $this->queuePaidOrderMail(
                $paidOrder,
                $transition === 'already_paid' ? 'already_paid' : 'paid',
            );
        }

        $freshOrder = Order::with(['user', 'invoiceSnapshot'])->find($order->getKey());
        if ($freshOrder?->status === 'paid') {
            return $this->queuePaidOrderMail($freshOrder, 'already_paid');
        }

        return ['status' => 'ignored', 'reason' => $transition];
    }

    /**
     * Validate all identity and amount fields that are required before a
     * successful PaymentIntent can grant access. Only clearly legacy orders may
     * omit the V036 metadata/customer fields.
     *
     * @param  object|array<string, mixed>  $paymentIntent
     */
    public function identityMismatch(
        Order $order,
        object|array $paymentIntent,
        bool $requireReceivedAmount,
        bool $allowMissingLink = false,
    ): ?string
    {
        $paymentIntentId = $this->valueString($this->objectValue($paymentIntent, 'id'));
        $storedPaymentIntentId = $order->stripe_payment_intent_id;
        if ($paymentIntentId === null
            || (! $allowMissingLink && $paymentIntentId !== (string) $storedPaymentIntentId)
            || ($allowMissingLink
                && $storedPaymentIntentId !== null
                && $paymentIntentId !== (string) $storedPaymentIntentId)) {
            return 'obsolete_payment_intent';
        }

        $metadata = $this->objectValue($paymentIntent, 'metadata');
        if ($this->metadataString($metadata, 'order_id') !== (string) $order->getKey()) {
            return 'metadata_order_mismatch';
        }

        $legacyOrder = $this->isLegacyOrder($order);
        if ($legacyOrder) {
            foreach ([
                'checkout_idempotency_key' => $order->checkout_idempotency_key,
                'checkout_fingerprint' => $order->checkout_fingerprint,
                'generation' => (string) $order->payment_intent_generation,
                'portal_user_id' => (string) $order->user_id,
            ] as $key => $expected) {
                $actual = $this->metadataString($metadata, $key);
                if ($actual !== null && $actual !== (string) $expected) {
                    return match ($key) {
                        'checkout_idempotency_key' => 'metadata_key_mismatch',
                        'checkout_fingerprint' => 'metadata_fingerprint_mismatch',
                        'generation' => 'generation_mismatch',
                        'portal_user_id' => 'user_mismatch',
                        default => 'metadata_order_mismatch',
                    };
                }
            }
        } else {
            $expected = [
                'checkout_idempotency_key' => $order->checkout_idempotency_key,
                'checkout_fingerprint' => $order->checkout_fingerprint,
                'generation' => (string) $order->payment_intent_generation,
                'portal_user_id' => (string) $order->user_id,
                'account_created_at' => $order->user?->created_at?->getTimestamp() === null
                    ? null
                    : (string) $order->user->created_at->getTimestamp(),
                'amount_cents' => (string) $order->total_amount,
                'currency' => 'eur',
            ];
            foreach ($expected as $key => $expectedValue) {
                $actual = $this->metadataString($metadata, $key);
                if ($expectedValue === null || $actual === null || $actual !== (string) $expectedValue) {
                    return match ($key) {
                        'checkout_idempotency_key' => 'metadata_key_mismatch',
                        'checkout_fingerprint' => 'metadata_fingerprint_mismatch',
                        'generation' => 'generation_mismatch',
                        'portal_user_id' => 'user_mismatch',
                        'account_created_at' => 'metadata_account_mismatch',
                        'amount_cents' => 'metadata_amount_mismatch',
                        'currency' => 'metadata_currency_mismatch',
                        default => 'metadata_order_mismatch',
                    };
                }
            }
        }

        $metadataAmount = $this->metadataString($metadata, 'amount_cents');
        if ($metadataAmount !== null
            && (! ctype_digit($metadataAmount) || (int) $metadataAmount !== (int) $order->total_amount)) {
            return 'metadata_amount_mismatch';
        }
        $metadataCurrency = $this->metadataString($metadata, 'currency');
        if ($metadataCurrency !== null && strtolower($metadataCurrency) !== 'eur') {
            return 'metadata_currency_mismatch';
        }

        $amount = $this->valueInt($this->objectValue($paymentIntent, 'amount'));
        if ($amount === null || $amount !== (int) $order->total_amount) {
            return 'amount_mismatch';
        }
        $currency = strtolower($this->valueString($this->objectValue($paymentIntent, 'currency')) ?? '');
        if ($currency !== 'eur') {
            return 'currency_mismatch';
        }

        if ($requireReceivedAmount) {
            $received = $this->valueInt($this->objectValue($paymentIntent, 'amount_received'));
            if ($received === null || $received < (int) $order->total_amount) {
                return 'underpaid';
            }
        }

        if (! $this->customerMatchesOrder($order, $this->objectValue($paymentIntent, 'customer'))) {
            return 'customer_mismatch';
        }

        return null;
    }

    private function retrieveOptionalFee(string $paymentIntentId, Order $order): ?int
    {
        try {
            $fee = $this->stripePayment->retrievePaymentIntentWithFee($paymentIntentId);
            if ($fee === null) {
                Log::warning('PaymentIntent reconciliation: expanded fee unavailable; using payout fallback', [
                    'order_id' => (string) $order->getKey(),
                    'payment_intent_id' => $paymentIntentId,
                ]);
            } elseif ($fee === 0) {
                Log::warning('PaymentIntent reconciliation: genuine zero fee observed', [
                    'order_id' => (string) $order->getKey(),
                    'payment_intent_id' => $paymentIntentId,
                ]);
            }

            return $fee;
        } catch (\Throwable $exception) {
            Log::warning('PaymentIntent reconciliation: fee lookup unavailable; using payout fallback', [
                'order_id' => (string) $order->getKey(),
                'payment_intent_id' => $paymentIntentId,
                'exception_class' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @return array{status: 'paid'|'already_paid'|'retryable', reason: ?string}
     */
    private function queuePaidOrderMail(Order $order, string $successStatus): array
    {
        try {
            if ($order->user && $order->invoiceSnapshot) {
                Cache::lock('invoice-mail:'.$order->getKey(), 30)->block(5, function () use ($order): void {
                    $marker = 'invoice_sent_'.$order->getKey();
                    if (Cache::has($marker)) {
                        return;
                    }

                    Mail::to($order->user->email)->queue(new InvoiceMail($order, $order->invoiceSnapshot));
                    Cache::put($marker, true, now()->addDays(7));
                });
            }

            return ['status' => $successStatus, 'reason' => null];
        } catch (\Throwable $exception) {
            Log::warning('PaymentIntent reconciliation: invoice mail queue failed', [
                'order_id' => (string) $order->getKey(),
                'exception_class' => $exception::class,
            ]);

            return ['status' => 'retryable', 'reason' => 'mail_failed'];
        }
    }

    private function customerMatchesOrder(Order $order, mixed $customer): bool
    {
        $mappedCustomer = $order->user?->stripe_customer_id;
        $mappedCustomer = is_string($mappedCustomer) && $mappedCustomer !== ''
            ? $mappedCustomer
            : null;
        $suppliedCustomer = $this->customerId($customer);
        $legacyOrder = $this->isLegacyOrder($order);

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

    private function isLegacyOrder(Order $order): bool
    {
        return $order->checkout_idempotency_key === null
            && $order->checkout_fingerprint === null;
    }

    private function metadataString(mixed $metadata, string $key): ?string
    {
        return $this->valueString($this->objectValue($metadata, $key));
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

    private function customerId(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        } elseif ($value instanceof \ArrayAccess && $value->offsetExists('id')) {
            $value = $value['id'];
        } elseif (is_object($value) && isset($value->id)) {
            $value = $value->id;
        }

        return $this->valueString($value);
    }
}
