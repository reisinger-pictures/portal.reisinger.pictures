<?php

namespace Tests\Feature\Pricing;

use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CouponService;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VolumeLicensingStrategyTest extends TestCase
{
    use RefreshDatabase;

    private VolumeLicensingStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        $this->strategy = new VolumeLicensingStrategy(new SettingResolver());
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(null);
        parent::tearDown();
    }

    public function test_calculate_cart_applies_volume_discount_at_threshold(): void
    {
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold1', 'brand' => 'rp'],
            ['value' => '5']
        );
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold2', 'brand' => 'rp'],
            ['value' => '10']
        );

        $user = User::factory()->create();
        $items = $this->buildItems(5, false);
        $result = $this->strategy->calculateCart($items, $user);

        // 5 × 3000 (tier1) − 5 × 500 (tier2 discount) = 12500
        $this->assertSame(12500, $result['totalCents']);
        $this->assertCount(1, $result['tier_breakdown']);
    }

    public function test_calculate_cart_applies_higher_discount_at_higher_threshold(): void
    {
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold1', 'brand' => 'rp'],
            ['value' => '5']
        );
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold2', 'brand' => 'rp'],
            ['value' => '10']
        );

        $user = User::factory()->create();
        $items = $this->buildItems(10, false);
        $result = $this->strategy->calculateCart($items, $user);

        // 10 × 3000 (tier1) − 10 × 500 (tier2) − 10 × 500 (tier3) = 20000
        $this->assertSame(20000, $result['totalCents']);
        $this->assertCount(2, $result['tier_breakdown']);
    }

    public function test_calculate_cart_below_lowest_threshold_no_discount(): void
    {
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold1', 'brand' => 'rp'],
            ['value' => '5']
        );
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold2', 'brand' => 'rp'],
            ['value' => '10']
        );

        $user = User::factory()->create();
        $items = $this->buildItems(3, false);
        $result = $this->strategy->calculateCart($items, $user);

        $this->assertSame(9000, $result['totalCents']); // 3 × 3000
        $this->assertEmpty($result['tier_breakdown']);
    }

    public function test_tier_breakdown_is_generated_correctly(): void
    {
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold1', 'brand' => 'rp'],
            ['value' => '5']
        );
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold2', 'brand' => 'rp'],
            ['value' => '10']
        );

        $user = User::factory()->create();
        $items = $this->buildItems(10, false);
        $result = $this->strategy->calculateCart($items, $user);

        $this->assertCount(2, $result['tier_breakdown']);

        $bd1 = $result['tier_breakdown'][0];
        $this->assertSame('discount_fixed', $bd1['type']);
        $this->assertStringContainsString('5', $bd1['filename']);
        $this->assertSame(10, $bd1['qty']);
        $this->assertSame(-500, $bd1['price']);
        $this->assertSame(-5000, $bd1['row_total']);

        $bd2 = $result['tier_breakdown'][1];
        $this->assertSame('discount_fixed', $bd2['type']);
        $this->assertStringContainsString('10', $bd2['filename']);
        $this->assertSame(10, $bd2['qty']);
        $this->assertSame(-500, $bd2['price']);
        $this->assertSame(-5000, $bd2['row_total']);
    }

    public function test_quote_items_are_excluded_from_count(): void
    {
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold1', 'brand' => 'rp'],
            ['value' => '5']
        );
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold2', 'brand' => 'rp'],
            ['value' => '10']
        );

        $user = User::factory()->create();
        // 3 non-quote + 2 quote = 5 total, but only 3 non-quote → below threshold(5)
        $items = [
            ...$this->buildItems(3, false),
            ...$this->buildItems(2, true),
        ];
        $result = $this->strategy->calculateCart($items, $user);

        $this->assertSame(9000, $result['totalCents']); // 3 × 3000
        $this->assertEmpty($result['tier_breakdown']);
    }

    public function test_coupon_integration_with_fixed_discount(): void
    {
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold1', 'brand' => 'rp'],
            ['value' => '5']
        );
        Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold2', 'brand' => 'rp'],
            ['value' => '10']
        );

        $coupon = Coupon::factory()->fixed(10.00)->create([
            'brand' => 'rp',
            'active' => true,
        ]);

        $strategy = new VolumeLicensingStrategy(new SettingResolver(), new CouponService());
        $user = User::factory()->create();
        $items = $this->buildItems(5, false);
        $result = $strategy->calculateCart($items, $user, $coupon->code);

        // Volume: 5 × 3000 − 5 × 500 = 12500
        // Coupon: fixed 10 EUR = 1000 cents
        // Total: 12500 − 1000 = 11500
        $this->assertSame(11500, $result['totalCents']);
        $this->assertSame(1000, $result['discountCents']);
        $this->assertSame($coupon->id, $result['couponId']);
    }

    public function test_empty_items_returns_zero(): void
    {
        $user = User::factory()->create();
        $result = $this->strategy->calculateCart([], $user);

        $this->assertSame(0, $result['totalCents']);
        $this->assertEmpty($result['items']);
        $this->assertEmpty($result['tier_breakdown']);
    }

    private function buildItems(int $count, bool $isQuote): array
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
