<?php

namespace Tests\Feature;

use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Services\PricingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private PricingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PricingService();
    }

    public function test_calculate_item_price_throws_when_use_case_missing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->calculateItemPriceCents('non-existent-uuid', null, 'web');
    }

    public function test_base_price_full_when_user_rank_below_required_tier(): void
    {
        // tier 'print' (rank 2), user 'web' (rank 1) -> not covered
        $useCase = LicenseUseCase::factory()->create([
            'name' => 'Print Use',
            'base_price' => 5000,
            'flatrate_tier' => 'print',
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, null, 'web');

        $this->assertSame(5000, $result['total_cents']);
        $this->assertSame('print', $result['tier']);
        $this->assertSame('Print Use', $result['use_case_name']);
        $this->assertSame([], $result['modifier_names']);
    }

    public function test_base_price_zero_when_user_rank_equals_required_tier(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 5000,
            'flatrate_tier' => 'web',
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, null, 'web');

        $this->assertSame(0, $result['total_cents']);
        $this->assertSame('web', $result['tier']);
    }

    public function test_base_price_zero_when_user_rank_above_required_tier(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 5000,
            'flatrate_tier' => 'web',
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, null, 'original');

        $this->assertSame(0, $result['total_cents']);
    }

    public function test_tier_defaults_to_web_when_flatrate_tier_is_null(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 1000,
            'flatrate_tier' => null,
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, null, 'none');

        // user 'none' (rank 0) < 'web' (rank 1) -> not covered -> full price
        $this->assertSame('web', $result['tier']);
        $this->assertSame(1000, $result['total_cents']);
    }

    public function test_none_user_level_pays_full_price_for_any_tier(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 3000,
            'flatrate_tier' => 'none',
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, null, 'none');

        // tier 'none' (rank 0) == user 'none' (rank 0) -> covered
        $this->assertSame(0, $result['total_cents']);
    }

    public function test_unknown_user_flatrate_level_treated_as_rank_zero(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 4000,
            'flatrate_tier' => 'web',
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, null, 'platinum');

        // unknown -> rank 0 -> not covered (web is rank 1)
        $this->assertSame(4000, $result['total_cents']);
    }

    public function test_modifier_null_treated_like_empty_array(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 5000,
            'flatrate_tier' => 'print',
        ]);

        $resultNull = $this->service->calculateItemPriceCents($useCase->id, null, 'none');
        $resultEmpty = $this->service->calculateItemPriceCents($useCase->id, [], 'none');

        $this->assertSame($resultNull, $resultEmpty);
        $this->assertSame(5000, $resultNull['total_cents']);
        $this->assertSame([], $resultNull['modifier_names']);
    }

    public function test_modifier_surcharge_added_when_user_not_covered(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 10000,
            'flatrate_tier' => 'original',
        ]);
        $mod = LicenseModifier::factory()->create([
            'name' => 'Exclusivity',
            'percent_surcharge' => 50,
            'is_included_in_flatrate' => false,
        ]);

        // user 'web' (rank 1) < 'original' (rank 3) -> base not covered
        $result = $this->service->calculateItemPriceCents($useCase->id, [$mod->id], 'web');

        // base 10000 + surcharge 5000 = 15000
        $this->assertSame(15000, $result['total_cents']);
        $this->assertSame(['Exclusivity'], $result['modifier_names']);
    }

    public function test_modifier_surcharge_added_even_when_base_covered_if_not_included_in_flatrate(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 10000,
            'flatrate_tier' => 'web',
        ]);
        $mod = LicenseModifier::factory()->create([
            'name' => 'Territory',
            'percent_surcharge' => 25,
            'is_included_in_flatrate' => false,
        ]);

        // user 'web' (rank 1) == 'web' (rank 1) -> base covered; modifier not included -> surcharge applies
        $result = $this->service->calculateItemPriceCents($useCase->id, [$mod->id], 'web');

        // base 0 (covered) + surcharge 2500 = 2500
        $this->assertSame(2500, $result['total_cents']);
        $this->assertSame(['Territory'], $result['modifier_names']);
    }

    public function test_modifier_skipped_when_base_covered_and_included_in_flatrate(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 10000,
            'flatrate_tier' => 'web',
        ]);
        $mod = LicenseModifier::factory()->create([
            'name' => 'Included Bonus',
            'percent_surcharge' => 80,
            'is_included_in_flatrate' => true,
        ]);

        // base covered + included -> skipped entirely
        $result = $this->service->calculateItemPriceCents($useCase->id, [$mod->id], 'web');

        $this->assertSame(0, $result['total_cents']);
        // name is pushed BEFORE the skip check
        $this->assertSame(['Included Bonus'], $result['modifier_names']);
    }

    public function test_modifier_included_in_flatrate_still_charged_when_base_not_covered(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 10000,
            'flatrate_tier' => 'original',
        ]);
        $mod = LicenseModifier::factory()->create([
            'name' => 'Premium',
            'percent_surcharge' => 10,
            'is_included_in_flatrate' => true,
        ]);

        // base NOT covered (web < original), so is_included_in_flatrate is irrelevant
        $result = $this->service->calculateItemPriceCents($useCase->id, [$mod->id], 'web');

        // base 10000 + surcharge 1000 = 11000
        $this->assertSame(11000, $result['total_cents']);
    }

    public function test_modifier_with_zero_percent_surcharge_adds_nothing(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 5000,
            'flatrate_tier' => 'original',
        ]);
        $mod = LicenseModifier::factory()->create([
            'name' => 'Freebie',
            'percent_surcharge' => 0,
            'is_included_in_flatrate' => false,
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, [$mod->id], 'web');

        // base 5000 + 0 surcharge
        $this->assertSame(5000, $result['total_cents']);
        $this->assertSame(['Freebie'], $result['modifier_names']);
    }

    public function test_modifier_with_one_hundred_percent_surcharge_doubles_total(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 4000,
            'flatrate_tier' => 'original',
        ]);
        $mod = LicenseModifier::factory()->create([
            'name' => 'Double',
            'percent_surcharge' => 100,
            'is_included_in_flatrate' => false,
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, [$mod->id], 'web');

        // base 4000 + 4000 = 8000
        $this->assertSame(8000, $result['total_cents']);
    }

    public function test_multiple_modifiers_surcharge_summed(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 10000,
            'flatrate_tier' => 'original',
        ]);
        $mod1 = LicenseModifier::factory()->create([
            'name' => 'A',
            'percent_surcharge' => 10,
            'is_included_in_flatrate' => false,
        ]);
        $mod2 = LicenseModifier::factory()->create([
            'name' => 'B',
            'percent_surcharge' => 20,
            'is_included_in_flatrate' => false,
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, [$mod1->id, $mod2->id], 'web');

        // base 10000 + (1000 + 2000) = 13000
        $this->assertSame(13000, $result['total_cents']);
        $this->assertSame(['A', 'B'], $result['modifier_names']);
    }

    public function test_surcharge_rounded_to_nearest_cent(): void
    {
        // 3333 * 10/100 = 333.3 -> rounds to 333
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 3333,
            'flatrate_tier' => 'original',
        ]);
        $mod = LicenseModifier::factory()->create([
            'name' => 'Round',
            'percent_surcharge' => 10,
            'is_included_in_flatrate' => false,
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, [$mod->id], 'web');

        // base 3333 + 333 = 3666
        $this->assertSame(3666, $result['total_cents']);
    }

    public function test_surcharge_rounds_half_up(): void
    {
        // 5 * 15/100 = 0.75 -> rounds to 1
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 5,
            'flatrate_tier' => 'original',
        ]);
        $mod = LicenseModifier::factory()->create([
            'name' => 'HalfUp',
            'percent_surcharge' => 15,
            'is_included_in_flatrate' => false,
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, [$mod->id], 'web');

        // base 5 + round(0.75) = 5 + 1 = 6
        $this->assertSame(6, $result['total_cents']);
    }

    public function test_zero_base_price_with_modifier(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 0,
            'flatrate_tier' => 'original',
        ]);
        $mod = LicenseModifier::factory()->create([
            'name' => 'Zero Base',
            'percent_surcharge' => 100,
            'is_included_in_flatrate' => false,
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, [$mod->id], 'web');

        // base 0 + surcharge (0 * 100/100 = 0) = 0
        $this->assertSame(0, $result['total_cents']);
        $this->assertSame(['Zero Base'], $result['modifier_names']);
    }

    public function test_unknown_modifier_id_in_array_is_silently_ignored(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 1000,
            'flatrate_tier' => 'original',
        ]);

        // only one unknown id in array -> whereIn returns empty collection
        $result = $this->service->calculateItemPriceCents($useCase->id, ['unknown-uuid'], 'web');

        $this->assertSame(1000, $result['total_cents']);
        $this->assertSame([], $result['modifier_names']);
    }

    public function test_unknown_tier_value_falls_back_to_rank_zero(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 5000,
            'flatrate_tier' => 'ultra', // not in rank map
        ]);

        // tier 'ultra' -> rank 0; user 'none' -> rank 0; 0 >= 0 -> covered
        $result = $this->service->calculateItemPriceCents($useCase->id, null, 'none');

        $this->assertSame('ultra', $result['tier']);
        $this->assertSame(0, $result['total_cents']);
    }

    public function test_return_shape_contains_all_expected_keys(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'name' => 'Editorial',
            'base_price' => 1000,
            'flatrate_tier' => 'web',
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, null, 'web');

        $this->assertArrayHasKey('total_cents', $result);
        $this->assertArrayHasKey('tier', $result);
        $this->assertArrayHasKey('use_case_name', $result);
        $this->assertArrayHasKey('modifier_names', $result);
        $this->assertIsInt($result['total_cents']);
        $this->assertIsString($result['tier']);
        $this->assertIsString($result['use_case_name']);
        $this->assertIsArray($result['modifier_names']);
    }

    #[DataProvider('levelTierCoverageProvider')]
    public function test_base_coverage_matrix(string $tier, string $userLevel, int $expectedTotal, bool $covered): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 1000,
            'flatrate_tier' => $tier,
        ]);

        $result = $this->service->calculateItemPriceCents($useCase->id, null, $userLevel);

        $this->assertSame($expectedTotal, $result['total_cents']);
        if ($covered) {
            $this->assertSame(0, $result['total_cents']);
        }
    }

    public static function levelTierCoverageProvider(): array
    {
        // base 1000; covered when userRank >= tierRank
        // ranks: none=0, web=1, print=2, original=3
        return [
            // tier none (rank 0)
            'none/none -> covered' => ['none', 'none', 0, true],
            'none/web -> covered' => ['none', 'web', 0, true],
            'none/print -> covered' => ['none', 'print', 0, true],
            'none/original -> covered' => ['none', 'original', 0, true],
            // tier web (rank 1)
            'web/none -> not covered' => ['web', 'none', 1000, false],
            'web/web -> covered' => ['web', 'web', 0, true],
            'web/print -> covered' => ['web', 'print', 0, true],
            'web/original -> covered' => ['web', 'original', 0, true],
            // tier print (rank 2)
            'print/none -> not covered' => ['print', 'none', 1000, false],
            'print/web -> not covered' => ['print', 'web', 1000, false],
            'print/print -> covered' => ['print', 'print', 0, true],
            'print/original -> covered' => ['print', 'original', 0, true],
            // tier original (rank 3)
            'original/none -> not covered' => ['original', 'none', 1000, false],
            'original/web -> not covered' => ['original', 'web', 1000, false],
            'original/print -> not covered' => ['original', 'print', 1000, false],
            'original/original -> covered' => ['original', 'original', 0, true],
        ];
    }
}
