<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Stripe\ApiRequestor;
use Tests\TestCase;

class WebhookNullPiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.stripe.secret', 'sk_test_mock');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function signPayload(string $secret, array $data): array
    {
        $payload = json_encode($data);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return [$payload, "t={$timestamp},v1={$signature}"];
    }

    public function test_dispute_with_null_payment_intent_does_not_modify_orders(): void
    {
        $secret = 'whsec_test';
        Config::set('services.stripe.webhook_secret', $secret);

        $order = Order::factory()->create([
            'status' => 'paid',
            'stripe_payment_intent_id' => 'pi_existing_123',
        ]);

        $payloadData = [
            'type' => 'charge.dispute.created',
            'data' => [
                'object' => [
                    'payment_intent' => null,
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $response = $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
        ]);
        $this->assertDatabaseMissing('orders', ['status' => 'disputed']);
    }

    public function test_refund_with_null_payment_intent_does_not_modify_orders(): void
    {
        $secret = 'whsec_test';
        Config::set('services.stripe.webhook_secret', $secret);

        $order = Order::factory()->create([
            'status' => 'paid',
            'stripe_payment_intent_id' => 'pi_existing_456',
        ]);

        $payloadData = [
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'payment_intent' => null,
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $response = $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
        ]);
        $this->assertDatabaseMissing('orders', ['status' => 'refunded']);
    }
}
