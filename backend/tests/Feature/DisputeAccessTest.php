<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DisputeAccessTest extends TestCase {
    use RefreshDatabase;

    public function test_webhook_dispute_locks_order_status() {
        \Illuminate\Support\Facades\Mail::fake();
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'paid',
            'total_amount' => 100.00,
            'stripe_payment_intent_id' => 'pi_dispute_test_123'
        ]);

        $secret = 'whsec_test_secret';
        config(['services.stripe.webhook_secret' => $secret]);

        $payload = json_encode([
            'type' => 'charge.dispute.created',
            'data' => [
                'object' => [
                    'payment_intent' => 'pi_dispute_test_123'
                ]
            ]
        ]);

        // Konstruktion eines kryptografisch korrekten Stripe Webhook Signatur-Headers
        $timestamp = time();
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac('sha256', $signedPayload, $secret);
        $header = "t={$timestamp},v1={$signature}";

        $response = $this->withHeaders([
            'Stripe-Signature' => $header
        ])->postJson('/api/webhooks/stripe', json_decode($payload, true));

        $response->assertStatus(200);

        // Verifizieren, ob die Bestellung auf 'disputed' gesetzt wurde
        $order->refresh();
        $this->assertEquals('disputed', $order->status);
    }
}
