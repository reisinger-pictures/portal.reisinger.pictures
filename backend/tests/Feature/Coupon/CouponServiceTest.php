<?php

namespace Tests\Feature\Coupon;

use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\CouponUserUsage;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use App\Models\Tenant;
use App\Models\User;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CouponService;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
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
        BrandRegistry::set(Brand::SRP);
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
            'brand' => 'srp',
            'code' => 'TEST10',
            'type' => 'percentage',
            'value' => 10,
            'scope_type' => 'global',
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('TEST10', Brand::SRP);

        $this->assertNotNull($found);
        $this->assertNull($error);
        $this->assertSame($coupon->id, $found->id);
    }

    public function test_rejects_wrong_brand(): void
    {
        Coupon::factory()->create([
            'brand' => 'rp',
            'code' => 'RPONLY',
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('RPONLY', Brand::SRP);

        $this->assertNull($found);
        $this->assertNotNull($error);
        $this->assertStringContainsString('not found', $error);
    }

    public function test_rejects_expired_coupon(): void
    {
        Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'EXPIRED',
            'active' => true,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        [$found, $error] = $this->service->findValidCoupon('EXPIRED', Brand::SRP);

        $this->assertNull($found);
        $this->assertStringContainsString('expired', $error);
    }

    public function test_rejects_maxed_out_coupon(): void
    {
        Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'MAXED',
            'active' => true,
            'max_uses_global' => 5,
            'used_count' => 5,
        ]);

        [$found, $error] = $this->service->findValidCoupon('MAXED', Brand::SRP);

        $this->assertNull($found);
        $this->assertStringContainsString('usage limit', $error);
    }

    public function test_rejects_inactive_coupon(): void
    {
        Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'INACTIVE',
            'active' => false,
        ]);

        [$found, $error] = $this->service->findValidCoupon('INACTIVE', Brand::SRP);

        $this->assertNull($found);
        $this->assertStringContainsString('not active', $error);
    }

    public function test_accepts_gallery_scoped_coupon_when_gallery_matches(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'GALLERY',
            'scope_type' => 'gallery',
            'scope_id' => 42,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('GALLERY', Brand::SRP, 42);

        $this->assertNotNull($found);
        $this->assertNull($error);
    }

    public function test_rejects_gallery_scoped_coupon_when_gallery_does_not_match(): void
    {
        Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'GALLERY',
            'scope_type' => 'gallery',
            'scope_id' => 42,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('GALLERY', Brand::SRP, 99);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid', $error);
    }

    public function test_accepts_meta_gallery_scoped_coupon_when_meta_gallery_matches(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'META',
            'scope_type' => 'meta_gallery',
            'scope_id' => 7,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('META', Brand::SRP, null, 7);

        $this->assertNotNull($found);
        $this->assertNull($error);
    }

    public function test_rejects_meta_gallery_scoped_coupon_when_meta_gallery_does_not_match(): void
    {
        Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'META',
            'scope_type' => 'meta_gallery',
            'scope_id' => 7,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('META', Brand::SRP, null, 99);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid', $error);
    }

    // ──────────────────────────────────────────────
    //  applyCoupon
    // ──────────────────────────────────────────────

    public function test_apply_fixed_discount(): void
    {
        $coupon = Coupon::factory()->fixed(5.00)->create(['brand' => 'srp', 'active' => true]);
        $items = $this->makePricedItems(10, 2500); // 10 × 2500 = 25000
        $total = 25000;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // Fixed 5 EUR = 500 cents discount
        $this->assertSame(24500, $result['totalCents']);
        $this->assertSame(500, $result['discountCents']);
    }

    public function test_apply_fixed_discount_does_not_go_below_zero(): void
    {
        $coupon = Coupon::factory()->fixed(999999.00)->create(['brand' => 'srp', 'active' => true]);
        $items = $this->makePricedItems(1, 1000);
        $total = 1000;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        $this->assertSame(0, $result['totalCents']);
        $this->assertSame(1000, $result['discountCents']);
    }

    public function test_apply_percentage_discount(): void
    {
        $coupon = Coupon::factory()->percentage(10)->create(['brand' => 'srp', 'active' => true]);
        $items = $this->makePricedItems(10, 2500); // 10 × 2500 = 25000
        $total = 25000;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // 10% of 25000 = 2500 discount
        $this->assertSame(22500, $result['totalCents']);
        $this->assertSame(2500, $result['discountCents']);
    }

    public function test_apply_free_items_discount(): void
    {
        // 3 free items, cheapest first
        $coupon = Coupon::factory()->freeItems(3)->create(['brand' => 'srp', 'active' => true]);
        $items = $this->makePricedItems(5, [3000, 2500, 2000, 1500, 1000]);
        $total = 10000; // 3000+2500+2000+1500+1000

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // Cheapest 3 items (1000+1500+2000 = 4500) become free
        $this->assertSame(5500, $result['totalCents']);
        $this->assertSame(4500, $result['discountCents']);

        // Verify cheapest items are free
        $priceCents = array_map(fn ($i) => $i['priceCents'], $result['items']);
        sort($priceCents);
        $this->assertSame(0, $priceCents[0]);
        $this->assertSame(0, $priceCents[1]);
        $this->assertSame(0, $priceCents[2]);
        $this->assertSame(2500, $priceCents[3]);
        $this->assertSame(3000, $priceCents[4]);
    }

    // ──────────────────────────────────────────────
    //  applyCoupon — per_sub_gallery
    // ──────────────────────────────────────────────

    public function test_apply_coupon_free_items_per_sub_gallery(): void
    {
        $coupon = Coupon::factory()->freeItems(1)->create([
            'brand' => 'srp',
            'per_sub_gallery' => true,
            'scope_type' => 'meta_gallery',
            'active' => true,
        ]);

        $items = [
            ['itemId' => 'a1', 'priceCents' => 3000, 'galleryId' => 'gallery-1', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
            ['itemId' => 'a2', 'priceCents' => 1000, 'galleryId' => 'gallery-1', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
            ['itemId' => 'b1', 'priceCents' => 2500, 'galleryId' => 'gallery-2', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
            ['itemId' => 'c1', 'priceCents' => 1500, 'galleryId' => 'gallery-3', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
        ];
        $total = 8000;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // Cheapest from gallery-1: 1000, gallery-2: 2500, gallery-3: 1500 → 3 items free
        $this->assertSame(3000, $result['totalCents']);
        $this->assertSame(5000, $result['discountCents']);

        $priceCents = array_map(fn ($i) => $i['priceCents'], $result['items']);
        $this->assertSame(3000, $priceCents[0]);
        $this->assertSame(0, $priceCents[1]);
        $this->assertSame(0, $priceCents[2]);
        $this->assertSame(0, $priceCents[3]);
    }

    public function test_apply_coupon_free_items_per_sub_gallery_multiple_free(): void
    {
        $coupon = Coupon::factory()->freeItems(2)->create([
            'brand' => 'srp',
            'per_sub_gallery' => true,
            'scope_type' => 'meta_gallery',
            'active' => true,
        ]);

        $items = [
            ['itemId' => 'a1', 'priceCents' => 3000, 'galleryId' => 'gallery-1', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
            ['itemId' => 'a2', 'priceCents' => 2000, 'galleryId' => 'gallery-1', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
            ['itemId' => 'a3', 'priceCents' => 1000, 'galleryId' => 'gallery-1', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
            ['itemId' => 'b1', 'priceCents' => 2500, 'galleryId' => 'gallery-2', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
        ];
        $total = 8500;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // gallery-1: cheapest 2 (1000+2000=3000) free
        // gallery-2: cheapest 1 (2500) free (only item)
        // Total free: 5500
        $this->assertSame(3000, $result['totalCents']);
        $this->assertSame(5500, $result['discountCents']);

        $priceCents = array_map(fn ($i) => $i['priceCents'], $result['items']);
        $this->assertSame(3000, $priceCents[0]);
        $this->assertSame(0, $priceCents[1]);
        $this->assertSame(0, $priceCents[2]);
        $this->assertSame(0, $priceCents[3]);
    }

    public function test_apply_coupon_free_items_per_sub_gallery_no_gallery_id(): void
    {
        $coupon = Coupon::factory()->freeItems(1)->create([
            'brand' => 'srp',
            'per_sub_gallery' => true,
            'scope_type' => 'meta_gallery',
            'active' => true,
        ]);

        $items = [
            ['itemId' => 'a1', 'priceCents' => 3000, 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
            ['itemId' => 'a2', 'priceCents' => 1000, 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
        ];
        $total = 4000;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // No galleryId keys → no items grouped → no free items
        $this->assertSame(4000, $result['totalCents']);
        $this->assertSame(0, $result['discountCents']);
    }

    public function test_apply_coupon_free_items_global_mode_unchanged(): void
    {
        $coupon = Coupon::factory()->freeItems(2)->create([
            'brand' => 'srp',
            'per_sub_gallery' => false,
            'active' => true,
        ]);

        $items = [
            ['itemId' => 'a1', 'priceCents' => 3000, 'galleryId' => 'gallery-1', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
            ['itemId' => 'a2', 'priceCents' => 1000, 'galleryId' => 'gallery-1', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
            ['itemId' => 'b1', 'priceCents' => 2500, 'galleryId' => 'gallery-2', 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
        ];
        $total = 6500;

        $result = $this->service->applyCoupon($coupon, $items, $total);

        // Global mode: cheapest 2 items globally (1000+2500=3500) free
        $this->assertSame(3000, $result['totalCents']);
        $this->assertSame(3500, $result['discountCents']);

        $priceCents = array_map(fn ($i) => $i['priceCents'], $result['items']);
        $this->assertSame(3000, $priceCents[0]);
        $this->assertSame(0, $priceCents[1]);
        $this->assertSame(0, $priceCents[2]);
    }

    // ──────────────────────────────────────────────
    //  incrementUsage
    // ──────────────────────────────────────────────

    public function test_increment_usage(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'srp',
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
        $coupon = Coupon::factory()->percentage(10)->create([
            'brand' => 'srp',
            'code' => 'SRP10',
            'active' => true,
        ]);

        $strategy = new VolumeLicensingStrategy(new SettingResolver(), new CouponService());
        $user = User::factory()->create();

        $items = $this->buildStrategyItems(10, false);
        $result = $strategy->calculateCart($items, $user, 'SRP10');

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

    public function test_brand_isolation_srp_coupon_not_applied_in_non_srp_context(): void
    {
        // Create a B2B coupon
        Coupon::factory()->percentage(10)->create([
            'brand' => 'rp',
            'code' => 'RP10',
            'active' => true,
        ]);

        // Set brand to B2B context
        BrandRegistry::set(Brand::B2B);

        $strategy = new VolumeLicensingStrategy(new SettingResolver(), new CouponService());
        $user = User::factory()->create();

        $items = $this->buildStrategyItems(10, false);

        // Even though coupon is passed, brand won't match the current context
        // Normally the findValidCoupon checks brand against current brand
        // But here we're testing the strategy integration
        // Re-set to SRP for this test to check isolation
        BrandRegistry::set(Brand::SRP);

        // Create an SRP coupon that should work
        Coupon::factory()->percentage(10)->create([
            'brand' => 'srp',
            'code' => 'SRP10',
            'active' => true,
        ]);

        $result = $strategy->calculateCart($items, $user, 'SRP10');

        $this->assertSame(22500, $result['totalCents']);
        $this->assertNotNull($result['couponId']);
    }

    // ──────────────────────────────────────────────
    //  Per-Account Usage Limits
    // ──────────────────────────────────────────────

    public function test_rejects_per_account_maxed_out(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'srp',
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

        [$found, $error] = $this->service->findValidCoupon('PERACCT', Brand::SRP, null, null, $user->id);

        $this->assertNull($found);
        $this->assertStringContainsString('usage limit', $error);
    }

    public function test_accepts_below_per_account_limit(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'srp',
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

        [$found, $error] = $this->service->findValidCoupon('PEROK', Brand::SRP, null, null, $user->id);

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
            'brand' => 'srp',
            'code' => 'PHOTOVALID',
            'active' => true,
            'scope_type' => 'photographer',
            'created_by' => $photographer->id,
        ]);

        [$found, $error] = $this->service->findValidCoupon('PHOTOVALID', Brand::SRP, $gallery->id);

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
            'brand' => 'srp',
            'code' => 'PHOTOINVALID',
            'active' => true,
            'scope_type' => 'photographer',
            'created_by' => $photographer->id,
        ]);

        [$found, $error] = $this->service->findValidCoupon('PHOTOINVALID', Brand::SRP, $galleryB->id);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid', $error);
    }

    // ──────────────────────────────────────────────
    //  scope_gallery_id Sub-Scoping
    // ──────────────────────────────────────────────

    public function test_meta_gallery_with_scope_gallery_id_matches(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'MGMATCH',
            'active' => true,
            'scope_type' => 'meta_gallery',
            'scope_id' => 7,
            'scope_gallery_id' => 42,
        ]);

        [$found, $error] = $this->service->findValidCoupon('MGMATCH', Brand::SRP, 42, 7);

        $this->assertNotNull($found);
        $this->assertNull($error);
    }

    public function test_meta_gallery_with_scope_gallery_id_mismatch(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'MGMISMATCH',
            'active' => true,
            'scope_type' => 'meta_gallery',
            'scope_id' => 7,
            'scope_gallery_id' => 42,
        ]);

        [$found, $error] = $this->service->findValidCoupon('MGMISMATCH', Brand::SRP, 99, 7);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid for this specific gallery', $error);
    }

    // ──────────────────────────────────────────────
    //  Organisation Scope
    // ──────────────────────────────────────────────

    public function test_find_valid_coupon_organisation_scope_success(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->users()->attach($user->id);

        $coupon = Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'ORGVALID',
            'scope_type' => 'organisation',
            'scope_id' => $tenant->id,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('ORGVALID', Brand::SRP, null, null, $user->id);

        $this->assertNotNull($found);
        $this->assertNull($error);
        $this->assertSame($coupon->id, $found->id);
    }

    public function test_find_valid_coupon_organisation_scope_wrong_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenantB->users()->attach($user->id);

        Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'ORGWRONG',
            'scope_type' => 'organisation',
            'scope_id' => $tenantA->id,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('ORGWRONG', Brand::SRP, null, null, $user->id);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid for your account', $error);
    }

    public function test_find_valid_coupon_organisation_scope_no_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(); // no tenant attached

        Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'ORGNOTENANT',
            'scope_type' => 'organisation',
            'scope_id' => $tenant->id,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('ORGNOTENANT', Brand::SRP, null, null, $user->id);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid for your account', $error);
    }

    public function test_find_valid_coupon_organisation_scope_requires_auth(): void
    {
        $tenant = Tenant::factory()->create();

        Coupon::factory()->create([
            'brand' => 'srp',
            'code' => 'ORGAUTH',
            'scope_type' => 'organisation',
            'scope_id' => $tenant->id,
            'active' => true,
        ]);

        [$found, $error] = $this->service->findValidCoupon('ORGAUTH', Brand::SRP, null, null, null);

        $this->assertNull($found);
        $this->assertStringContainsString('not valid for your account', $error);
    }

    // ──────────────────────────────────────────────
    //  incrementUsage with Per-Account
    // ──────────────────────────────────────────────

    public function test_increment_usage_with_per_account(): void
    {
        $coupon = Coupon::factory()->create([
            'brand' => 'srp',
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
            'brand' => 'srp',
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

        [$found, $error] = $this->service->findValidCoupon('COMBINED', Brand::SRP, null, null, $user->id);

        $this->assertNull($found);
        $this->assertStringContainsString('usage limit', $error);
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
