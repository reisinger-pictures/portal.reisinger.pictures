<?php

namespace Tests\Feature\Coupon;

use App\Enums\UserRole;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Coupon;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponCheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(BrandContextMiddleware::class);
        $testBrand = new BrandConfig(
            id: 'test-brand',
            name: 'Test Brand',
            theme: 'rp',
            portalName: 'Test Portal',
            impressumUrl: null,
            logoPath: null,
            features: [],
            hostnames: [],
            isActive: true,
        );
        BrandRegistry::set($testBrand);
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(null);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    //  validateCoupon Endpoint
    // ──────────────────────────────────────────────

    public function test_validate_coupon_returns_valid(): void
    {
        Coupon::factory()->percentage(10)->create([
            'brand' => 'test-brand',
            'code' => 'VALID10',
            'active' => true,
        ]);

        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/coupons/validate', [
                'code' => 'VALID10',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('valid', true);
        $response->assertJsonPath('coupon.code', 'VALID10');
        $response->assertJsonPath('coupon.type', 'percentage');
        $response->assertJsonPath('coupon.value', 10);
        $response->assertJsonMissingPath('coupon.id');
        $response->assertJsonMissingPath('coupon.scope_type');
    }

    public function test_validate_coupon_returns_invalid_for_nonexistent(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/coupons/validate', [
                'code' => 'NONEXISTENT',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('valid', false);
        $response->assertJsonPath('error', 'Coupon code not found.');
    }

    public function test_validate_coupon_requires_auth(): void
    {
        $response = $this->postJson('/api/coupons/validate', [
            'code' => 'ANY',
        ]);

        $response->assertStatus(401);
    }

    public function test_validate_coupon_with_scope_gallery(): void
    {
        Coupon::factory()->create([
            'brand' => 'test-brand',
            'code' => 'SCOPED',
            'scope_type' => 'gallery',
            'scope_id' => 'test-gallery-uuid',
            'active' => true,
        ]);

        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/coupons/validate', [
                'code' => 'SCOPED',
                'gallery_id' => 'test-gallery-uuid',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('valid', true);
    }
}
