<?php

namespace Tests\Feature\Coupon;

use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\CouponUserUsage;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use App\Models\Org;
use App\Models\User;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CouponService;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->service = new CouponService();
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(null);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    //  findValidCoupon
    // ──────────────────────────────────────────────

    public function test_finds_valid_global_coupon(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'TEST10',
            'type' => 'percentage',
            'value' => 10,
            'scope_type' => 'global',
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('TEST10', Brand::B2B);

        $this->assertNotNull($found);
        $this->assertNull($error);
        $this->assertSame($coupon->id, $found->id);
    }

    public function test_rejects_wrong_brand(): void
    {
        Coupon::factory()->create([
            'brand' => 'test-brand',
            'code' => 'TBONLY',
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('TBONLY', Brand::B2B);

        $this->assertNull($found);
        $this->assertNotNull($error);
        $this->assertStringContainsString('not found', $error);
    }

    public function test_rejects_expired_coupon(): void
    {
        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'EXPIRED',
            'active' => true,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        [$found, $error] = $this->service->findValidCoupon('EXPIRED', Brand::B2B);

        $this->assertNull($found);
        $this->assertStringContainsString('expired', $error);
    }

    public function test_rejects_maxed_out_coupon(): void
    {
        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'MAXED',
            'active' => true,
            'max_uses_global' => 5,
            'used_count' => 5,
        ]);

        [$found, $error] = $this->service->findValidCoupon('MAXED', Brand::B2B);

        $this->assertNull($found);
        $this->assertStringContainsString('usage limit', $error);
    }

    public function test_rejects_inactive_coupon(): void
    {
        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'INACTIVE',
            'active' => false,
        ]);

        [$found, $error] = $this->service->findValidCoupon('INACTIVE', Brand::B2B);

        $this->assertNull($found);
        $this->assertStringContainsString('not active', $error);
    }

    public function test_accepts_gallery_scoped_coupon_when_gallery_matches(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'GALLERY',
            'scope_type' => 'gallery',
            'scope_id' => 42,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('GALLERY', Brand::B2B, 42);

        $this->assertNotNull($found);
        $this->assertNull($error);
    }

    public function test_rejects_gallery_scoped_coupon_when_gallery_does_not_match(): void
    {
        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'GALLERY',
            'scope_type' => 'gallery',
            'scope_id' => 42,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('GALLERY', Brand::B2B, 99);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid', $error);
    }

    public function test_accepts_meta_gallery_scoped_coupon_when_meta_gallery_matches(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'META',
            'scope_type' => 'meta_gallery',
            'scope_id' => 7,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('META', Brand::B2B, null, 7);

        $this->assertNotNull($found);
        $this->assertNull($error);
    }

    public function test_rejects_meta_gallery_scoped_coupon_when_meta_gallery_does_not_match(): void
    {
        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'META',
            'scope_type' => 'meta_gallery',
            'scope_id' => 7,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('META', Brand::B2B, null, 99);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid', $error);
    }

    // ──────────────────────────────────────────────
    //  applyCoupon
    // ──────────────────────────────────────────────

    public function test_apply_fixed_discount(): void
    {
        $coupon = Coupon::factory()->fixed(5.00)->create(['brand' => 'rp', 'active' => true]);
        $items = $this->makePricedItems(10, 2500); // 10 × 2500 = 25000
        $total = 25000;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // Fixed 5 EUR = 500 cents discount
        $this->assertSame(24500, $result['totalCents']);
        $this->assertSame(500, $result['discountCents']);
    }

    public function test_apply_fixed_discount_does_not_go_below_zero(): void
    {
        $coupon = Coupon::factory()->fixed(999999.00)->create(['brand' => 'rp', 'active' => true]);
        $items = $this->makePricedItems(1, 1000);
        $total = 1000;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        $this->assertSame(0, $result['totalCents']);
        $this->assertSame(1000, $result['discountCents']);
    }

    public function test_apply_percentage_discount(): void
    {
        $coupon = Coupon::factory()->percentage(10)->create(['brand' => 'rp', 'active' => true]);
        $items = $this->makePricedItems(10, 2500); // 10 × 2500 = 25000
        $total = 25000;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // 10% of 25000 = 2500 discount
        $this->assertSame(22500, $result['totalCents']);
        $this->assertSame(2500, $result['discountCents']);
    }

    public function test_apply_percentage_with_max_items_limits_to_cheapest(): void
    {
        // 50% off the 3 cheapest items
        $coupon = Coupon::factory()->percentageWithMaxItems(50, 3)->create(['brand' => 'rp', 'active' => true]);
        $items = $this->makePricedItems(5, [3000, 2500, 2000, 1500, 1000]);
        $total = 10000; // 3000+2500+2000+1500+1000

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // Cheapest 3 items: 1000+1500+2000 = 4500, 50% of that = 2250
        $this->assertSame(7750, $result['totalCents']);
        $this->assertSame(2250, $result['discountCents']);
    }

    public function test_apply_percentage_with_max_items_greater_than_count(): void
    {
        // 50% off the 10 cheapest items, but only 3 items exist
        $coupon = Coupon::factory()->percentageWithMaxItems(50, 10)->create(['brand' => 'rp', 'active' => true]);
        $items = $this->makePricedItems(3, [1000, 2000, 3000]);
        $total = 6000;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // max_items > item count → apply to all items: 50% of 6000 = 3000
        $this->assertSame(3000, $result['totalCents']);
        $this->assertSame(3000, $result['discountCents']);
    }

    public function test_apply_percentage_without_max_items_applies_to_entire_cart(): void
    {
        $coupon = Coupon::factory()->percentage(10)->create(['brand' => 'rp', 'active' => true]);
        $items = $this->makePricedItems(5, [3000, 2500, 2000, 1500, 1000]);
        $total = 10000;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // 10% of 10000 = 1000
        $this->assertSame(9000, $result['totalCents']);
        $this->assertSame(1000, $result['discountCents']);
    }

    // ──────────────────────────────────────────────
    //  incrementUsage
    // ──────────────────────────────────────────────

    public function test_increment_usage(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'USAGE',
            'used_count' => 0,
        ]);

        $this->service->incrementUsage($coupon);
        $coupon->refresh();

        $this->assertSame(1, $coupon->used_count);
    }

    // ──────────────────────────────────────────────
    //  Integration with VolumeLicensingStrategy
    // ──────────────────────────────────────────────

    public function test_strategy_with_coupon_code_applies_discount(): void
    {
        BrandRegistry::set(Brand::B2B);

        $coupon = Coupon::factory()->percentage(10)->create([
            'brand' => 'rp',
            'code' => 'RP10',
            'active' => true,
        ]);

        $strategy = new VolumeLicensingStrategy(new SettingResolver(), new CouponService());
        $user = User::factory()->create();

        $items = $this->buildStrategyItems(10, false);
        $result = $strategy->calculateCart($items, $user, 'RP10');

        // 10 items × 2500 (tier2) = 25000, minus 10% = 22500
        $this->assertSame(22500, $result['totalCents']);
        $this->assertSame(2500, $result['discountCents']);
        $this->assertSame($coupon->id, $result['couponId']);
    }

    public function test_strategy_without_coupon_no_discount(): void
    {
        $strategy = new VolumeLicensingStrategy(new SettingResolver(), new CouponService());
        $user = User::factory()->create();

        $items = $this->buildStrategyItems(10, false);
        $result = $strategy->calculateCart($items, $user);

        // 10 items × 2500 (tier2) = 25000
        $this->assertSame(25000, $result['totalCents']);
        $this->assertSame(0, $result['discountCents']);
        $this->assertNull($result['couponId']);
    }

    public function test_strategy_applies_coupon_with_matching_brand(): void
    {
        BrandRegistry::set(Brand::B2B);

        $strategy = new VolumeLicensingStrategy(new SettingResolver(), new CouponService());
        $user = User::factory()->create();

        $items = $this->buildStrategyItems(10, false);

        Coupon::factory()->percentage(10)->create([
            'brand' => 'rp',
            'code' => 'WORK10',
            'active' => true,
        ]);

        $result = $strategy->calculateCart($items, $user, 'WORK10');

        $this->assertSame(22500, $result['totalCents']);
        $this->assertNotNull($result['couponId']);
    }

    // ──────────────────────────────────────────────
    //  Per-Account Usage Limits
    // ──────────────────────────────────────────────

    public function test_rejects_per_account_maxed_out(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'PERACCT',
            'active' => true,
            'max_uses_global' => null,
            'max_uses_per_account' => 2,
        ]);

        $user = User::factory()->create();

        CouponUserUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 2,
        ]);

        [$found, $error] = $this->service->findValidCoupon('PERACCT', Brand::B2B, null, null, $user->id);

        $this->assertNull($found);
        $this->assertStringContainsString('usage limit', $error);
    }

    public function test_accepts_below_per_account_limit(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'PEROK',
            'active' => true,
            'max_uses_global' => null,
            'max_uses_per_account' => 2,
        ]);

        $user = User::factory()->create();

        CouponUserUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 1,
        ]);

        [$found, $error] = $this->service->findValidCoupon('PEROK', Brand::B2B, null, null, $user->id);

        $this->assertNotNull($found);
        $this->assertNull($error);
    }

    // ──────────────────────────────────────────────
    //  Photographer Scope
    // ──────────────────────────────────────────────

    public function test_photographer_scope_valid_when_creator_has_gallery_access(): void
    {
        $photographer = User::factory()->create();
        $gallery = Gallery::factory()->create();

        $photographer->photographerGalleries()->attach($gallery->id);

        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'PHOTOVALID',
            'active' => true,
            'scope_type' => 'photographer',
            'created_by' => $photographer->id,
        ]);

        [$found, $error] = $this->service->findValidCoupon('PHOTOVALID', Brand::B2B, $gallery->id);

        $this->assertNotNull($found);
        $this->assertNull($error);
    }

    public function test_photographer_scope_invalid_when_creator_has_no_gallery_access(): void
    {
        $photographer = User::factory()->create();
        $galleryA = Gallery::factory()->create();
        $galleryB = Gallery::factory()->create();

        $photographer->photographerGalleries()->attach($galleryA->id);

        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'PHOTOINVALID',
            'active' => true,
            'scope_type' => 'photographer',
            'created_by' => $photographer->id,
        ]);

        [$found, $error] = $this->service->findValidCoupon('PHOTOINVALID', Brand::B2B, $galleryB->id);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid', $error);
    }

    // ──────────────────────────────────────────────
    // ──────────────────────────────────────────────
    //  Organisation Scope
    // ──────────────────────────────────────────────

    public function test_find_valid_coupon_organisation_scope_success(): void
    {
        $org = Org::factory()->create();
        $user = User::factory()->create();
        $user->org_id = $org->id;
        $user->save();

        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'ORGVALID',
            'scope_type' => 'organisation',
            'scope_id' => $org->id,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('ORGVALID', Brand::B2B, null, null, $user->id);

        $this->assertNotNull($found);
        $this->assertNull($error);
        $this->assertSame($coupon->id, $found->id);
    }

    public function test_find_valid_coupon_organisation_scope_wrong_org(): void
    {
        $orgA = Org::factory()->create();
        $orgB = Org::factory()->create();
        $user = User::factory()->create();
        $user->org_id = $orgB->id;
        $user->save();

        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'ORGWRONG',
            'scope_type' => 'organisation',
            'scope_id' => $orgA->id,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('ORGWRONG', Brand::B2B, null, null, $user->id);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid for your account', $error);
    }

    public function test_find_valid_coupon_organisation_scope_no_org(): void
    {
        $org = Org::factory()->create();
        $user = User::factory()->create(); // no org attached

        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'ORGNOTENANT',
            'scope_type' => 'organisation',
            'scope_id' => $org->id,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('ORGNOTENANT', Brand::B2B, null, null, $user->id);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid for your account', $error);
    }

    public function test_find_valid_coupon_organisation_scope_requires_auth(): void
    {
        $org = Org::factory()->create();

        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'ORGAUTH',
            'scope_type' => 'organisation',
            'scope_id' => $org->id,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('ORGAUTH', Brand::B2B, null, null, null);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid for your account', $error);
    }

    // ──────────────────────────────────────────────
    //  incrementUsage with Per-Account
    // ──────────────────────────────────────────────

    public function test_increment_usage_with_per_account(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'INCPER',
            'max_uses_global' => null,
            'max_uses_per_account' => 5,
            'used_count' => 0,
        ]);

        $user = User::factory()->create();

        $this->service->incrementUsage($coupon, $user->id);

        $this->assertDatabaseHas('coupon_user_usage', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 1,
        ]);
    }

    public function test_combined_global_and_per_account_limits(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'COMBINED',
            'active' => true,
            'max_uses_global' => 5,
            'max_uses_per_account' => 2,
        ]);

        $user = User::factory()->create();

        CouponUserUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 2,
        ]);

        [$found, $error] = $this->service->findValidCoupon('COMBINED', Brand::B2B, null, null, $user->id);

        $this->assertNull($found);
        $this->assertStringContainsString('usage limit', $error);
    }

    // ──────────────────────────────────────────────
    //  lockAndRevalidateCoupon
    // ──────────────────────────────────────────────

    public function test_lock_and_revalidate_success(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LOCKOK',
            'active' => true,
            'used_count' => 0,
            'max_uses_global' => 5,
        ]);

        [$found, $error] = $this->service->lockAndRevalidateCoupon($coupon);

        $this->assertNotNull($found);
        $this->assertNull($error);
        $this->assertSame($coupon->id, $found->id);
    }

    public function test_lock_and_revalidate_global_maxed_out(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LOCKMAX',
            'active' => true,
            'max_uses_global' => 5,
            'used_count' => 5,
        ]);

        [$found, $error] = $this->service->lockAndRevalidateCoupon($coupon);

        $this->assertNull($found);
        $this->assertStringContainsString('usage limit', $error);
    }

    public function test_lock_and_revalidate_per_account_maxed_out(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LOCKPER',
            'active' => true,
            'max_uses_per_account' => 2,
        ]);

        $user = User::factory()->create();

        CouponUserUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 2,
        ]);

        [$found, $error] = $this->service->lockAndRevalidateCoupon($coupon, $user->id);

        $this->assertNull($found);
        $this->assertStringContainsString('usage limit', $error);
    }

    public function test_lock_and_revalidate_ignores_per_account_when_null_user(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'LOCKNULL',
            'active' => true,
            'max_uses_per_account' => 2,
        ]);

        [$found, $error] = $this->service->lockAndRevalidateCoupon($coupon, null);

        $this->assertNotNull($found);
        $this->assertNull($error);
    }

    // ──────────────────────────────────────────────
    //  Edge Cases
    // ──────────────────────────────────────────────

    public function test_lock_and_revalidate_coupon_when_coupon_deleted(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'DELETE',
            'active' => true,
            'used_count' => 0,
            'max_uses_global' => 5,
        ]);

        $coupon->delete();

        [$found, $error] = $this->service->lockAndRevalidateCoupon($coupon);

        $this->assertNull($found);
        $this->assertSame('Coupon not found.', $error);
    }

    public function test_increment_usage_multiple_increments_for_same_user(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'MULTIINC',
            'max_uses_per_account' => 3,
            'max_uses_global' => 100,
            'used_count' => 0,
        ]);

        $user = User::factory()->create();

        // 1st increment
        $this->service->incrementUsage($coupon, $user->id);
        $coupon->refresh();
        $this->assertSame(1, $coupon->used_count);
        $this->assertDatabaseHas('coupon_user_usage', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 1,
        ]);

        // 2nd increment
        $this->service->incrementUsage($coupon, $user->id);
        $coupon->refresh();
        $this->assertSame(2, $coupon->used_count);
        $this->assertDatabaseHas('coupon_user_usage', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 2,
        ]);

        // 3rd increment
        $this->service->incrementUsage($coupon, $user->id);
        $coupon->refresh();
        $this->assertSame(3, $coupon->used_count);
        $this->assertDatabaseHas('coupon_user_usage', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 3,
        ]);

        // 4th increment — incrementUsage itself does not enforce limits
        $this->service->incrementUsage($coupon, $user->id);
        $coupon->refresh();
        $this->assertSame(4, $coupon->used_count);
        $this->assertDatabaseHas('coupon_user_usage', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_count' => 4,
        ]);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Create priced items array as returned by PricingStrategy.
     */
    private function makePricedItems(int $count, int|array $priceCents): array
    {
        $items = [];
        $prices = is_array($priceCents) ? $priceCents : array_fill(0, $count, $priceCents);
        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'itemId' => 'item-' . ($i + 1),
                'priceCents' => $prices[$i] ?? 0,
                'tier' => 'srp',
                'useCaseName' => 'SRP Lizenz',
                'modifierNames' => [],
            ];
        }
        return $items;
    }

    /**
     * Build strategy-compatible items (same shape as VolumeLicensingStrategy expects).
     */
    private function buildStrategyItems(int $count, bool $isQuote): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'id' => 'item-' . ($i + 1),
                'license_use_case_id' => '',
                'license_modifier_ids' => [],
                'is_quote' => $isQuote,
            ];
        }
        return $items;
    }
}
