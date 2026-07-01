<?php

namespace Tests\Feature\Pricing;

use App\Enums\Brand;
use App\Models\User;
use App\Pricing\VolumeLicensingStrategy;
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
        $this->strategy = new VolumeLicensingStrategy(new SettingResolver());
    }

    public function test_9_images_returns_30_each(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(9, false);
        $result = $this->strategy->calculateCart($items, $user);

        // 9 × 3000 = 27000
        $this->assertSame(27000, $result['totalCents']);
        foreach ($result['items'] as $item) {
            $this->assertSame(3000, $item['priceCents']);
        }
    }

    public function test_10_images_returns_25_each(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(10, false);
        $result = $this->strategy->calculateCart($items, $user);

        // 10 × 3000 (tier1) − 10 × 500 (tier2 discount) = 25000
        $this->assertSame(25000, $result['totalCents']);
        foreach ($result['items'] as $item) {
            $this->assertSame(3000, $item['priceCents']);
        }
    }

    public function test_11_images_returns_25_each(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(11, false);
        $result = $this->strategy->calculateCart($items, $user);

        // 11 × 3000 (tier1) − 11 × 500 (tier2 discount) = 27500
        $this->assertSame(27500, $result['totalCents']);
        foreach ($result['items'] as $item) {
            $this->assertSame(3000, $item['priceCents']);
        }
    }

    public function test_19_images_returns_25_each(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(19, false);
        $result = $this->strategy->calculateCart($items, $user);

        // 19 × 3000 (tier1) − 19 × 500 (tier2 discount) = 47500
        $this->assertSame(47500, $result['totalCents']);
        foreach ($result['items'] as $item) {
            $this->assertSame(3000, $item['priceCents']);
        }
    }

    public function test_20_images_returns_20_each(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(20, false);
        $result = $this->strategy->calculateCart($items, $user);

        // 20 × 3000 (tier1) − 20 × 500 (tier2 discount) − 20 × 500 (tier3 discount) = 40000
        $this->assertSame(40000, $result['totalCents']);
        foreach ($result['items'] as $item) {
            $this->assertSame(3000, $item['priceCents']);
        }
    }

    public function test_21_images_returns_20_each(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(21, false);
        $result = $this->strategy->calculateCart($items, $user);

        // 21 × 3000 (tier1) − 21 × 500 (tier2 discount) − 21 × 500 (tier3 discount) = 42000
        $this->assertSame(42000, $result['totalCents']);
        foreach ($result['items'] as $item) {
            $this->assertSame(3000, $item['priceCents']);
        }
    }

    public function test_quote_items_are_zero(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(3, true);
        $result = $this->strategy->calculateCart($items, $user);

        $this->assertSame(0, $result['totalCents']);
        foreach ($result['items'] as $item) {
            $this->assertSame(0, $item['priceCents']);
        }
    }

    public function test_empty_cart_returns_zero(): void
    {
        $user = User::factory()->create();
        $result = $this->strategy->calculateCart([], $user);

        $this->assertSame(0, $result['totalCents']);
        $this->assertEmpty($result['items']);
    }

    public function test_mixed_quotes_and_normal_items(): void
    {
        $user = User::factory()->create();
        $items = [
            ...$this->buildItems(8, false),  // 8 normal → count = 8 → tier1 (3000)
            ...$this->buildItems(2, true),   // 2 quotes → 0 cents each, not counted
        ];
        $result = $this->strategy->calculateCart($items, $user);

        // 8 × 3000 = 24000
        $this->assertSame(24000, $result['totalCents']);

        // First 8 items (non-quote) should be 3000 each
        for ($i = 0; $i < 8; $i++) {
            $this->assertSame(3000, $result['items'][$i]['priceCents']);
        }
        // Last 2 items (quotes) should be 0 each
        for ($i = 8; $i < 10; $i++) {
            $this->assertSame(0, $result['items'][$i]['priceCents']);
        }
    }

    public function test_volume_pricing_with_custom_settings(): void
    {
        // Set custom thresholds and prices via settings
        \App\Models\Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold1', 'brand' => 'srp'],
            ['value' => '5']
        );
        \App\Models\Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold2', 'brand' => 'srp'],
            ['value' => '15']
        );
        \App\Models\Setting::updateOrCreate(
            ['key' => 'srp_price_per_image_tier1', 'brand' => 'srp'],
            ['value' => '4000']
        );
        \App\Models\Setting::updateOrCreate(
            ['key' => 'srp_price_per_image_tier2', 'brand' => 'srp'],
            ['value' => '3500']
        );
        \App\Models\Setting::updateOrCreate(
            ['key' => 'srp_price_per_image_tier3', 'brand' => 'srp'],
            ['value' => '3000']
        );

        BrandRegistry::set(Brand::SRP);
        $user = User::factory()->create();
        $items = $this->buildItems(12, false);
        $result = $this->strategy->calculateCart($items, $user);
        BrandRegistry::set(null);

        // 12 items ≥ threshold1(5) and < threshold2(15) → tier2
        // 12 × 4000 (tier1) − 12 × 500 (tier2 discount) = 48000 − 6000 = 42000
        $this->assertSame(42000, $result['totalCents']);
        foreach ($result['items'] as $item) {
            $this->assertSame(4000, $item['priceCents']);
        }
    }

    public function test_item_shape_contains_expected_keys(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(1, false);
        $result = $this->strategy->calculateCart($items, $user);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('totalCents', $result);
        $this->assertArrayHasKey('tier_breakdown', $result);
        $this->assertCount(1, $result['items']);

        $item = $result['items'][0];
        $this->assertArrayHasKey('itemId', $item);
        $this->assertArrayHasKey('priceCents', $item);
        $this->assertArrayHasKey('tier', $item);
        $this->assertArrayHasKey('useCaseName', $item);
        $this->assertArrayHasKey('modifierNames', $item);
    }

    public function test_tier_1_has_no_breakdown(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(9, false);
        $result = $this->strategy->calculateCart($items, $user);

        $this->assertEmpty($result['tier_breakdown']);
    }

    public function test_tier_2_breakdown_structure(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(10, false);
        $result = $this->strategy->calculateCart($items, $user);

        $this->assertCount(1, $result['tier_breakdown']);

        $bd = $result['tier_breakdown'][0];
        $this->assertSame('discount_fixed', $bd['type']);
        $this->assertStringContainsString('10', $bd['filename']);
        $this->assertSame(10, $bd['qty']);
        $this->assertSame(-500, $bd['price']);      // tier1(3000) − tier2(2500) = 500
        $this->assertSame(-5000, $bd['row_total']); // 10 × −500
    }

    public function test_tier_3_breakdown_structure(): void
    {
        $user = User::factory()->create();
        $items = $this->buildItems(20, false);
        $result = $this->strategy->calculateCart($items, $user);

        $this->assertCount(2, $result['tier_breakdown']);

        // First line: tier1→tier2 discount
        $bd1 = $result['tier_breakdown'][0];
        $this->assertSame('discount_fixed', $bd1['type']);
        $this->assertStringContainsString('10', $bd1['filename']);
        $this->assertSame(20, $bd1['qty']);
        $this->assertSame(-500, $bd1['price']);
        $this->assertSame(-10000, $bd1['row_total']); // 20 × −500

        // Second line: tier2→tier3 discount
        $bd2 = $result['tier_breakdown'][1];
        $this->assertSame('discount_fixed', $bd2['type']);
        $this->assertStringContainsString('20', $bd2['filename']);
        $this->assertSame(20, $bd2['qty']);
        $this->assertSame(-500, $bd2['price']);
        $this->assertSame(-10000, $bd2['row_total']); // 20 × −500
    }

    public function test_tier_breakdown_with_custom_settings(): void
    {
        \App\Models\Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold1', 'brand' => 'srp'],
            ['value' => '5']
        );
        \App\Models\Setting::updateOrCreate(
            ['key' => 'srp_tier_threshold2', 'brand' => 'srp'],
            ['value' => '15']
        );
        \App\Models\Setting::updateOrCreate(
            ['key' => 'srp_price_per_image_tier1', 'brand' => 'srp'],
            ['value' => '4000']
        );
        \App\Models\Setting::updateOrCreate(
            ['key' => 'srp_price_per_image_tier2', 'brand' => 'srp'],
            ['value' => '3500']
        );
        \App\Models\Setting::updateOrCreate(
            ['key' => 'srp_price_per_image_tier3', 'brand' => 'srp'],
            ['value' => '3000']
        );

        BrandRegistry::set(Brand::SRP);
        $user = User::factory()->create();

        // Tier 2: 12 items ≥ 5 and < 15 → one breakdown line (tier1→tier2)
        $items = $this->buildItems(12, false);
        $result = $this->strategy->calculateCart($items, $user);

        $this->assertCount(1, $result['tier_breakdown']);
        $bd = $result['tier_breakdown'][0];
        $this->assertSame(12, $bd['qty']);
        $this->assertSame(-500, $bd['price']);      // 4000 − 3500 = 500
        $this->assertSame(-6000, $bd['row_total']); // 12 × −500

        // Tier 3: 20 items ≥ 15 → two breakdown lines
        $items = $this->buildItems(20, false);
        $result = $this->strategy->calculateCart($items, $user);

        $this->assertCount(2, $result['tier_breakdown']);
        $this->assertSame(-500, $result['tier_breakdown'][0]['price']);  // 4000 − 3500
        $this->assertSame(-500, $result['tier_breakdown'][1]['price']);  // 3500 − 3000

        BrandRegistry::set(null);
    }

    /**
     * Build n test items that share the same is_quote flag.
     *
     * @return array<int, array{id: string, license_use_case_id: string, license_modifier_ids: array, is_quote: bool}>
     */
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
