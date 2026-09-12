<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for P0-B9: the checkout lock must re-validate the *full* coupon
 * state (active, expiry, usage and discount/scope definition), not just usage limits.
 */
class CouponLockRevalidationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CouponService::class);
    }

    public function test_lock_rejects_coupon_deactivated_after_preview(): void
    {
        $coupon = Coupon::factory()->create(['brand' => 'rp', 'code' => 'LOCKACT', 'active' => true]);
        Coupon::where('id', $coupon->id)->update(['active' => false]);

        [$found, $error] = $this->service->lockAndRevalidateCoupon($coupon);

        $this->assertNull($found);
        $this->assertSame('This coupon is not active.', $error);
    }

    public function test_lock_rejects_coupon_expired_after_preview(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LOCKEXP',
            'active' => true,
            'expires_at' => null,
        ]);
        Coupon::where('id', $coupon->id)->update(['expires_at' => now()->subDay()]);

        [$found, $error] = $this->service->lockAndRevalidateCoupon($coupon);

        $this->assertNull($found);
        $this->assertSame('This coupon has expired.', $error);
    }

    public function test_lock_rejects_coupon_whose_discount_value_changed(): void
    {
        $coupon = Coupon::factory()->percentage(10)->create(['brand' => 'rp', 'code' => 'LOCKVAL', 'active' => true]);
        Coupon::where('id', $coupon->id)->update(['value' => 90]);

        [$found, $error] = $this->service->lockAndRevalidateCoupon($coupon);

        $this->assertNull($found);
        $this->assertSame('Der Rabattcode ist nicht mehr gültig.', $error);
    }

    public function test_lock_rejects_coupon_whose_scope_changed(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LOCKSCOPE',
            'active' => true,
            'scope_type' => 'global',
            'scope_id' => null,
        ]);
        Coupon::where('id', $coupon->id)->update(['scope_type' => 'gallery', 'scope_id' => 'some-gallery-id']);

        [$found, $error] = $this->service->lockAndRevalidateCoupon($coupon);

        $this->assertNull($found);
        $this->assertSame('Der Rabattcode ist nicht mehr gültig.', $error);
    }

    public function test_lock_accepts_unchanged_coupon(): void
    {
        $coupon = Coupon::factory()->percentage(10)->create(['brand' => 'rp', 'code' => 'LOCKOK2', 'active' => true]);

        [$found, $error] = $this->service->lockAndRevalidateCoupon($coupon);

        $this->assertNotNull($found);
        $this->assertNull($error);
        $this->assertSame($coupon->id, $found->id);
    }
}
