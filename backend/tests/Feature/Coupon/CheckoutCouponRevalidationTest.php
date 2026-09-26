<?php

namespace Tests\Feature\Coupon;

use App\Enums\Brand;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\MocksStripeClient;
use Tests\TestCase;

class CheckoutCouponRevalidationTest extends TestCase
{
    use MocksStripeClient;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockStripePaymentIntentSuccess();
        $this->withoutMiddleware(BrandContextMiddleware::class);
        BrandRegistry::set(Brand::B2B);

        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'volume_licensing']);
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'company_street', 'brand' => 'rp'], ['value' => 'Teststreet 1']);

        LicenseUseCase::forceCreate(['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Tageszeitung', 'base_price' => 8000, 'flatrate_tier' => 'print', 'brand' => 'rp']);
        LicenseUseCase::forceCreate(['id' => '00000000-0000-0000-0000-000000000000', 'name' => 'Web', 'base_price' => 3000, 'flatrate_tier' => 'web', 'brand' => 'rp']);
    }

    protected function tearDown(): void
    {
        $this->resetStripeHttpClient();
        BrandRegistry::set(null);
        parent::tearDown();
    }

    private function createCheckoutData(): array
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        return [
            'gallery' => $gallery,
            'photo' => $photo,
            'items' => [
                ['photoId' => $photo->id, 'tier' => 'web'],
            ],
        ];
    }

    public function test_checkout_rejects_invalid_coupon(): void
    {
        $data = $this->createCheckoutData();
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders/checkout', [
                'items' => $data['items'],
                'coupon_code' => 'INVALID',
                'billing_name' => 'Test',
                'billing_street' => 'Str 1',
                'billing_zip' => '1010',
                'billing_city' => 'Wien',
                'withdrawal_waived' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Der Rabattcode ist nicht mehr gültig.']);
    }

    public function test_checkout_rejects_expired_coupon(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'EXPCHECK',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $data = $this->createCheckoutData();
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders/checkout', [
                'items' => $data['items'],
                'coupon_code' => 'EXPCHECK',
                'billing_name' => 'Test',
                'billing_street' => 'Str 1',
                'billing_zip' => '1010',
                'billing_city' => 'Wien',
                'withdrawal_waived' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Der Rabattcode ist nicht mehr gültig.']);
    }

    public function test_checkout_rejects_maxed_out_coupon(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'MAXCHECK',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
            'max_uses_global' => 1,
            'used_count' => 1,
        ]);

        $data = $this->createCheckoutData();
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders/checkout', [
                'items' => $data['items'],
                'coupon_code' => 'MAXCHECK',
                'billing_name' => 'Test',
                'billing_street' => 'Str 1',
                'billing_zip' => '1010',
                'billing_city' => 'Wien',
                'withdrawal_waived' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Der Rabattcode ist nicht mehr gültig.']);
    }

    // ──────────────────────────────────────────────
    //  Successful Checkout with Coupon
    // ──────────────────────────────────────────────

    public function test_checkout_with_valid_percentage_coupon_succeeds(): void
    {
        $coupon = Coupon::factory()->percentage(10)->create([
            'brand' => 'rp',
            'code' => 'VALID10',
            'active' => true,
        ]);

        $data = $this->createCheckoutData();
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders/checkout', [
                'items' => $data['items'],
                'coupon_code' => 'VALID10',
                'billing_name' => 'Test',
                'billing_street' => 'Str 1',
                'billing_zip' => '1010',
                'billing_city' => 'Wien',
                'withdrawal_waived' => true,
            ]);

        $content = $response->getContent();
        file_put_contents('php://stderr', "STATUS: {$response->status()}\nBODY: {$content}\n");
        $response->assertStatus(200);
        $orderId = $response->json('order_id');
        $this->assertNotNull($orderId);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'coupon_id' => $coupon->id]);
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'used_count' => 1]);
    }

    public function test_exact_retry_replays_after_a_finite_use_coupon_is_exhausted(): void
    {
        $coupon = Coupon::factory()->percentage(10)->create([
            'brand' => 'rp',
            'code' => 'FINITE10',
            'active' => true,
            'max_uses_global' => 1,
            'used_count' => 0,
        ]);
        $data = $this->createCheckoutData();
        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $createdPiId = 'pi_finite_coupon';
        $createdAmount = 0;
        $createdMetadata = [];
        $createCalls = 0;
        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->method('request')->willReturnCallback(
            function (string $method, string $url, array $headers, array $parameters) use (
                &$createdPiId,
                &$createdAmount,
                &$createdMetadata,
                &$createCalls,
            ): array {
                if ($method === 'post') {
                    $createCalls++;
                    $createdAmount = (int) ($parameters['amount'] ?? 0);
                    $createdMetadata = $parameters['metadata'] ?? [];
                }

                return [json_encode([
                    'id' => $createdPiId,
                    'status' => 'requires_payment_method',
                    'client_secret' => $createdPiId.'_secret',
                    'amount' => $createdAmount,
                    'currency' => 'eur',
                    'created' => time(),
                    'customer' => $parameters['customer'] ?? null,
                    'metadata' => $createdMetadata,
                ]), 200, []];
            }
        );
        ApiRequestor::setHttpClient($clientMock);

        $payload = [
            'items' => $data['items'],
            'coupon_code' => 'FINITE10',
            'billing_name' => 'Test',
            'billing_street' => 'Str 1',
            'billing_zip' => '1010',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
        ];
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'finite-coupon-checkout-0001',
        ];
        $first = $this->withHeaders($headers)->postJson('/api/orders/checkout', $payload);
        $first->assertOk();
        $order = Order::query()->firstOrFail();
        $this->assertSame(1, $coupon->fresh()->used_count);

        $second = $this->withHeaders($headers)->postJson('/api/orders/checkout', $payload);
        $second->assertOk();

        $this->assertSame(1, $createCalls);
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame($createdPiId, $order->fresh()->stripe_payment_intent_id);

        $coupon->update(['active' => false, 'used_count' => 1]);
        $lostKeyHeaders = [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'finite-coupon-checkout-0002',
        ];
        $lostKeyRetry = $this->withHeaders($lostKeyHeaders)
            ->postJson('/api/orders/checkout', $payload);
        $lostKeyRetry->assertOk();
        $this->assertSame(1, $createCalls);
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(1, Order::query()->count());

        $changedPayload = $payload;
        $changedPayload['billing_street'] = 'Str 2';
        $this->withHeaders($headers)
            ->postJson('/api/orders/checkout', $changedPayload)
            ->assertStatus(409)
            ->assertJson(['idempotency_conflict' => true]);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_checkout_increments_coupon_usage_per_account(): void
    {
        $coupon = Coupon::factory()->percentage(10)->create([
            'brand' => 'rp',
            'code' => 'INCR',
            'active' => true,
            'max_uses_global' => 5,
            'used_count' => 0,
        ]);

        $data = $this->createCheckoutData();
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders/checkout', [
                'items' => $data['items'],
                'coupon_code' => 'INCR',
                'billing_name' => 'Test',
                'billing_street' => 'Str 1',
                'billing_zip' => '1010',
                'billing_city' => 'Wien',
                'withdrawal_waived' => true,
            ])->assertStatus(200);

        $this->assertDatabaseHas('coupon_user_usage', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 1,
        ]);
    }

    public function test_concurrent_checkout_race_condition_blocks_second_request(): void
    {
        $coupon = Coupon::factory()->percentage(10)->create([
            'brand' => 'rp',
            'code' => 'RACE',
            'active' => true,
            'max_uses_global' => 1,
            'used_count' => 0,
        ]);

        $data = $this->createCheckoutData();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $token1 = auth('api')->login($user1);

        $r1 = $this->withHeaders(['Authorization' => 'Bearer '.$token1])
            ->postJson('/api/orders/checkout', [
                'items' => $data['items'],
                'coupon_code' => 'RACE',
                'billing_name' => 'Test',
                'billing_street' => 'Str 1',
                'billing_zip' => '1010',
                'billing_city' => 'Wien',
                'withdrawal_waived' => true,
            ]);
        $r1->assertStatus(200);
        $token2 = auth('api')->login($user2);

        $r2 = $this->withHeaders(['Authorization' => 'Bearer '.$token2])
            ->postJson('/api/orders/checkout', [
                'items' => $data['items'],
                'coupon_code' => 'RACE',
                'billing_name' => 'Test',
                'billing_street' => 'Str 1',
                'billing_zip' => '1010',
                'billing_city' => 'Wien',
                'withdrawal_waived' => true,
            ]);
        $r2->assertStatus(422);
        $r2->assertJson(['error' => 'Der Rabattcode ist nicht mehr gültig.']);
    }
}
