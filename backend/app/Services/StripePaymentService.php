<?php

namespace App\Services;

use App\Models\Order;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\StripeObject;

class StripePaymentService
{
    private StripeClient $stripe;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Create a PaymentIntent while keeping the original three-argument call
     * contract valid. New checkout callers append the server-owned identity
     * values; legacy callers can still resolve them from the order. A legacy
     * call with no explicit generation/context keeps the historical
     * `pi_{orderId}` Stripe idempotency key; V036 calls use the generation key.
     *
     * @return array{
     *     id: string,
     *     status: string,
     *     client_secret: ?string,
     *     amount: int,
     *     amount_received: ?int,
     *     currency: string,
     *     created: ?int,
     *     customer: ?string,
     *     metadata: array{
     *         order_id: ?string,
     *         portal_user_id: ?string,
     *         account_created_at: ?string,
     *         checkout_idempotency_key: ?string,
     *         checkout_fingerprint: ?string,
     *         generation: ?string,
     *         amount_cents: ?string,
     *         currency: ?string
     *     }
     * }
     */
    public function createPaymentIntent(
        int $amountCents,
        string $orderId,
        string $receiptEmail,
        ?string $stripeCustomerId = null,
        ?int $generation = null,
        int|string|null $portalUserId = null,
        int|string|null $accountCreatedAt = null,
        ?string $checkoutIdempotencyKey = null,
        ?string $checkoutFingerprint = null,
    ): array {
        $legacyContract = $generation === null
            && $portalUserId === null
            && $accountCreatedAt === null
            && $checkoutIdempotencyKey === null
            && $checkoutFingerprint === null;
        $order = Order::query()->with('user')->find($orderId);
        if ($order === null) {
            throw new \RuntimeException('Stripe PaymentIntent order does not exist.');
        }
        if (is_string($order->guest_id) && trim($order->guest_id) !== '') {
            throw new \RuntimeException('Stripe checkout for transient guests is not supported.');
        }
        $context = $this->resolveIntentContext(
            $orderId,
            $generation,
            $portalUserId,
            $accountCreatedAt,
            $checkoutIdempotencyKey,
            $checkoutFingerprint,
            $order,
        );
        $generation = $context['generation'];

        $metadata = array_filter([
            'order_id' => $orderId,
            'portal_user_id' => $context['portal_user_id'],
            'generation' => (string) $generation,
            'account_created_at' => $context['account_created_at'],
            'checkout_idempotency_key' => $context['checkout_idempotency_key'],
            'checkout_fingerprint' => $context['checkout_fingerprint'],
            'amount_cents' => (string) $amountCents,
            'currency' => 'eur',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $parameters = [
            'amount' => $amountCents,
            'currency' => 'eur',
            'receipt_email' => $receiptEmail,
            'metadata' => $metadata,
        ];

        if ($stripeCustomerId !== null && $stripeCustomerId !== '') {
            $parameters['customer'] = $stripeCustomerId;
        }

        $idempotencyKey = $legacyContract
            ? 'pi_'.$orderId
            : 'pi_'.$orderId.'_'.$generation;
        $paymentIntent = $this->stripe->paymentIntents->create($parameters, [
            'idempotency_key' => $idempotencyKey,
        ]);

        return $this->safePaymentIntent($paymentIntent);
    }

    /**
     * @return array{
     *     id: string,
     *     status: string,
     *     client_secret: ?string,
     *     amount: int,
     *     amount_received: ?int,
     *     currency: string,
     *     created: ?int,
     *     customer: ?string,
     *     metadata: array{
     *         order_id: ?string,
     *         portal_user_id: ?string,
     *         account_created_at: ?string,
     *         checkout_idempotency_key: ?string,
     *         checkout_fingerprint: ?string,
     *         generation: ?string,
     *         amount_cents: ?string,
     *         currency: ?string
     *     }
     * }
     */
    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        return $this->safePaymentIntent(
            $this->stripe->paymentIntents->retrieve($paymentIntentId),
        );
    }

    /**
     * @return array{
     *     id: string,
     *     status: string,
     *     client_secret: ?string,
     *     amount: int,
     *     amount_received: ?int,
     *     currency: string,
     *     created: ?int,
     *     customer: ?string,
     *     metadata: array{
     *         order_id: ?string,
     *         portal_user_id: ?string,
     *         account_created_at: ?string,
     *         checkout_idempotency_key: ?string,
     *         checkout_fingerprint: ?string,
     *         generation: ?string,
     *         amount_cents: ?string,
     *         currency: ?string
     *     }
     * }
     */
    public function cancelPaymentIntent(string $paymentIntentId): array
    {
        return $this->safePaymentIntent(
            $this->stripe->paymentIntents->cancel($paymentIntentId, [
                'cancellation_reason' => PaymentIntent::CANCELLATION_REASON_ABANDONED,
            ]),
        );
    }

    public function retrievePaymentIntentWithFee(string $paymentIntentId): ?int
    {
        $intent = $this->stripe->paymentIntents->retrieve($paymentIntentId, [
            'expand' => ['latest_charge.balance_transaction'],
        ]);

        $latestCharge = $intent->latest_charge ?? null;
        $balanceTransaction = $latestCharge?->balance_transaction ?? null;
        if ($balanceTransaction === null || ! isset($balanceTransaction->fee)) {
            return null;
        }

        $fee = $balanceTransaction->fee;
        if (! is_numeric($fee)) {
            return null;
        }

        return max(0, (int) $fee);
    }

    /**
     * @return array{
     *     generation: int,
     *     portal_user_id: ?string,
     *     account_created_at: ?string,
     *     checkout_idempotency_key: ?string,
     *     checkout_fingerprint: ?string
     * }
     */
    private function resolveIntentContext(
        string $orderId,
        ?int $generation,
        int|string|null $portalUserId,
        int|string|null $accountCreatedAt,
        ?string $checkoutIdempotencyKey,
        ?string $checkoutFingerprint,
        ?Order $resolvedOrder = null,
    ): array {
        $needsOrder = $generation === null
            || $portalUserId === null
            || $accountCreatedAt === null
            || $checkoutIdempotencyKey === null
            || $checkoutFingerprint === null;
        $order = $resolvedOrder ?? ($needsOrder
            ? Order::query()->with('user')->find($orderId)
            : null);

        $resolvedKey = $checkoutIdempotencyKey ?? $order?->checkout_idempotency_key;
        $resolvedFingerprint = $checkoutFingerprint ?? $order?->checkout_fingerprint;

        return [
            'generation' => max(1, $generation ?? (int) ($order?->payment_intent_generation ?? 1)),
            'portal_user_id' => $this->nullableString($portalUserId ?? $order?->user_id),
            'account_created_at' => $this->nullableString(
                $accountCreatedAt ?? $order?->user?->created_at?->getTimestamp(),
            ),
            'checkout_idempotency_key' => $this->nullableString($resolvedKey),
            'checkout_fingerprint' => $this->nullableString($resolvedFingerprint),
        ];
    }

    /**
     * Only return fields required by the checkout identity checks. In
     * particular, Stripe's complete response (including any payment method
     * details) is never returned or logged.
     *
     * @return array{
     *     id: string,
     *     status: string,
     *     client_secret: ?string,
     *     amount: int,
     *     amount_received: ?int,
     *     currency: string,
     *     created: ?int,
     *     customer: ?string,
     *     metadata: array{
     *         order_id: ?string,
     *         portal_user_id: ?string,
     *         account_created_at: ?string,
     *         checkout_idempotency_key: ?string,
     *         checkout_fingerprint: ?string,
     *         generation: ?string,
     *         amount_cents: ?string,
     *         currency: ?string
     *     }
     * }
     */
    private function safePaymentIntent(StripeObject $paymentIntent): array
    {
        $metadata = $paymentIntent->metadata ?? [];

        $safeMetadata = [
            'order_id' => $this->metadataString($metadata, 'order_id'),
            'portal_user_id' => $this->metadataString($metadata, 'portal_user_id'),
            'account_created_at' => $this->metadataString($metadata, 'account_created_at'),
            'checkout_idempotency_key' => $this->metadataString($metadata, 'checkout_idempotency_key'),
            'checkout_fingerprint' => $this->metadataString($metadata, 'checkout_fingerprint'),
            'generation' => $this->metadataString($metadata, 'generation'),
            'amount_cents' => $this->metadataString($metadata, 'amount_cents'),
            'currency' => $this->metadataString($metadata, 'currency'),
        ];

        $safeMetadata = array_filter(
            $safeMetadata,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return [
            'id' => $this->nullableString($paymentIntent->id ?? null) ?? '',
            'status' => $this->nullableString($paymentIntent->status ?? null) ?? '',
            'client_secret' => $this->nullableString($paymentIntent->client_secret ?? null),
            'amount' => (int) ($paymentIntent->amount ?? 0),
            'amount_received' => is_numeric($paymentIntent->amount_received ?? null)
                ? (int) $paymentIntent->amount_received
                : null,
            'currency' => $this->nullableString($paymentIntent->currency ?? null) ?? '',
            'created' => is_numeric($paymentIntent->created ?? null)
                ? (int) $paymentIntent->created
                : null,
            'customer' => $this->customerId($paymentIntent->customer ?? null),
            'metadata' => $safeMetadata,
        ];
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

        return $this->nullableString($value);
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

        return $this->nullableString($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
