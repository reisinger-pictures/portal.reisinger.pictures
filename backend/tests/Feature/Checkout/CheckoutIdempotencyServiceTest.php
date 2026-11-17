<?php

namespace Tests\Feature\Checkout;

use App\Enums\Brand;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutIdempotencyService;
use App\Support\BrandRegistry;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The identity layer is provider-independent: it owns the browser key, the
 * canonical fingerprint, the persisted amount and the identity locks. Provider
 * state (the Mollie attempt ledger) is resolved by the provider service, not
 * here.
 */
class CheckoutIdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['app.checkout_idempotency_ttl_minutes' => 30]);
    }

    protected function tearDown(): void
    {
        BrandRegistry::reset();
        parent::tearDown();
    }

    public function test_null_brand_positive_claim_fails_closed(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => 'null-brand-photo']],
            'billing_name' => 'Tester',
        ]);
        $service = new CheckoutIdempotencyService;
        $identity = $service->identify($request, $user, 4000, 'mollie');
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => null,
            'status' => 'pending_payment',
            'total_amount' => 4000,
            'checkout_idempotency_key' => $identity['key'],
            'checkout_fingerprint' => $identity['fingerprint'],
        ]);
        InvoiceSnapshot::factory()->for($order)->create();

        $this->assertNull($service->findPositiveOnlineOrderByKey($user, $identity['key'], 'mollie'));
        $this->assertNull($service->findPositiveOnlineOrderByFingerprint($user, $request, 'mollie'));

        $response = $service->executeOnline(
            $user,
            $identity['key'],
            $identity['fingerprint'],
            4000,
            'mollie',
            fn () => $this->fail('A null-brand claim must never be resumed.'),
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame($identity['key'], $order->fresh()->checkout_idempotency_key);
    }

    public function test_a_legacy_foreign_provider_order_is_not_recognised_as_a_mollie_claim(): void
    {
        BrandRegistry::set(Brand::B2B);

        $user = User::factory()->create(['brand' => Brand::B2B]);
        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => 'legacy-foreign-photo']],
            'billing_name' => 'Tester',
        ]);
        $service = new CheckoutIdempotencyService;
        $identity = $service->identify($request, $user, 4000, 'mollie');

        // A historical pending order of the removed provider: the surviving
        // marker column is the only thing that distinguishes it from a Mollie
        // claim, and it must never be adopted by a new Mollie attempt.
        $legacy = $this->pendingOrder($user, $identity['key'], $identity['fingerprint']);
        $legacy->forceFill(['stripe_payment_intent_id' => 'pi_old'])->save();

        $this->assertNull($service->findPositiveOnlineOrderByKey($user, $identity['key'], 'mollie'));
        $this->assertNull($service->findPositiveOnlineOrderByFingerprint($user, $request, 'mollie'));

        // Control: an otherwise identical row without the legacy marker is a
        // Mollie claim.
        $mollieOrder = $this->pendingOrder($user, 'mollie-current-key-0001', str_repeat('d', 64));

        $this->assertSame(
            $mollieOrder->id,
            $service->findPositiveOnlineOrderByKey($user, 'mollie-current-key-0001', 'mollie')?->id,
        );

        // The invoice scope is the mirror image: a row without a Mollie attempt
        // remains claimable as a non-immediate order.
        $this->assertSame(
            $legacy->id,
            $service->findPositiveOnlineOrderByKey($user, $identity['key'], 'invoice')?->id,
        );
    }

    public function test_identity_lock_lease_covers_the_full_provider_timeout_budget(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
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

        $service = new CheckoutIdempotencyService;
        $response = $service->executeOnline(
            $user,
            'checkout-lock-timeout-budget-0001',
            str_repeat('a', 64),
            4000,
            'mollie',
            fn () => response()->json(['success' => true]),
        );

        // Four sequential provider requests at the 80s HTTP timeout, plus a
        // 30s safety margin.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([350, 350], $lockTtls);
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

        $service = new CheckoutIdempotencyService;
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
        $service = new CheckoutIdempotencyService;

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

    public function test_online_unique_constraint_race_reloads_and_resumes_the_winning_claim(): void
    {
        BrandRegistry::set(Brand::B2B);

        $user = User::factory()->create(['brand' => Brand::B2B]);
        $key = 'checkout-immediate-race-0001';
        $fingerprint = str_repeat('d', 64);
        $creatorCalls = 0;
        $winner = null;
        $service = new CheckoutIdempotencyService;

        $response = $service->executeOnline(
            $user,
            $key,
            $fingerprint,
            4000,
            'mollie',
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
                    'checkout_idempotency_key' => $key,
                    'checkout_fingerprint' => $fingerprint,
                ]);

                throw new UniqueConstraintViolationException(
                    'sqlite',
                    'insert into orders ...',
                    [],
                    new \RuntimeException('duplicate immediate checkout identity'),
                );
            },
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $creatorCalls);
        $this->assertSame($winner?->id, $response->getData(true)['order_id']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_online_unique_constraint_race_reloads_and_returns_a_fingerprint_conflict(): void
    {
        BrandRegistry::set(Brand::B2B);

        $user = User::factory()->create(['brand' => Brand::B2B]);
        $key = 'checkout-immediate-race-0002';
        $fingerprint = str_repeat('e', 64);
        $creatorCalls = 0;
        $service = new CheckoutIdempotencyService;

        $response = $service->executeOnline(
            $user,
            $key,
            $fingerprint,
            4000,
            'mollie',
            function (?Order $existingOrder) use (&$creatorCalls, $user, $key): JsonResponse {
                $creatorCalls++;
                if ($existingOrder !== null) {
                    return response()->json(['order_id' => $existingOrder->id]);
                }

                // The winning row has a different canonical checkout intent;
                // the unique-key race must become a deterministic 409, never a
                // provider operation or an unsafe replay.
                Order::factory()->create([
                    'user_id' => $user->id,
                    'brand' => Brand::B2B,
                    'status' => 'pending_payment',
                    'total_amount' => 4000,
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

    public function test_same_key_with_different_payload_returns_conflict(): void
    {
        BrandRegistry::set(Brand::B2B);

        $user = User::factory()->create(['brand' => Brand::B2B]);
        $order = $this->pendingOrder($user, 'checkout-key-0002', str_repeat('a', 64));
        InvoiceSnapshot::factory()->for($order)->create();

        $service = new CheckoutIdempotencyService;

        $response = $service->executeOnline(
            $user,
            'checkout-key-0002',
            str_repeat('b', 64),
            4000,
            'mollie',
            fn () => $this->fail('Creator must not run for an idempotency conflict.'),
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
    }

    public function test_paid_order_replays_success_without_a_creator_call(): void
    {
        BrandRegistry::set(Brand::B2B);

        $user = User::factory()->create(['brand' => Brand::B2B]);
        $order = $this->pendingOrder($user, 'checkout-key-0003', str_repeat('a', 64));
        $snapshot = InvoiceSnapshot::factory()->for($order)->create();
        $order->update(['status' => 'paid']);

        $service = new CheckoutIdempotencyService;

        $response = $service->executeOnline(
            $user,
            'checkout-key-0003',
            str_repeat('a', 64),
            4000,
            'mollie',
            fn () => $this->fail('Creator must not run for a paid order.'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame($snapshot->invoice_number, $response->getData(true)['invoice_number']);
    }

    public function test_lost_key_fallback_discovers_the_pending_order_by_fingerprint(): void
    {
        BrandRegistry::set(Brand::B2B);

        $user = User::factory()->create(['brand' => Brand::B2B]);
        $fingerprint = str_repeat('c', 64);
        $order = $this->pendingOrder($user, 'old-checkout-key', $fingerprint);
        $order->update(['created_at' => now()->subHours(2)]);
        $order->refresh();

        $service = new CheckoutIdempotencyService;
        $resumed = null;

        $response = $service->executeOnline(
            $user,
            'new-auto-key',
            $fingerprint,
            4000,
            'mollie',
            function (?Order $existingOrder) use (&$resumed): JsonResponse {
                $resumed = $existingOrder;

                return response()->json(['order_id' => $existingOrder?->id]);
            },
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($order->id, $resumed?->id);
        $this->assertSame('old-checkout-key', $order->fresh()->checkout_idempotency_key);
        $this->assertSame($fingerprint, $order->fresh()->checkout_fingerprint);
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

        $service = new CheckoutIdempotencyService;
        $first = $service->identify($requestA, $user);
        $second = $service->identify($requestB, $user);

        $this->assertSame($first, $second);
        $this->assertSame('auto-'.$first['fingerprint'], $first['key']);
        $this->assertSame(64, strlen($first['fingerprint']));
    }

    public function test_payment_method_is_part_of_the_checkout_fingerprint(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => 'photo-a']],
            'billing_name' => 'Test User',
            'withdrawal_waived' => true,
        ]);
        $service = new CheckoutIdempotencyService;

        $mollie = $service->identify($request, $user, 4000, 'mollie');
        $invoice = $service->identify($request, $user, 4000, 'invoice');

        $this->assertNotSame($mollie['fingerprint'], $invoice['fingerprint']);
    }

    public function test_server_calculated_amount_is_part_of_the_fingerprint(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => 'photo-a']],
            'billing_name' => 'Test User',
            'withdrawal_waived' => true,
        ]);
        $service = new CheckoutIdempotencyService;

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
            'checkout_idempotency_key' => $key,
            'checkout_fingerprint' => $fingerprint,
        ]);
    }
}
