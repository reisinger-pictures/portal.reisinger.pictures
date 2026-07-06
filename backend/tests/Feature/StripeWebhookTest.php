<?php

namespace Tests\Feature;

use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
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

    public function test_valid_payment_intent_succeeded_fulfills_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stripe_payment_intent_id' => 'pi_test_intent_123',
        ]);
        $snapshot = InvoiceSnapshot::create([
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

        $secret = 'whsec_test_succeeded';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_intent_123',
                    'metadata' => ['order_id' => $order->id],
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $clientMock = $this->createStub(ClientInterface::class);
        $clientMock->method('request')
            ->willReturn([
                json_encode([
                    'id' => 'pi_test_intent_123',
                    'latest_charge' => [
                        'balance_transaction' => ['fee' => 150],
                    ],
                ]),
                200,
                [],
            ]);
        ApiRequestor::setHttpClient($clientMock);

        $response = $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'stripe_fee_cents' => 150,
        ]);
    }

    public function test_invalid_signature_returns_400(): void
    {
        Config::set('services.stripe.webhook_secret', 'whsec_secret');

        $response = $this->postJson('/api/webhooks/stripe', [
            'type' => 'test',
        ], [
            'Stripe-Signature' => 't=1234567890,v1=invalid_signature_that_does_not_match',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    public function test_invalid_payload_returns_400(): void
    {
        $secret = 'whsec_secret';
        Config::set('services.stripe.webhook_secret', $secret);

        $rawBody = 'this is not valid json at all!!!';
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
        $sigHeader = "t={$timestamp},v1={$signature}";

        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $sigHeader,
        ], $rawBody);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid payload']);
    }

    public function test_missing_signature_header_returns_400(): void
    {
        Config::set('services.stripe.webhook_secret', 'whsec_secret');

        $response = $this->postJson('/api/webhooks/stripe', [
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_test', 'metadata' => []]],
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    public function test_charge_dispute_created_marks_order_disputed(): void
    {
        $order = Order::factory()->create([
            'status' => 'paid',
            'stripe_payment_intent_id' => 'pi_dispute_123',
        ]);

        $secret = 'whsec_dispute_secret';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'charge.dispute.created',
            'data' => [
                'object' => [
                    'payment_intent' => 'pi_dispute_123',
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $response = $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'disputed',
        ]);
    }

    public function test_charge_refunded_marks_order_refunded(): void
    {
        $order = Order::factory()->create([
            'status' => 'paid',
            'stripe_payment_intent_id' => 'pi_refund_123',
        ]);

        $secret = 'whsec_refund_secret';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'payment_intent' => 'pi_refund_123',
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $response = $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'refunded',
        ]);
    }

    public function test_unknown_event_type_is_handled_gracefully(): void
    {
        $secret = 'whsec_unknown_secret';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'some.unknown.event',
            'data' => [
                'object' => ['id' => 'evt_unknown'],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $response = $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
    }

    public function test_duplicate_event_is_idempotent(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stripe_payment_intent_id' => 'pi_idempotent_123',
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

        $secret = 'whsec_idempotent';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_idempotent_123',
                    'metadata' => ['order_id' => $order->id],
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $clientMock = $this->createStub(ClientInterface::class);
        $clientMock->method('request')
            ->willReturn([
                json_encode([
                    'id' => 'pi_idempotent_123',
                    'latest_charge' => [
                        'balance_transaction' => ['fee' => 200],
                    ],
                ]),
                200,
                [],
            ]);
        ApiRequestor::setHttpClient($clientMock);

        // First call
        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'stripe_fee_cents' => 200,
        ]);

        // Second call — controller skips because status is already 'paid'
        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'stripe_fee_cents' => 200,
        ]);
    }

    public function test_payment_intent_succeeded_for_order_without_stripe_id_still_returns_success(): void
    {
        $secret = 'whsec_no_pi';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_no_order_matches',
                    'metadata' => ['order_id' => 'non-existent-order-id'],
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $response = $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
    }

    public function test_payment_intent_succeeded_with_multiple_secrets_processes_correctly(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stripe_payment_intent_id' => 'pi_multi_secret_123',
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

        $secret1 = 'whsec_first_secret';
        $secret2 = 'whsec_second_secret';
        Config::set('services.stripe.webhook_secret', "{$secret1},{$secret2}");

        $payloadData = [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_multi_secret_123',
                    'metadata' => ['order_id' => $order->id],
                ],
            ],
        ];

        // Sign with secret2 (the second one)
        $payload = json_encode($payloadData);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret2);
        $sigHeader = "t={$timestamp},v1={$signature}";

        $clientMock = $this->createStub(ClientInterface::class);
        $clientMock->method('request')
            ->willReturn([
                json_encode([
                    'id' => 'pi_multi_secret_123',
                    'latest_charge' => [
                        'balance_transaction' => ['fee' => 99],
                    ],
                ]),
                200,
                [],
            ]);
        ApiRequestor::setHttpClient($clientMock);

        $response = $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'stripe_fee_cents' => 99,
        ]);
    }
}
