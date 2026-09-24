<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: the management order list and status update must be brand-scoped
 * for brand-bound admins. Only cross-brand users (brand = null) may act on all
 * brands.
 */
class BrandScopingOrderTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_BRAND = 'srp';

    private function adminTokenForBrand(?string $brand): string
    {
        $user = User::factory()->create(['brand' => $brand]);
        $role = $brand === null ? UserRole::SUPER_ADMIN : UserRole::ADMIN;
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return auth('api')->login($user);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_brand_bound_admin_only_lists_own_brand_orders(): void
    {
        $token = $this->adminTokenForBrand(self::OTHER_BRAND);

        $ownOrder = Order::factory()->create(['brand' => self::OTHER_BRAND]);
        Order::factory()->create(['brand' => Brand::B2B->value]);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/orders');

        $response->assertOk();
        $response->assertJsonCount(1);
        $this->assertSame($ownOrder->id, $response->json('0.id'));
    }

    public function test_cross_brand_admin_lists_all_orders(): void
    {
        $token = $this->adminTokenForBrand(null);

        Order::factory()->create(['brand' => self::OTHER_BRAND]);
        Order::factory()->create(['brand' => Brand::B2B->value]);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/orders');

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_brand_bound_admin_cannot_update_foreign_brand_order(): void
    {
        $token = $this->adminTokenForBrand(self::OTHER_BRAND);

        $foreignOrder = Order::factory()->create(['brand' => Brand::B2B->value, 'status' => 'pending']);

        $response = $this->withHeaders($this->bearer($token))
            ->putJson("/api/management/orders/{$foreignOrder->id}/status", ['status' => 'paid']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('orders', ['id' => $foreignOrder->id, 'status' => 'pending']);
    }

    public function test_cross_brand_admin_can_update_foreign_brand_order(): void
    {
        $token = $this->adminTokenForBrand(null);

        $foreignOrder = Order::factory()->create(['brand' => Brand::B2B->value, 'status' => 'pending']);

        $response = $this->withHeaders($this->bearer($token))
            ->putJson("/api/management/orders/{$foreignOrder->id}/status", ['status' => 'paid']);

        $response->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $foreignOrder->id, 'status' => 'paid']);
    }
}
