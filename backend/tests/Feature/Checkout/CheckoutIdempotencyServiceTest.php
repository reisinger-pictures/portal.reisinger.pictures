<?php

namespace Tests\Feature\Checkout;

use App\Enums\Brand;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutIdempotencyService;
use App\Services\StripePaymentService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

class CheckoutIdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['app.checkout_idempotency_ttl_minutes' => 30]);
    }

    public function test_same_key_and_payload_reuses_pending_payment_intent(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-0001', str_repeat('a', 64));
        InvoiceSnapshot::factory()->for($order)->create();

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_pending')
            ->willReturn($this->intent('pi_pending', $order));
        $stripe->expects($this->never())->method('cancelPaymentIntent');

        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->execute(
            $user,
            'checkout-key-0001',
            str_repeat('a', 64),
            4000,
            fn () => $this->fail('Creator must not run for a reusable pending PaymentIntent.'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pi_pending_secret', $response->getData(true)['client_secret']);
        $this->assertSame($order->id, $response->getData(true)['order_id']);
    }

    public function test_null_brand_positive_payment_intent_claim_fails_closed(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => 'null-brand-photo']],
            'billing_name' => 'Tester',
        ]);
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('retrievePaymentIntent');
        $service = new CheckoutIdempotencyService($stripe);
        $identity = $service->identify($request, $user, 4000, 'stripe');
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => null,
            'status' => 'pending_payment',
            'total_amount' => 4000,
            'stripe_payment_intent_id' => 'pi_null_brand',
            'checkout_idempotency_key' => $identity['key'],
            'checkout_fingerprint' => $identity['fingerprint'],
        ]);
        InvoiceSnapshot::factory()->for($order)->create();

        $this->assertNull($service->findPositiveStripeOrderByKey($user, $identity['key']));
        $this->assertNull($service->findPositiveStripeOrderByFingerprint($user, $request, 'stripe'));

        $response = $service->replay(
            $user,
            $identity['key'],
            $identity['fingerprint'],
            4000,
        );

        $this->assertNotNull($response);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('pi_null_brand', $order->fresh()->stripe_payment_intent_id);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_identity_lock_lease_covers_the_full_stripe_timeout_budget(): void
    {
        $user = User::factory()->create();
        $lockTtls = [];
        $lock = $this->createMock(Lock::class);
        $lock->expects($this->exactly(2))
            ->method('block')
            ->willReturnCallback(static fn (int $seconds, callable $callback): mixed => $callback());

        Cache::shouldReceive('lock')
            ->twice()
            ->andReturnUsing(function (string $name, int $seconds) use (&$lockTtls, $lock): Lock {
                $lockTtls[] = $seconds;

                return $lock;
            });

        $service = new CheckoutIdempotencyService($this->createMock(StripePaymentService::class));
        $response = $service->execute(
            $user,
            'checkout-lock-timeout-budget-0001',
            str_repeat('a', 64),
            4000,
            fn () => response()->json(['success' => true]),
        );

        $expectedTtl = CurlClient::DEFAULT_TIMEOUT * 4 + 30;
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$expectedTtl, $expectedTtl], $lockTtls);
    }

    public function test_identity_lock_timeout_is_retryable_without_running_the_creator(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $lock = $this->createMock(Lock::class);
        $lock->expects($this->once())
            ->method('block')
            ->willThrowException(new LockTimeoutException('competing checkout holds the identity lock'));

        Cache::shouldReceive('lock')
            ->once()
            ->andReturn($lock);

        $service = new CheckoutIdempotencyService($this->createMock(StripePaymentService::class));
        $response = $service->executeNonImmediate(
            $user,
            'checkout-lock-timeout-0001',
            str_repeat('a', 64),
            4000,
            fn () => $this->fail('A timed-out identity lock must not run the creator.'),
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_unique_constraint_race_reloads_and_resolves_the_competing_creator_claim(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $key = 'checkout-unique-race-0001';
        $fingerprint = str_repeat('b', 64);
        $creatorCalls = 0;
        $service = new CheckoutIdempotencyService($this->createMock(StripePaymentService::class));

        $response = $service->executeNonImmediate(
            $user,
            $key,
            $fingerprint,
            4000,
            function (?Order $existingOrder) use (&$creatorCalls, $user, $key, $fingerprint): JsonResponse {
                $creatorCalls++;
                if ($existingOrder !== null) {
                    return response()->json(['order_id' => $existingOrder->id]);
                }

                // Model the other worker's committed insert winning the unique
                // key race. No sleep or second process is needed to exercise
                // the recovery seam deterministically.
                Order::factory()->create([
                    'user_id' => $user->id,
                    'brand' => Brand::B2B,
                    'status' => 'invoice_created',
                    'total_amount' => 4000,
                    'is_quote_request' => false,
                    'checkout_idempotency_key' => $key,
                    'checkout_fingerprint' => $fingerprint,
                ]);

                throw new UniqueConstraintViolationException(
                    'sqlite',
                    'insert into orders ...',
                    [],
                    new \RuntimeException('duplicate checkout identity'),
                );
            },
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $creatorCalls);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame($key, Order::query()->value('checkout_idempotency_key'));
    }

    public function test_immediate_unique_constraint_race_reloads_and_replays_winning_pending_claim(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $key = 'checkout-immediate-race-0001';
        $fingerprint = str_repeat('d', 64);
        $creatorCalls = 0;
        $winner = null;
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_immediate_race')
            ->willReturnCallback(function (string $paymentIntentId) use (&$winner): array {
                $this->assertInstanceOf(Order::class, $winner);

                return $this->intent($paymentIntentId, $winner);
            });
        $stripe->expects($this->never())->method('cancelPaymentIntent');
        $service = new CheckoutIdempotencyService($stripe);

        $response = $service->execute(
            $user,
            $key,
            $fingerprint,
            4000,
            function (?Order $existingOrder) use (&$creatorCalls, &$winner, $user, $key, $fingerprint): JsonResponse {
                $creatorCalls++;
                if ($existingOrder !== null) {
                    return response()->json(['order_id' => $existingOrder->id]);
                }

                // The competing worker commits the unique-key claim after the
                // initial lookup and before this creator reaches its insert.
                $winner = Order::factory()->create([
                    'user_id' => $user->id,
                    'brand' => Brand::B2B,
                    'status' => 'pending_payment',
                    'total_amount' => 4000,
                    'stripe_payment_intent_id' => 'pi_immediate_race',
                    'checkout_idempotency_key' => $key,
                    'checkout_fingerprint' => $fingerprint,
                ]);
                InvoiceSnapshot::factory()->for($winner)->create();

                throw new UniqueConstraintViolationException(
                    'sqlite',
                    'insert into orders ...',
                    [],
                    new \RuntimeException('duplicate immediate checkout identity'),
                );
            },
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $creatorCalls);
        $this->assertSame($winner?->id, $response->getData(true)['order_id']);
        $this->assertSame('pi_immediate_race_secret', $response->getData(true)['client_secret']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_immediate_unique_constraint_race_reloads_and_returns_a_fingerprint_conflict(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $key = 'checkout-immediate-race-0002';
        $fingerprint = str_repeat('e', 64);
        $creatorCalls = 0;
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('retrievePaymentIntent');
        $stripe->expects($this->never())->method('cancelPaymentIntent');
        $service = new CheckoutIdempotencyService($stripe);

        $response = $service->execute(
            $user,
            $key,
            $fingerprint,
            4000,
            function (?Order $existingOrder) use (&$creatorCalls, $user, $key): JsonResponse {
                $creatorCalls++;
                if ($existingOrder !== null) {
                    return response()->json(['order_id' => $existingOrder->id]);
                }

                // The winning row has a different canonical checkout intent;
                // the unique-key race must become a deterministic 409, never
                // a new Stripe operation or an unsafe replay.
                Order::factory()->create([
                    'user_id' => $user->id,
                    'brand' => Brand::B2B,
                    'status' => 'pending_payment',
                    'total_amount' => 4000,
                    'stripe_payment_intent_id' => 'pi_immediate_conflict',
                    'checkout_idempotency_key' => $key,
                    'checkout_fingerprint' => str_repeat('f', 64),
                ]);

                throw new UniqueConstraintViolationException(
                    'sqlite',
                    'insert into orders ...',
                    [],
                    new \RuntimeException('duplicate immediate checkout identity'),
                );
            },
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
        $this->assertSame(1, $creatorCalls);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_v036_replay_rejects_missing_portal_user_metadata(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_replay_expected']);
        $order = $this->pendingOrder($user, 'checkout-key-missing-user', str_repeat('a', 64));
        InvoiceSnapshot::factory()->for($order)->create();
        $intent = $this->intent('pi_pending', $order);
        $intent['customer'] = 'cus_replay_expected';
        unset($intent['metadata']['portal_user_id']);

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->willReturn($intent);
        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->replay(
            $user,
            'checkout-key-missing-user',
            str_repeat('a', 64),
            4000,
        );

        $this->assertNotNull($response);
        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_v036_replay_rejects_a_customer_that_is_not_the_mapped_user_customer(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_replay_expected']);
        $order = $this->pendingOrder($user, 'checkout-key-wrong-customer', str_repeat('a', 64));
        InvoiceSnapshot::factory()->for($order)->create();
        $intent = $this->intent('pi_pending', $order);
        $intent['customer'] = 'cus_replay_attacker';

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->willReturn($intent);
        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->replay(
            $user,
            'checkout-key-wrong-customer',
            str_repeat('a', 64),
            4000,
        );

        $this->assertNotNull($response);
        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_same_key_with_different_payload_returns_conflict(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-0002', str_repeat('a', 64));
        InvoiceSnapshot::factory()->for($order)->create();

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('retrievePaymentIntent');
        $service = new CheckoutIdempotencyService($stripe);

        $response = $service->execute(
            $user,
            'checkout-key-0002',
            str_repeat('b', 64),
            4000,
            fn () => $this->fail('Creator must not run for an idempotency conflict.'),
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
    }

    public function test_paid_order_replays_success_without_stripe_call(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-0003', str_repeat('a', 64));
        $snapshot = InvoiceSnapshot::factory()->for($order)->create();
        $order->update(['status' => 'paid']);

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('retrievePaymentIntent');
        $service = new CheckoutIdempotencyService($stripe);

        $response = $service->execute(
            $user,
            'checkout-key-0003',
            str_repeat('a', 64),
            4000,
            fn () => $this->fail('Creator must not run for a paid order.'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame($snapshot->invoice_number, $response->getData(true)['invoice_number']);
    }

    public function test_stale_cancelable_intent_is_canceled_and_replaced_on_same_order(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-0004', str_repeat('a', 64));
        InvoiceSnapshot::factory()->for($order)->create();
        $created = now()->subHours(2)->getTimestamp();

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->exactly(2))
            ->method('retrievePaymentIntent')
            ->willReturn($this->intent('pi_pending', $order, $created));
        $stripe->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_pending')
            ->willReturn(['id' => 'pi_pending', 'status' => 'canceled']);

        $service = new CheckoutIdempotencyService($stripe);
        $newKey = 'checkout-key-after-stale-session-loss';
        $this->assertNull($service->replay(
            $user,
            $newKey,
            str_repeat('a', 64),
            4000,
        ));
        $replacementOrder = null;
        $response = $service->execute(
            $user,
            $newKey,
            str_repeat('a', 64),
            4000,
            function (Order $existingOrder) use (&$replacementOrder) {
                $replacementOrder = $existingOrder;

                return response()->json([
                    'success' => true,
                    'requires_action' => true,
                    'client_secret' => 'pi_replacement_secret',
                    'order_id' => $existingOrder->id,
                    'invoice_number' => $existingOrder->invoiceSnapshot->invoice_number,
                ]);
            },
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($order->id, $replacementOrder?->id);
        $this->assertSame(2, $order->fresh()->payment_intent_generation);
        $this->assertSame('checkout-key-0004', $order->fresh()->checkout_idempotency_key);
        $this->assertSame(str_repeat('a', 64), $order->fresh()->checkout_fingerprint);
    }

    public function test_replacement_does_not_reopen_an_order_paid_while_cancellation_is_in_flight(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-race-paid', str_repeat('d', 64));
        InvoiceSnapshot::factory()->for($order)->create();
        $intent = $this->intent('pi_pending', $order, now()->subHours(2)->getTimestamp());

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->willReturn($intent);
        $stripe->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_pending')
            ->willReturnCallback(function () use ($order): array {
                Order::query()->whereKey($order->getKey())->update(['status' => 'paid']);

                return ['id' => 'pi_pending', 'status' => 'canceled'];
            });

        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->execute(
            $user,
            'checkout-key-race-paid',
            str_repeat('d', 64),
            4000,
            fn () => $this->fail('A paid order must not be reopened for replacement.'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('pi_pending', $order->fresh()->stripe_payment_intent_id);
        $this->assertSame(1, $order->fresh()->payment_intent_generation);
    }

    public function test_replacement_rejects_a_cancel_response_for_another_payment_intent(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-race-cancel-id', str_repeat('e', 64));
        InvoiceSnapshot::factory()->for($order)->create();

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->willReturn($this->intent('pi_pending', $order, now()->subHours(2)->getTimestamp()));
        $stripe->expects($this->once())
            ->method('cancelPaymentIntent')
            ->willReturn(['id' => 'pi_other', 'status' => 'canceled']);

        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->execute(
            $user,
            'checkout-key-race-cancel-id',
            str_repeat('e', 64),
            4000,
            fn () => $this->fail('An invalid cancellation response must not create a replacement.'),
        );

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame('pi_pending', $order->fresh()->stripe_payment_intent_id);
        $this->assertSame(1, $order->fresh()->payment_intent_generation);
    }

    public function test_replacement_rejects_a_cancel_response_that_is_not_canceled(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-race-cancel-status', str_repeat('f', 64));
        InvoiceSnapshot::factory()->for($order)->create();

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->willReturn($this->intent('pi_pending', $order, now()->subHours(2)->getTimestamp()));
        $stripe->expects($this->once())
            ->method('cancelPaymentIntent')
            ->willReturn(['id' => 'pi_pending', 'status' => 'requires_payment_method']);

        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->execute(
            $user,
            'checkout-key-race-cancel-status',
            str_repeat('f', 64),
            4000,
            fn () => $this->fail('A non-canceled cancellation response must not create a replacement.'),
        );

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame('pi_pending', $order->fresh()->stripe_payment_intent_id);
        $this->assertSame(1, $order->fresh()->payment_intent_generation);
    }

    public function test_null_payment_intent_retry_never_reopens_a_paid_order(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-null-paid', str_repeat('1', 64));
        $order->update(['stripe_payment_intent_id' => null, 'status' => 'paid']);
        InvoiceSnapshot::factory()->for($order)->create();

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('retrievePaymentIntent');
        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->execute(
            $user,
            'checkout-key-null-paid',
            str_repeat('1', 64),
            4000,
            fn () => $this->fail('A paid null-PI order must not be reopened.'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertNull($order->fresh()->stripe_payment_intent_id);
    }

    public function test_pending_intent_without_created_timestamp_is_retryable_without_cancel(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-missing-created', str_repeat('a', 64));
        InvoiceSnapshot::factory()->for($order)->create();

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->willReturn(array_replace(
                $this->intent('pi_pending', $order, null, 'requires_payment_method'),
                ['created' => null, 'client_secret' => null],
            ));
        $stripe->expects($this->never())->method('cancelPaymentIntent');

        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->replay(
            $user,
            'checkout-key-after-session-storage-loss',
            str_repeat('a', 64),
            4000,
        );

        $this->assertNotNull($response);
        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(1, $order->fresh()->payment_intent_generation);
        $this->assertSame('pi_pending', $order->fresh()->stripe_payment_intent_id);
    }

    public function test_v036_stale_intent_with_missing_identity_metadata_is_rejected(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-missing-metadata', str_repeat('c', 64));
        InvoiceSnapshot::factory()->for($order)->create();
        $intent = $this->intent('pi_pending', $order, now()->subHours(2)->getTimestamp());
        $intent['metadata'] = [];

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_pending')
            ->willReturn($intent);
        $stripe->expects($this->never())->method('cancelPaymentIntent');

        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->replay(
            $user,
            'checkout-key-missing-metadata',
            str_repeat('c', 64),
            4000,
        );

        $this->assertNotNull($response);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
        $this->assertSame(1, $order->fresh()->payment_intent_generation);
    }

    public function test_null_payment_intent_retry_preserves_original_checkout_identity(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-null-pi', str_repeat('b', 64));
        $order->update(['stripe_payment_intent_id' => null]);
        InvoiceSnapshot::factory()->for($order)->create();
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('retrievePaymentIntent');
        $service = new CheckoutIdempotencyService($stripe);
        $newKey = 'checkout-key-new-after-null-pi';

        $this->assertNull($service->replay($user, $newKey, str_repeat('b', 64), 4000));
        $response = $service->execute(
            $user,
            $newKey,
            str_repeat('b', 64),
            4000,
            fn (Order $existingOrder) => response()->json(['order_id' => $existingOrder->id]),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('checkout-key-null-pi', $order->fresh()->checkout_idempotency_key);
        $this->assertSame(str_repeat('b', 64), $order->fresh()->checkout_fingerprint);
    }

    public function test_fulfilled_remote_intent_returns_a_non_destructive_recovery_response(): void
    {
        $user = User::factory()->create();
        $order = $this->pendingOrder($user, 'checkout-key-0005', str_repeat('a', 64));
        InvoiceSnapshot::factory()->for($order)->create();

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->willReturn($this->intent('pi_pending', $order, now()->getTimestamp(), 'succeeded'));
        $stripe->expects($this->never())->method('cancelPaymentIntent');

        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->execute(
            $user,
            'checkout-key-0005',
            str_repeat('a', 64),
            4000,
            fn () => $this->fail('Creator must not run while the remote PaymentIntent is fulfilled.'),
        );
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['payment_pending']);
        $this->assertFalse($payload['requires_action']);
        $this->assertArrayNotHasKey('client_secret', $payload);
        $this->assertSame($order->id, $payload['order_id']);
        $this->assertSame('pending_payment', $payload['status']);
        $this->assertSame('/api/orders/'.$order->id, $payload['poll_url']);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_identity_is_stable_for_reordered_items_and_falls_back_when_header_is_missing(): void
    {
        $user = User::factory()->create();
        $requestA = Request::create('/', 'POST', [
            'items' => [
                ['photoId' => 'photo-b', 'modifierIds' => ['modifier-2', 'modifier-1']],
                ['photoId' => 'photo-a', 'modifierIds' => []],
            ],
            'billing_name' => 'Test User',
            'billing_street' => 'Street 1',
            'billing_zip' => '1010',
            'billing_city' => 'Vienna',
            'coupon_code' => 'SAVE10',
            'withdrawal_waived' => true,
        ]);
        $requestB = Request::create('/', 'POST', [
            'items' => [
                ['photoId' => 'photo-a', 'modifierIds' => []],
                ['photoId' => 'photo-b', 'modifierIds' => ['modifier-1', 'modifier-2']],
            ],
            'billing_name' => 'Test User',
            'billing_street' => 'Street 1',
            'billing_zip' => '1010',
            'billing_city' => 'Vienna',
            'coupon_code' => 'SAVE10',
            'withdrawal_waived' => true,
        ]);

        $service = new CheckoutIdempotencyService($this->createMock(StripePaymentService::class));
        $first = $service->identify($requestA, $user);
        $second = $service->identify($requestB, $user);

        $this->assertSame($first, $second);
        $this->assertSame('auto-'.$first['fingerprint'], $first['key']);
        $this->assertSame(64, strlen($first['fingerprint']));
    }

    public function test_lost_key_fallback_discovers_an_old_order_with_a_freshly_replaced_pi(): void
    {
        $user = User::factory()->create();
        $fingerprint = str_repeat('c', 64);
        $order = $this->pendingOrder($user, 'old-checkout-key', $fingerprint);
        Order::query()->whereKey($order->getKey())->update([
            'created_at' => now()->subHours(2),
            'stripe_payment_intent_id' => 'pi_fresh_replacement',
            'payment_intent_generation' => 2,
        ]);
        $order->refresh();

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_fresh_replacement')
            ->willReturn($this->intent('pi_fresh_replacement', $order, now()->getTimestamp(), 'requires_action'));
        $stripe->expects($this->never())->method('cancelPaymentIntent');

        $service = new CheckoutIdempotencyService($stripe);
        $response = $service->replay($user, 'new-auto-key', $fingerprint, 4000);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pi_fresh_replacement_secret', $response->getData(true)['client_secret']);
        $this->assertSame('old-checkout-key', $order->fresh()->checkout_idempotency_key);
        $this->assertSame($fingerprint, $order->fresh()->checkout_fingerprint);
    }

    public function test_payment_method_is_part_of_the_checkout_fingerprint(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => 'photo-a']],
            'billing_name' => 'Test User',
            'withdrawal_waived' => true,
        ]);
        $service = new CheckoutIdempotencyService($this->createMock(StripePaymentService::class));

        $stripe = $service->identify($request, $user, 4000, 'stripe');
        $invoice = $service->identify($request, $user, 4000, 'invoice');

        $this->assertNotSame($stripe['fingerprint'], $invoice['fingerprint']);
    }

    public function test_server_calculated_amount_is_part_of_the_fingerprint(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => 'photo-a']],
            'billing_name' => 'Test User',
            'withdrawal_waived' => true,
        ]);
        $service = new CheckoutIdempotencyService($this->createMock(StripePaymentService::class));

        $first = $service->identify($request, $user, 4000);
        $second = $service->identify($request, $user, 5000);

        $this->assertNotSame($first['fingerprint'], $second['fingerprint']);
        $this->assertNotSame($first['key'], $second['key']);
    }

    private function pendingOrder(User $user, string $key, string $fingerprint): Order
    {
        return Order::factory()->create([
            'user_id' => $user->id,
            'brand' => Brand::B2B,
            'status' => 'pending_payment',
            'total_amount' => 4000,
            'stripe_payment_intent_id' => 'pi_pending',
            'checkout_idempotency_key' => $key,
            'checkout_fingerprint' => $fingerprint,
            'payment_intent_generation' => 1,
        ]);
    }

    private function intent(string $id, Order $order, ?int $created = null, string $status = 'requires_payment_method'): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'client_secret' => $id.'_secret',
            'amount' => $order->total_amount,
            'currency' => 'eur',
            'created' => $created ?? now()->getTimestamp(),
            'metadata' => [
                'order_id' => (string) $order->id,
                'portal_user_id' => (string) $order->user_id,
                'account_created_at' => (string) $order->user->created_at->getTimestamp(),
                'checkout_idempotency_key' => (string) $order->checkout_idempotency_key,
                'checkout_fingerprint' => (string) $order->checkout_fingerprint,
                'generation' => (string) $order->payment_intent_generation,
                'amount_cents' => (string) $order->total_amount,
                'currency' => 'eur',
            ],
        ];
    }
}
