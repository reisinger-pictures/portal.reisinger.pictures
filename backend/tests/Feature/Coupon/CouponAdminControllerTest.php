<?php

namespace Tests\Feature\Coupon;

use App\Enums\UserRole;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponAdminControllerTest extends TestCase
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

    private function superAdminToken(): string
    {
        $superAdmin = User::factory()->create(['brand' => null]);
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return auth('api')->login($superAdmin);
    }

    private function photoPackagePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'PHOTOPKG',
            'type' => 'photo_package',
            'scope_type' => 'global',
            'active' => true,
        ], $overrides);
    }

    public function test_store_photo_package_valid_creates_and_maps_euro_to_cents(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/coupons', $this->photoPackagePayload([
                'package_quantity' => 10,
                'package_price_cents' => 40, // Euro → 4000 cents via controller mapping
            ]));

        $response->assertStatus(201);
        $response->assertJsonPath('coupon.type', 'photo_package');
        $response->assertJsonPath('coupon.package_quantity', 10);
        $response->assertJsonPath('coupon.package_price_cents', 4000);

        $this->assertDatabaseHas('coupons', [
            'code' => 'PHOTOPKG',
            'type' => 'photo_package',
            'package_quantity' => 10,
            'package_price_cents' => 4000,
        ]);
    }

    public function test_store_photo_package_missing_quantity_returns_422(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/coupons', $this->photoPackagePayload([
                'package_price_cents' => 40,
            ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Package quantity must be at least 1.');
    }

    public function test_store_photo_package_zero_quantity_returns_422(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/coupons', $this->photoPackagePayload([
                'package_quantity' => 0,
                'package_price_cents' => 40,
            ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Package quantity must be at least 1.');
    }

    public function test_store_fixed_requires_value(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/coupons', $this->photoPackagePayload([
                'type' => 'fixed',
                'code' => 'FIXEDX',
            ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Value is required for fixed and percentage coupons.');
    }
}
