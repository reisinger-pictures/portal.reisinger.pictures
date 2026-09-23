<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * P0-B8 (MEDIUM) — the invoice-mail dedupe must be atomic so two concurrent
 * duplicate `payment_intent.succeeded` webhooks cannot double-send.
 */
class WebhookInvoiceMailAtomicDedupeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.stripe.secret', 'sk_test_mock');
        Mail::fake();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => 'pi_atomic_123',
            'checkout_idempotency_key' => 'checkout-pi-atomic-123',
            'checkout_fingerprint' => hash('sha256', 'pi_atomic_123'),
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'name' => $user->name,
                'email' => $user->email,
                'items' => [
                    ['photoId' => 'test-photo-1', 'tier' => 'original', 'price' => 5000],
                ],
            ],
            'total_net' => 5000,
            'total_gross' => 5000,
            'tax_rate' => 0,
        ]);

        return $order;
    }

    private function signedPayload(array $data, string $secret): array
    {
        $payload = json_encode($data);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return [$payload, "t={$timestamp},v1={$signature}"];
    }

    private function mockStripeFee(): void
    {
        $clientMock = $this->createStub(ClientInterface::class);
        $clientMock->method('request')->willReturn([
            json_encode([
                'id' => 'pi_atomic_123',
                'latest_charge' => ['balance_transaction' => ['fee' => 150]],
            ]),
            200,
            [],
        ]);
        ApiRequestor::setHttpClient($clientMock);
    }

    public function test_losing_the_dedupe_race_does_not_send_invoice_mail(): void
    {
        $order = $this->makeOrder();
        $secret = 'whsec_atomic';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_atomic_123',
                    'amount' => 5000,
                    'currency' => 'eur',
                    'amount_received' => 5000,
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'checkout_idempotency_key' => (string) $order->checkout_idempotency_key,
                        'checkout_fingerprint' => (string) $order->checkout_fingerprint,
                        'generation' => (string) $order->payment_intent_generation,
                        'portal_user_id' => (string) $order->user_id,
                        'account_created_at' => (string) $order->user->created_at->getTimestamp(),
                        'amount_cents' => (string) $order->total_amount,
                        'currency' => 'eur',
                    ],
                ],
            ],
        ];
        [, $sigHeader] = $this->signedPayload($payloadData, $secret);

        $this->mockStripeFee();

        // Simulate a concurrent webhook that already claimed the send slot:
        // the durable marker is already visible, so this delivery must not
        // queue a mail. Every other cache operation (e.g. the throttle
        // limiter) still hits a real array store.
        $raceKey = 'invoice_sent_'.$order->id;
        Cache::extend('race_test', function () use ($raceKey) {
            return Cache::repository(new class($raceKey) extends ArrayStore
            {
                public function __construct(private string $raceKey)
                {
                    parent::__construct();
                }

                public function get($key)
                {
                    if ($key === $this->raceKey) {
                        return true;
                    }

                    return parent::get($key);
                }
            });
        });
        Config::set('cache.stores.race_test', ['driver' => 'race_test']);
        Config::set('cache.default', 'race_test');

        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertStatus(200);

        Mail::assertNotQueued(InvoiceMail::class);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_paid_order_retry_retries_mail_when_queue_throws(): void
    {
        $order = $this->makeOrder();
        $secret = 'whsec_mail_retry';
        Config::set('services.stripe.webhook_secret', $secret);
        $payload = [
            'id' => 'evt_mail_retry',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_atomic_123',
                'amount' => 5000,
                'currency' => 'eur',
                'amount_received' => 5000,
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'checkout_idempotency_key' => (string) $order->checkout_idempotency_key,
                    'checkout_fingerprint' => (string) $order->checkout_fingerprint,
                    'generation' => (string) $order->payment_intent_generation,
                    'portal_user_id' => (string) $order->user_id,
                    'account_created_at' => (string) $order->user->created_at->getTimestamp(),
                    'amount_cents' => (string) $order->total_amount,
                    'currency' => 'eur',
                ],
            ]],
        ];
        [, $signature] = $this->signedPayload($payload, $secret);
        $this->mockStripeFee();

        $mailCalls = 0;
        $originalMail = Mail::getFacadeRoot();
        $pendingMail = Mockery::mock(PendingMail::class);
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('to')->twice()->andReturn($pendingMail);
        $pendingMail->shouldReceive('queue')->twice()->andReturnUsing(function () use (&$mailCalls) {
            $mailCalls++;
            if ($mailCalls === 1) {
                throw new \RuntimeException('queue unavailable');
            }

            return null;
        });
        Mail::swap($manager);

        try {
            $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
                ->assertStatus(503);
            $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
            $this->assertFalse(Cache::has('invoice_sent_'.$order->id));
            $this->assertNull(Cache::get('stripe-webhook-event:evt_mail_retry'));

            $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
                ->assertOk();
            $this->assertTrue(Cache::has('invoice_sent_'.$order->id));
        } finally {
            Mail::swap($originalMail);
        }
    }

    public function test_first_delivery_sends_mail_and_claims_the_slot(): void
    {
        $order = $this->makeOrder();
        $secret = 'whsec_atomic_first';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_atomic_123',
                    'amount' => 5000,
                    'currency' => 'eur',
                    'amount_received' => 5000,
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'checkout_idempotency_key' => (string) $order->checkout_idempotency_key,
                        'checkout_fingerprint' => (string) $order->checkout_fingerprint,
                        'generation' => (string) $order->payment_intent_generation,
                        'portal_user_id' => (string) $order->user_id,
                        'account_created_at' => (string) $order->user->created_at->getTimestamp(),
                        'amount_cents' => (string) $order->total_amount,
                        'currency' => 'eur',
                    ],
                ],
            ],
        ];
        [, $sigHeader] = $this->signedPayload($payloadData, $secret);

        $this->mockStripeFee();

        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertStatus(200);

        Mail::assertQueued(InvoiceMail::class, 1);
        $this->assertTrue(Cache::has('invoice_sent_'.$order->id));
    }
}
