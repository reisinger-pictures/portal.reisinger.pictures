<?php

namespace Tests\Feature;

use App\Mail\CustomMail;
use App\Mail\InvoiceMail;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use App\Support\CheckoutKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
        Cache::flush();
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

    public function test_success_with_missing_local_payment_intent_linkage_reconciles_and_binds_the_signed_pi(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_unlinked']);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => null,
            'checkout_idempotency_key' => 'checkout-unlinked-pi',
            'checkout_fingerprint' => hash('sha256', 'checkout-unlinked-pi'),
            'payment_intent_generation' => 1,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'name' => $user->name,
                'email' => $user->email,
                'items' => [['photoId' => 'unlinked-photo', 'tier' => 'web', 'price' => 5000]],
            ],
            'total_net' => 5000,
            'total_gross' => 5000,
            'tax_rate' => 0,
        ]);
        $secret = 'whsec_unlinked_success';
        Config::set('services.stripe.webhook_secret', $secret);
        $payload = [
            'id' => 'evt_unlinked_success',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_unlinked_success',
                'amount' => 5000,
                'currency' => 'eur',
                'amount_received' => 5000,
                'customer' => 'cus_unlinked',
                'metadata' => $this->metadataFor($order),
            ]],
        ];
        $clientMock = $this->createStub(ClientInterface::class);
        $clientMock->method('request')->willReturn([
            json_encode([
                'id' => 'pi_unlinked_success',
                'latest_charge' => ['balance_transaction' => ['fee' => 44]],
            ]),
            200,
            [],
        ]);
        ApiRequestor::setHttpClient($clientMock);
        [, $signature] = $this->signPayload($secret, $payload);

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk()
            ->assertJson(['status' => 'success']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'stripe_payment_intent_id' => 'pi_unlinked_success',
            'payment_intent_generation' => 1,
            'stripe_fee_cents' => 44,
        ]);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_missing_local_link_does_not_bypass_v036_identity_validation(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_unlinked_mismatch']);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => null,
            'checkout_idempotency_key' => 'checkout-unlinked-mismatch',
            'checkout_fingerprint' => hash('sha256', 'checkout-unlinked-mismatch'),
            'payment_intent_generation' => 1,
        ]);
        $secret = 'whsec_unlinked_mismatch';
        Config::set('services.stripe.webhook_secret', $secret);
        $metadata = array_replace($this->metadataFor($order), [
            'checkout_fingerprint' => str_repeat('f', 64),
        ]);
        $payload = [
            'id' => 'evt_unlinked_mismatch',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_unlinked_mismatch',
                'amount' => 5000,
                'currency' => 'eur',
                'amount_received' => 5000,
                'customer' => 'cus_unlinked_mismatch',
                'metadata' => $metadata,
            ]],
        ];
        [, $signature] = $this->signPayload($secret, $payload);

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk()
            ->assertJson(['status' => 'ignored', 'reason' => 'metadata_fingerprint_mismatch']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
            'stripe_payment_intent_id' => null,
        ]);
    }

    public function test_valid_payment_intent_succeeded_fulfills_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => 'pi_test_intent_123',
            'checkout_idempotency_key' => 'checkout-pi-test-intent-123',
            'checkout_fingerprint' => hash('sha256', 'pi_test_intent_123'),
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
                    'amount' => 5000,
                    'currency' => 'eur',
                    'amount_received' => 5000,
                    'metadata' => $this->metadataFor($order),
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

    public function test_underpaid_payment_intent_does_not_mark_order_paid(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => 'pi_test_intent_underpaid',
            'checkout_idempotency_key' => 'checkout-pi-test-intent-underpaid',
            'checkout_fingerprint' => hash('sha256', 'pi_test_intent_underpaid'),
        ]);

        $secret = 'whsec_test_underpaid';
        Config::set('services.stripe.webhook_secret', $secret);

        // Attacker pays 1 cent for a 50.00 EUR order via a manipulated intent
        $payloadData = [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_intent_underpaid',
                    'amount' => 5000,
                    'currency' => 'eur',
                    'amount_received' => 1,
                    'metadata' => $this->metadataFor($order),
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $clientMock = $this->createStub(ClientInterface::class);
        $clientMock->method('request')
            ->willReturn([
                json_encode([
                    'id' => 'pi_test_intent_underpaid',
                    'latest_charge' => [
                        'balance_transaction' => ['fee' => 0],
                    ],
                ]),
                200,
                [],
            ]);
        ApiRequestor::setHttpClient($clientMock);

        $response = $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ]);

        // 200 so Stripe does not retry, but the order must stay pending
        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored', 'reason' => 'underpaid']);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
        ]);
        $this->assertDatabaseMissing('orders', [
            'id' => $order->id,
            'status' => 'paid',
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
                    'refunded' => true,
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

    public function test_dispute_created_webhook_is_idempotent(): void
    {
        $order = Order::factory()->create([
            'status' => 'paid',
            'stripe_payment_intent_id' => 'pi_dispute_idemp_123',
        ]);

        $secret = 'whsec_dispute_idemp';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'charge.dispute.created',
            'data' => [
                'object' => [
                    'payment_intent' => 'pi_dispute_idemp_123',
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'disputed',
        ]);

        Mail::assertQueued(CustomMail::class, 1);

        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'disputed',
        ]);

        Mail::assertQueued(CustomMail::class, 1);
    }

    public function test_refunded_webhook_is_idempotent(): void
    {
        $order = Order::factory()->create([
            'status' => 'paid',
            'stripe_payment_intent_id' => 'pi_refund_idemp_123',
        ]);

        $secret = 'whsec_refund_idemp';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'payment_intent' => 'pi_refund_idemp_123',
                    'refunded' => true,
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'refunded',
        ]);

        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertStatus(200);

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

    public function test_success_accepts_a_legacy_pending_order_without_v036_identity_metadata(): void
    {
        $user = User::factory()->create();
        $order = $this->legacyOrder($user, 'pi_legacy_success');
        $secret = 'whsec_legacy_success';
        Config::set('services.stripe.webhook_secret', $secret);

        $clientMock = $this->createStub(ClientInterface::class);
        $clientMock->method('request')->willReturn([
            json_encode([
                'id' => 'pi_legacy_success',
                'latest_charge' => ['balance_transaction' => ['fee' => 25]],
            ]),
            200,
            [],
        ]);
        ApiRequestor::setHttpClient($clientMock);

        $payload = [
            'id' => 'evt_legacy_success',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_legacy_success',
                'amount' => 5000,
                'currency' => 'eur',
                'amount_received' => 5000,
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ];
        [, $signature] = $this->signPayload($secret, $payload);

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_failure_accepts_a_legacy_pending_order_without_v036_identity_metadata(): void
    {
        $user = User::factory()->create();
        $order = $this->legacyOrder($user, 'pi_legacy_failure');
        $secret = 'whsec_legacy_failure';
        Config::set('services.stripe.webhook_secret', $secret);
        $payload = [
            'id' => 'evt_legacy_failure',
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_legacy_failure',
                'status' => 'requires_payment_method',
                'amount' => 5000,
                'currency' => 'eur',
                'metadata' => ['order_id' => (string) $order->id],
                'last_payment_error' => ['type' => 'card_error', 'code' => 'card_declined'],
            ]],
        ];
        [, $signature] = $this->signPayload($secret, $payload);

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
            'payment_failure_count' => 1,
        ]);
    }

    public function test_legacy_success_accepts_a_missing_customer_after_user_mapping_is_added(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_legacy_later']);
        $order = $this->legacyOrder($user, 'pi_legacy_customer_later');
        $secret = 'whsec_legacy_customer_later';
        Config::set('services.stripe.webhook_secret', $secret);

        $clientMock = $this->createStub(ClientInterface::class);
        $clientMock->method('request')->willReturn([
            json_encode([
                'id' => 'pi_legacy_customer_later',
                'latest_charge' => ['balance_transaction' => ['fee' => 31]],
            ]),
            200,
            [],
        ]);
        ApiRequestor::setHttpClient($clientMock);
        $payload = $this->successPayload($order, [], 'evt_legacy_customer_later');
        [, $signature] = $this->signPayload($secret, $payload);

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_legacy_success_rejects_a_supplied_customer_that_conflicts_with_later_mapping(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_legacy_later']);
        $order = $this->legacyOrder($user, 'pi_legacy_customer_mismatch');
        $secret = 'whsec_legacy_customer_mismatch';
        Config::set('services.stripe.webhook_secret', $secret);
        $payload = $this->successPayload(
            $order,
            ['customer' => 'cus_attacker'],
            'evt_legacy_customer_mismatch'
        );
        [, $signature] = $this->signPayload($secret, $payload);

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertJson(['status' => 'ignored', 'reason' => 'customer_mismatch']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    public function test_new_live_order_rejects_a_null_customer_mapping(): void
    {
        $user = User::factory()->create();
        $order = $this->identityOrder($user, 'pi_live_customer_guard');
        $secret = 'whsec_live_customer_guard';
        Config::set('services.stripe.webhook_secret', $secret);
        $payload = $this->successPayload($order, ['customer' => null], 'evt_live_customer_guard');
        [, $signature] = $this->signPayload($secret, $payload);
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
                ->assertJson(['status' => 'ignored', 'reason' => 'customer_mismatch']);
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    public function test_mismatched_legacy_payment_is_not_fulfilled(): void
    {
        $user = User::factory()->create();
        $order = $this->legacyOrder($user, 'pi_legacy_mismatch');
        $secret = 'whsec_legacy_mismatch';
        Config::set('services.stripe.webhook_secret', $secret);
        $payload = [
            'id' => 'evt_legacy_mismatch',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_legacy_mismatch',
                'amount' => 4999,
                'currency' => 'eur',
                'amount_received' => 4999,
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ];
        [, $signature] = $this->signPayload($secret, $payload);

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertJson(['status' => 'ignored', 'reason' => 'amount_mismatch']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    public function test_mismatched_legacy_identity_is_not_fulfilled(): void
    {
        $user = User::factory()->create();
        $order = $this->legacyOrder($user, 'pi_legacy_identity_mismatch');
        $secret = 'whsec_legacy_identity_mismatch';
        Config::set('services.stripe.webhook_secret', $secret);
        $payload = [
            'id' => 'evt_legacy_identity_mismatch',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_legacy_identity_mismatch',
                'amount' => 5000,
                'currency' => 'eur',
                'amount_received' => 5000,
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'checkout_idempotency_key' => 'unexpected-new-key',
                ],
            ]],
        ];
        [, $signature] = $this->signPayload($secret, $payload);

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertJson(['status' => 'ignored', 'reason' => 'metadata_key_mismatch']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    public function test_duplicate_event_is_idempotent(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => 'pi_idempotent_123',
            'checkout_idempotency_key' => 'checkout-pi-idempotent-123',
            'checkout_fingerprint' => hash('sha256', 'pi_idempotent_123'),
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
                    'amount' => 5000,
                    'currency' => 'eur',
                    'amount_received' => 5000,
                    'metadata' => $this->metadataFor($order),
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

    public function test_success_event_claim_is_released_when_the_paid_transition_fails(): void
    {
        $user = User::factory()->create();
        $order = $this->identityOrder($user, 'pi_success_db_retry');
        $secret = 'whsec_success_db_retry';
        Config::set('services.stripe.webhook_secret', $secret);

        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturn([
                json_encode([
                    'id' => 'pi_success_db_retry',
                    'latest_charge' => ['balance_transaction' => ['fee' => 12]],
                ]),
                200,
                [],
            ]);
        ApiRequestor::setHttpClient($clientMock);

        $payload = $this->successPayload($order, [], 'evt_success_db_retry');
        [, $signature] = $this->signPayload($secret, $payload);
        $failedTransition = false;
        DB::listen(function (QueryExecuted $query) use (&$failedTransition): void {
            $sql = strtolower($query->sql);
            if (! $failedTransition && str_contains($sql, 'update') && str_contains($sql, 'orders')) {
                $failedTransition = true;
                throw new \RuntimeException('simulated transient database failure');
            }
        });

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.231'])
            ->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertStatus(503);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.232'])
            ->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_success_with_unavailable_fee_pays_order_and_completes_event_claim(): void
    {
        $user = User::factory()->create();
        $order = $this->identityOrder($user, 'pi_success_fee_fallback');
        $secret = 'whsec_success_fee_fallback';
        Config::set('services.stripe.webhook_secret', $secret);

        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->once())
            ->method('request')
            ->willReturn([
                json_encode(['id' => 'pi_success_fee_fallback']),
                200,
                [],
            ]);
        ApiRequestor::setHttpClient($clientMock);

        $payload = $this->successPayload($order, [], 'evt_success_fee_fallback');
        [, $signature] = $this->signPayload($secret, $payload);
        Log::spy();

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'stripe_fee_cents' => null,
        ]);
        $this->assertSame('processed', Cache::get('stripe-webhook-event:evt_success_fee_fallback'));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'PaymentIntent reconciliation: expanded fee unavailable; using payout fallback')
            ->once();
    }

    public function test_payment_intent_succeeded_with_multiple_secrets_processes_correctly(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => 'pi_multi_secret_123',
            'checkout_idempotency_key' => 'checkout-pi-multi-secret-123',
            'checkout_fingerprint' => hash('sha256', 'pi_multi_secret_123'),
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
                    'amount' => 5000,
                    'currency' => 'eur',
                    'amount_received' => 5000,
                    'metadata' => $this->metadataFor($order),
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

    public function test_payment_failure_is_recorded_once_without_changing_order_status(): void
    {
        $order = Order::factory()->create([
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => 'pi_failure_123',
            'checkout_idempotency_key' => 'checkout-pi-failure-123',
            'checkout_fingerprint' => hash('sha256', 'pi_failure_123'),
        ]);
        $secret = 'whsec_payment_failure';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'id' => 'evt_payment_failure_123',
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_failure_123',
                    'status' => 'requires_payment_method',
                    'amount' => 5000,
                    'currency' => 'eur',
                    'metadata' => $this->metadataFor($order),
                    'last_payment_error' => [
                        'type' => 'card_error',
                        'code' => 'card_declined',
                        'decline_code' => 'generic_decline',
                    ],
                ],
            ],
        ];
        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertOk();

        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
            'payment_failure_count' => 1,
            'last_payment_decline_code' => 'generic_decline',
        ]);
        $this->assertNotNull($order->fresh()->last_payment_failure_at);
    }

    public function test_verified_failures_use_the_persisted_order_ip_not_webhook_ingress_ip(): void
    {
        $user = User::factory()->create();
        $order = $this->identityOrder($user, 'pi_failure_customer_ip');
        $order->update(['ip_address' => '198.51.100.77']);
        $secret = 'whsec_failure_customer_ip';
        Config::set('services.stripe.webhook_secret', $secret);

        foreach ([
            ['event_id' => 'evt_failure_customer_ip_1', 'webhook_ip' => '198.51.100.201'],
            ['event_id' => 'evt_failure_customer_ip_2', 'webhook_ip' => '198.51.100.202'],
        ] as $attempt) {
            $payload = $this->failurePayload($order, [], $attempt['event_id']);
            [, $signature] = $this->signPayload($secret, $payload);

            $this->withServerVariables(['REMOTE_ADDR' => $attempt['webhook_ip']])
                ->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
                ->assertOk();
        }

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_failure_count' => 2,
        ]);
        $this->assertSame(2, (int) RateLimiter::attempts(
            CheckoutKey::ip('198.51.100.77', 'checkout-failure')
        ));
        $this->assertSame(2, (int) RateLimiter::attempts(
            CheckoutKey::user($user->getKey(), 'checkout-failure')
        ));
        $this->assertSame(0, (int) RateLimiter::attempts(
            CheckoutKey::ip('198.51.100.201', 'checkout-failure')
        ));
        $this->assertSame(0, (int) RateLimiter::attempts(
            CheckoutKey::ip('198.51.100.202', 'checkout-failure')
        ));
    }

    public function test_failure_event_claim_is_released_when_telemetry_update_fails(): void
    {
        $user = User::factory()->create();
        $order = $this->identityOrder($user, 'pi_failure_db_retry');
        $secret = 'whsec_failure_db_retry';
        Config::set('services.stripe.webhook_secret', $secret);
        $payload = $this->failurePayload($order, [], 'evt_failure_db_retry');
        [, $signature] = $this->signPayload($secret, $payload);

        $failedUpdate = false;
        DB::listen(function (QueryExecuted $query) use (&$failedUpdate): void {
            $sql = strtolower($query->sql);
            if (! $failedUpdate && str_contains($sql, 'update') && str_contains($sql, 'orders')) {
                $failedUpdate = true;
                throw new \RuntimeException('simulated transient telemetry failure');
            }
        });

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.233'])
            ->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertStatus(503);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_failure_count' => 0]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.234'])
            ->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_failure_count' => 1]);
    }

    public function test_success_from_obsolete_payment_intent_cannot_fulfill_current_order(): void
    {
        $order = Order::factory()->create([
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => 'pi_generation_2',
            'checkout_idempotency_key' => 'checkout-pi-generation-2',
            'checkout_fingerprint' => hash('sha256', 'pi_generation_2'),
        ]);
        $secret = 'whsec_obsolete_success';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'id' => 'evt_obsolete_success',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_generation_1',
                    'amount' => 5000,
                    'currency' => 'eur',
                    'amount_received' => 5000,
                    'metadata' => $this->metadataFor($order),
                ],
            ],
        ];
        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $response = $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ignored', 'reason' => 'obsolete_payment_intent']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
        ]);
        $this->assertDatabaseMissing('orders', [
            'id' => $order->id,
            'status' => 'paid',
        ]);
    }

    public function test_success_rejects_each_server_owned_identity_mismatch(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_expected']);
        $order = $this->identityOrder($user, 'pi_identity_guard');
        $secret = 'whsec_identity_guard';
        Config::set('services.stripe.webhook_secret', $secret);

        $cases = [
            'metadata_key_mismatch' => ['metadata' => array_replace($this->metadataFor($order), [
                'checkout_idempotency_key' => 'different-key',
            ])],
            'metadata_key_missing' => ['metadata' => array_diff_key(
                $this->metadataFor($order),
                ['checkout_idempotency_key' => true],
            )],
            'metadata_fingerprint_mismatch' => ['metadata' => array_replace($this->metadataFor($order), [
                'checkout_fingerprint' => str_repeat('e', 64),
            ])],
            'metadata_fingerprint_missing' => ['metadata' => array_diff_key(
                $this->metadataFor($order),
                ['checkout_fingerprint' => true],
            )],
            'generation_mismatch' => ['metadata' => array_replace($this->metadataFor($order), [
                'generation' => '2',
            ])],
            'metadata_amount_mismatch' => ['metadata' => array_replace($this->metadataFor($order), [
                'amount_cents' => '4999',
            ])],
            'metadata_currency_mismatch' => ['metadata' => array_replace($this->metadataFor($order), [
                'currency' => 'usd',
            ])],
            'amount_mismatch' => ['amount' => 4999],
            'currency_mismatch' => ['currency' => 'usd'],
            'underpaid' => ['amount_received' => 4999],
            'customer_mismatch' => ['customer' => 'cus_attacker'],
            'customer_missing' => ['customer' => null],
            'user_mismatch' => ['metadata' => array_replace($this->metadataFor($order), [
                'portal_user_id' => (string) User::factory()->create()->getKey(),
            ])],
        ];

        $caseIndex = 0;
        foreach ($cases as $reason => $objectOverrides) {
            $caseIndex++;
            $payloadData = $this->successPayload(
                $order,
                $objectOverrides,
                'evt_identity_guard_'.$reason,
            );
            [, $sigHeader] = $this->signPayload($secret, $payloadData);

            $expectedReason = match ($reason) {
                'metadata_key_missing', 'metadata_fingerprint_missing', 'customer_missing' => str_replace('_missing', '_mismatch', $reason),
                default => $reason,
            };
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(200 + $caseIndex)])
                ->postJson('/api/webhooks/stripe', $payloadData, [
                    'Stripe-Signature' => $sigHeader,
                ])->assertJson(['status' => 'ignored', 'reason' => $expectedReason]);
        }

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
        ]);
    }

    public function test_success_accepts_only_the_mapped_customer_and_transitions_conditionally(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_expected']);
        $order = $this->identityOrder($user, 'pi_customer_guard');
        $secret = 'whsec_customer_guard';
        Config::set('services.stripe.webhook_secret', $secret);

        $clientMock = $this->createStub(ClientInterface::class);
        $clientMock->method('request')->willReturn([
            json_encode([
                'id' => 'pi_customer_guard',
                'latest_charge' => ['balance_transaction' => ['fee' => 77]],
            ]),
            200,
            [],
        ]);
        ApiRequestor::setHttpClient($clientMock);

        $payloadData = $this->successPayload($order, ['customer' => 'cus_expected'], 'evt_customer_guard');
        [, $sigHeader] = $this->signPayload($secret, $payloadData);
        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'stripe_fee_cents' => 77,
        ]);
    }

    public function test_failure_identity_dedupe_saturation_and_sanitized_code(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_expected']);
        $order = $this->identityOrder($user, 'pi_failure_guard');
        $secret = 'whsec_failure_guard';
        Config::set('services.stripe.webhook_secret', $secret);

        $first = $this->failurePayload($order, [
            'last_payment_error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'generic_decline',
            ],
        ], 'evt_failure_guard_first');
        [, $signature] = $this->signPayload($secret, $first);
        $this->postJson('/api/webhooks/stripe', $first, ['Stripe-Signature' => $signature])->assertOk();
        $this->postJson('/api/webhooks/stripe', $first, ['Stripe-Signature' => $signature])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_failure_count' => 1]);

        $longCode = str_repeat('x', 100);
        $second = $this->failurePayload($order, [
            'last_payment_error' => ['decline_code' => $longCode],
        ], 'evt_failure_guard_second');
        [, $signature] = $this->signPayload($secret, $second);
        $this->postJson('/api/webhooks/stripe', $second, ['Stripe-Signature' => $signature])->assertOk();
        $fresh = $order->fresh();
        $this->assertSame(2, $fresh->payment_failure_count);
        $this->assertSame(64, strlen((string) $fresh->last_payment_decline_code));

        $order->update(['payment_failure_count' => 255]);
        $third = $this->failurePayload($order, [], 'evt_failure_guard_saturated');
        [, $signature] = $this->signPayload($secret, $third);
        $this->postJson('/api/webhooks/stripe', $third, ['Stripe-Signature' => $signature])->assertOk();
        $this->assertSame(255, $order->fresh()->payment_failure_count);

        $mismatched = $this->failurePayload($order, [
            'metadata' => array_replace($this->metadataFor($order), ['generation' => '99']),
        ], 'evt_failure_guard_mismatch');
        [, $signature] = $this->signPayload($secret, $mismatched);
        $this->postJson('/api/webhooks/stripe', $mismatched, ['Stripe-Signature' => $signature])->assertOk();
        $this->assertSame(255, $order->fresh()->payment_failure_count);
    }

    public function test_failure_without_event_id_is_safe_and_incremented_once(): void
    {
        $user = User::factory()->create();
        $order = $this->identityOrder($user, 'pi_failure_without_event_id');
        $secret = 'whsec_failure_without_event_id';
        Config::set('services.stripe.webhook_secret', $secret);
        $payload = $this->failurePayload($order, [], null);
        [, $signature] = $this->signPayload($secret, $payload);

        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk();
        $this->postJson('/api/webhooks/stripe', $payload, ['Stripe-Signature' => $signature])
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_failure_count' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function successPayload(Order $order, array $overrides = [], ?string $eventId = 'evt_success_guard'): array
    {
        $object = array_replace([
            'id' => $order->stripe_payment_intent_id,
            'amount' => $order->total_amount,
            'currency' => 'eur',
            'amount_received' => $order->total_amount,
            'metadata' => $this->metadataFor($order),
        ], $overrides);

        $payload = [
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => $object],
        ];
        if ($eventId !== null) {
            $payload['id'] = $eventId;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function failurePayload(Order $order, array $overrides = [], ?string $eventId = 'evt_failure_guard'): array
    {
        $object = array_replace([
            'id' => $order->stripe_payment_intent_id,
            'status' => 'requires_payment_method',
            'amount' => $order->total_amount,
            'currency' => 'eur',
            'metadata' => $this->metadataFor($order),
            'customer' => $order->user?->stripe_customer_id,
            'last_payment_error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
            ],
        ], $overrides);

        $payload = [
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => $object],
        ];
        if ($eventId !== null) {
            $payload['id'] = $eventId;
        }

        return $payload;
    }

    private function legacyOrder(User $user, string $paymentIntentId): Order
    {
        return Order::factory()->create([
            'user_id' => $user->getKey(),
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => $paymentIntentId,
            'checkout_idempotency_key' => null,
            'checkout_fingerprint' => null,
            'payment_intent_generation' => 1,
        ]);
    }

    private function identityOrder(User $user, string $paymentIntentId): Order
    {
        return Order::factory()->create([
            'user_id' => $user->getKey(),
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => $paymentIntentId,
            'checkout_idempotency_key' => 'checkout-'.$paymentIntentId,
            'checkout_fingerprint' => hash('sha256', $paymentIntentId),
            'payment_intent_generation' => 1,
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function metadataFor(Order $order): array
    {
        return [
            'order_id' => (string) $order->getKey(),
            'checkout_idempotency_key' => $order->checkout_idempotency_key,
            'checkout_fingerprint' => $order->checkout_fingerprint,
            'generation' => (string) $order->payment_intent_generation,
            'portal_user_id' => (string) $order->user_id,
            'account_created_at' => (string) $order->user->created_at->getTimestamp(),
            'amount_cents' => (string) $order->total_amount,
            'currency' => 'eur',
        ];
    }
}
