<?php

namespace Tests\Feature\Coupon;

use App\Enums\Brand;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutCouponRevalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(BrandContextMiddleware::class);
        BrandRegistry::set(Brand::SRP);

        \App\Models\Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'srp'], ['value' => 'volume_licensing']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'srp'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'srp'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'company_street', 'brand' => 'srp'], ['value' => 'Teststreet 1']);

        \App\Models\LicenseUseCase::forceCreate(['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Tageszeitung', 'base_price' => 8000, 'flatrate_tier' => 'print', 'brand' => 'rp']);
        \App\Models\LicenseUseCase::forceCreate(['id' => '00000000-0000-0000-0000-000000000000', 'name' => 'Web', 'base_price' => 3000, 'flatrate_tier' => 'web', 'brand' => 'srp']);
    }

    protected function tearDown(): void
    {
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

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
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
            'brand' => 'srp',
            'code' => 'EXPCHECK',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $data = $this->createCheckoutData();
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
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
            'brand' => 'srp',
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

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
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
            'brand' => 'srp',
            'code' => 'VALID10',
            'active' => true,
        ]);

        $data = $this->createCheckoutData();
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
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

    public function test_checkout_increments_coupon_usage_per_account(): void
    {
        $coupon = Coupon::factory()->percentage(10)->create([
            'brand' => 'srp',
            'code' => 'INCR',
            'active' => true,
            'max_uses_global' => 5,
            'used_count' => 0,
        ]);

        $data = $this->createCheckoutData();
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
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
            'brand' => 'srp',
            'code' => 'RACE',
            'active' => true,
            'max_uses_global' => 1,
            'used_count' => 0,
        ]);

        $data = $this->createCheckoutData();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $token1 = auth('api')->login($user1);
        $token2 = auth('api')->login($user2);

        $r1 = $this->withHeaders(['Authorization' => 'Bearer ' . $token1])
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

        $r2 = $this->withHeaders(['Authorization' => 'Bearer ' . $token2])
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
