<?php

namespace Tests\Feature\Coupon;

use App\Enums\UserRole;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Coupon;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for P0-B15: updating an `organisation`-scoped coupon without a
 * `code` referenced an undefined `$brandValue` and returned a 500.
 */
class CouponUpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(BrandContextMiddleware::class);
    }

    public function test_update_organisation_scope_without_code_returns_422_not_500(): void
    {
        $coupon = Coupon::factory()->create(['brand' => 'rp', 'code' => 'UPDORG', 'active' => true]);

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/coupons/{$coupon->id}", [
                'scope_type' => 'organisation',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Org not found for this brand.');
    }
}
