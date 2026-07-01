<?php

namespace Tests\Feature\Coupon;

use App\Enums\Brand;
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
        BrandRegistry::set(Brand::SRP);

        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'srp'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'srp'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'company_street', 'brand' => 'srp'], ['value' => 'Teststreet 1']);
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
}
