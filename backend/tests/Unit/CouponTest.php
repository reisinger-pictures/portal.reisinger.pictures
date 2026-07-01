<?php

namespace Tests\Unit;

use App\Models\Coupon;
use App\Models\CouponUserUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    //  isGloballyMaxedOut
    // ──────────────────────────────────────────────

    public function test_is_globally_maxed_out_returns_true(): void
    {
        $coupon = Coupon::factory()->create([
            'max_uses_global' => 5,
            'used_count' => 5,
        ]);

        $this->assertTrue($coupon->isGloballyMaxedOut());
    }

    public function test_is_globally_maxed_out_returns_false(): void
    {
        $coupon = Coupon::factory()->create([
            'max_uses_global' => 5,
            'used_count' => 3,
        ]);

        $this->assertFalse($coupon->isGloballyMaxedOut());
    }

    public function test_is_globally_maxed_out_null_means_unlimited(): void
    {
        $coupon = Coupon::factory()->create([
            'max_uses_global' => null,
            'used_count' => 999,
        ]);

        $this->assertFalse($coupon->isGloballyMaxedOut());
    }

    // ──────────────────────────────────────────────
    //  isPerAccountMaxedOut
    // ──────────────────────────────────────────────

    public function test_is_per_account_maxed_out_true(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->create([
            'max_uses_per_account' => 2,
        ]);

        CouponUserUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 2,
        ]);

        $this->assertTrue($coupon->isPerAccountMaxedOut($user->id));
    }

    public function test_is_per_account_maxed_out_false(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->create([
            'max_uses_per_account' => 2,
        ]);

        // No usage record → not maxed out
        $this->assertFalse($coupon->isPerAccountMaxedOut($user->id));
    }

    public function test_is_per_account_maxed_out_null_means_unlimited(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::factory()->create([
            'max_uses_per_account' => null,
        ]);

        CouponUserUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 999,
        ]);

        $this->assertFalse($coupon->isPerAccountMaxedOut($user->id));
    }

    // ──────────────────────────────────────────────
    //  isValid / isExpired
    // ──────────────────────────────────────────────

    public function test_is_valid_returns_false_when_inactive(): void
    {
        $coupon = Coupon::factory()->create(['active' => false]);

        $this->assertFalse($coupon->isValid());
    }

    public function test_is_valid_returns_false_when_expired(): void
    {
        $coupon = Coupon::factory()->create([
            'active' => true,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->assertFalse($coupon->isValid());
    }

    public function test_is_valid_returns_true_for_valid_coupon(): void
    {
        $coupon = Coupon::factory()->create([
            'active' => true,
            'expires_at' => Carbon::now()->addMonth(),
            'max_uses_global' => null,
            'used_count' => 0,
        ]);

        $this->assertTrue($coupon->isValid());
    }

    public function test_is_expired_returns_false_for_null_expiry(): void
    {
        $coupon = Coupon::factory()->create(['expires_at' => null]);

        $this->assertFalse($coupon->isExpired());
    }

    public function test_is_expired_returns_false_for_future(): void
    {
        $coupon = Coupon::factory()->create([
            'expires_at' => Carbon::now()->addDay(),
        ]);

        $this->assertFalse($coupon->isExpired());
    }

    public function test_is_expired_returns_true_for_past(): void
    {
        $coupon = Coupon::factory()->create([
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->assertTrue($coupon->isExpired());
    }

    // ──────────────────────────────────────────────
    //  per_sub_gallery
    // ──────────────────────────────────────────────

    public function test_per_sub_gallery_defaults_to_false(): void
    {
        $coupon = Coupon::factory()->make();
        $this->assertFalse($coupon->per_sub_gallery);
    }

    public function test_per_sub_gallery_casts_to_boolean(): void
    {
        $coupon = Coupon::factory()->make(['per_sub_gallery' => 1]);
        $this->assertTrue($coupon->per_sub_gallery);
    }

    public function test_per_sub_gallery_is_fillable(): void
    {
        $coupon = Coupon::factory()->create(['per_sub_gallery' => true]);
        $this->assertTrue($coupon->per_sub_gallery);
    }
}
