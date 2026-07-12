<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class WebhookReplayMailTest extends TestCase
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

    public function test_duplicate_payment_intent_succeeded_sends_only_one_invoice_mail(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => 'pi_replay_123',
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

        $secret = 'whsec_replay';
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_replay_123',
                    'amount_received' => 5000,
                    'metadata' => ['order_id' => $order->id],
                ],
            ],
        ];

        [$payload, $sigHeader] = $this->signPayload($secret, $payloadData);

        $clientMock = $this->createStub(ClientInterface::class);
        $clientMock->method('request')
            ->willReturn([
                json_encode([
                    'id' => 'pi_replay_123',
                    'latest_charge' => [
                        'balance_transaction' => ['fee' => 150],
                    ],
                ]),
                200,
                [],
            ]);
        ApiRequestor::setHttpClient($clientMock);

        // First delivery — order becomes paid, invoice mail is queued
        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertStatus(200);

        Mail::assertQueued(InvoiceMail::class, 1);

        // Second delivery — exact same event, order already paid, no additional mail
        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => $sigHeader,
        ])->assertStatus(200);

        Mail::assertQueued(InvoiceMail::class, 1);
    }
}
