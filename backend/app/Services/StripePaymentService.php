<?php

namespace App\Services;

use Stripe\StripeClient;

class StripePaymentService
{
    private StripeClient $stripe;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    public function createPaymentIntent(int $amountCents, string $orderId, string $receiptEmail): array
    {
        $paymentIntent = $this->stripe->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => 'eur',
            'metadata' => ['order_id' => $orderId],
            'receipt_email' => $receiptEmail,
        ], ['idempotency_key' => 'pi_' . $orderId]);

        return [
            'id' => $paymentIntent->id,
            'client_secret' => $paymentIntent->client_secret,
        ];
    }

    public function retrievePaymentIntentWithFee(string $paymentIntentId): int
    {
        $intent = $this->stripe->paymentIntents->retrieve($paymentIntentId, [
            'expand' => ['latest_charge.balance_transaction'],
        ]);

        return (int) ($intent->latest_charge->balance_transaction->fee ?? 0);
    }
}
