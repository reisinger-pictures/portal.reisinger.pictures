<?php

namespace Tests\Feature;

use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * PAY-1: the dispute notification must not use the order status transition as
 * its mail idempotency key. A failed enqueue after the order is already
 * `disputed` has to remain retryable through a durable snapshot claim.
 */
class DisputeMailRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.stripe.secret', 'sk_test_mock');
        Cache::flush();
    }

    private function makeDisputedOrder(): Order
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'paid',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => 'pi_dispute_retry',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'name' => $user->name,
                'email' => $user->email,
                'items' => [],
            ],
            'total_net' => 5000,
            'total_gross' => 5000,
            'tax_rate' => 0,
        ]);

        return $order;
    }

    private function signedPayload(string $secret): array
    {
        $payload = [
            'id' => 'evt_dispute_retry',
            'type' => 'charge.dispute.created',
            'data' => [
                'object' => [
                    'id' => 'dp_dispute_retry',
                    'payment_intent' => 'pi_dispute_retry',
                ],
            ],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return [$payload, 't='.$timestamp.',v1='.$signature];
    }

    public function test_failed_dispute_enqueue_stays_retryable_after_the_order_is_disputed(): void
    {
        $order = $this->makeDisputedOrder();
        $secret = 'whsec_dispute_retry';
        Config::set('services.stripe.webhook_secret', $secret);
        [$payload, $signature] = $this->signedPayload($secret);

        $attempts = 0;
        $enqueued = 0;
        $pendingMail = Mockery::mock(PendingMail::class);
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('to')->andReturn($pendingMail);
        $pendingMail->shouldReceive('queue')->andReturnUsing(function () use (&$attempts, &$enqueued) {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('smtp unavailable');
            }

            $enqueued++;

            return null;
        });
        $originalMail = Mail::getFacadeRoot();
        Mail::swap($manager);

        try {
            $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
                ->assertStatus(503);

            $this->assertSame(1, $attempts);
            $this->assertSame(0, $enqueued);
            $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'disputed']);
            $this->assertFalse($order->invoiceSnapshot()->firstOrFail()->disputeMailDispatchClaimed());
            // The failed attempt must release the event claim so Stripe's retry
            // is processed again.
            $this->assertNull(Cache::get('stripe-webhook-event:evt_dispute_retry'));

            $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
                ->assertOk();

            $this->assertSame(2, $attempts);
            $this->assertSame(1, $enqueued);
            $this->assertTrue($order->invoiceSnapshot()->firstOrFail()->disputeMailDispatchClaimed());
        } finally {
            Mail::swap($originalMail);
        }
    }

    public function test_duplicate_dispute_event_is_processed_exactly_once(): void
    {
        $this->makeDisputedOrder();
        $secret = 'whsec_dispute_dedupe';
        Config::set('services.stripe.webhook_secret', $secret);
        [$payload, $signature] = $this->signedPayload($secret);

        $dispatcher = Mockery::mock(\App\Services\DisputeMailDispatcher::class);
        $dispatcher->shouldReceive('queueOnce')->once()->andReturnTrue();
        $this->app->instance(\App\Services\DisputeMailDispatcher::class, $dispatcher);

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk();
        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk();
    }
}
