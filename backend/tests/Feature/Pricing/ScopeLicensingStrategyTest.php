<?php

namespace Tests\Feature\Pricing;

use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScopeLicensingStrategyTest extends TestCase
{
    use RefreshDatabase;

    private ScopeLicensingStrategy $strategy;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new ScopeLicensingStrategy();
        $this->user = User::factory()->create(['flatrate_level' => 'web']);
    }

    public function test_base_price_full_when_user_rank_below_required_tier(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'name' => 'Print Use',
            'base_price' => 5000,
            'flatrate_tier' => 'print',
        ]);

        $this->user->flatrate_level = 'web';
        $result = $this->strategy->calculateCart([
            ['id' => 1, 'license_use_case_id' => $useCase->id, 'license_modifier_ids' => [], 'is_quote' => false],
        ], $this->user);

        $this->assertSame(5000, $result['totalCents']);
        $this->assertSame(5000, $result['items'][0]['priceCents']);
        $this->assertSame('print', $result['items'][0]['tier']);
        $this->assertSame('Print Use', $result['items'][0]['useCaseName']);
        $this->assertSame([], $result['items'][0]['modifierNames']);
    }

    public function test_base_price_zero_when_user_rank_equals_required_tier(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 5000,
            'flatrate_tier' => 'web',
        ]);

        $result = $this->strategy->calculateCart([
            ['id' => 1, 'license_use_case_id' => $useCase->id, 'license_modifier_ids' => [], 'is_quote' => false],
        ], $this->user);

        $this->assertSame(0, $result['totalCents']);
        $this->assertSame(0, $result['items'][0]['priceCents']);
    }

    public function test_base_price_zero_when_user_rank_above_required_tier(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'base_price' => 5000,
            'flatrate_tier' => 'web',
        ]);

        $this->user->flatrate_level = 'original';
        $result = $this->strategy->calculateCart([
            ['id' => 1, 'license_use_case_id' => $useCase->id, 'license_modifier_ids' => [], 'is_quote' => false],
        ], $this->user);

        $this->assertSame(0, $result['totalCents']);
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

        $this->user->flatrate_level = 'web';
        $result = $this->strategy->calculateCart([
            ['id' => 1, 'license_use_case_id' => $useCase->id, 'license_modifier_ids' => [$mod->id], 'is_quote' => false],
        ], $this->user);

        // base 10000 + surcharge 5000 = 15000
        $this->assertSame(15000, $result['totalCents']);
        $this->assertSame(['Exclusivity'], $result['items'][0]['modifierNames']);
    }

    public function test_multiple_items_summed_correctly(): void
    {
        $useCase1 = LicenseUseCase::factory()->create([
            'name' => 'Web Use',
            'base_price' => 3000,
            'flatrate_tier' => 'web',
        ]);
        $useCase2 = LicenseUseCase::factory()->create([
            'name' => 'Print Use',
            'base_price' => 5000,
            'flatrate_tier' => 'print',
        ]);

        $this->user->flatrate_level = 'web';
        $result = $this->strategy->calculateCart([
            ['id' => 1, 'license_use_case_id' => $useCase1->id, 'license_modifier_ids' => [], 'is_quote' => false],
            ['id' => 2, 'license_use_case_id' => $useCase2->id, 'license_modifier_ids' => [], 'is_quote' => false],
        ], $this->user);

        // Item 1: covered (web=web) → 0
        // Item 2: not covered (web < print) → 5000
        $this->assertSame(5000, $result['totalCents']);
        $this->assertSame(0, $result['items'][0]['priceCents']);
        $this->assertSame(5000, $result['items'][1]['priceCents']);
    }

    public function test_quote_item_returns_zero(): void
    {
        $result = $this->strategy->calculateCart([
            ['id' => 1, 'license_use_case_id' => '', 'license_modifier_ids' => [], 'is_quote' => true],
        ], $this->user);

        $this->assertSame(0, $result['totalCents']);
        $this->assertSame(0, $result['items'][0]['priceCents']);
        $this->assertSame('Anfrage', $result['items'][0]['useCaseName']);
    }

    public function test_throws_when_use_case_missing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->strategy->calculateCart([
            ['id' => 1, 'license_use_case_id' => 'non-existent-uuid', 'license_modifier_ids' => [], 'is_quote' => false],
        ], $this->user);
    }

    public function test_return_shape_contains_all_expected_keys(): void
    {
        $useCase = LicenseUseCase::factory()->create([
            'name' => 'Editorial',
            'base_price' => 1000,
            'flatrate_tier' => 'web',
        ]);

        $result = $this->strategy->calculateCart([
            ['id' => 1, 'license_use_case_id' => $useCase->id, 'license_modifier_ids' => [], 'is_quote' => false],
        ], $this->user);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('totalCents', $result);
        $this->assertCount(1, $result['items']);

        $item = $result['items'][0];
        $this->assertArrayHasKey('itemId', $item);
        $this->assertArrayHasKey('priceCents', $item);
        $this->assertArrayHasKey('tier', $item);
        $this->assertArrayHasKey('useCaseName', $item);
        $this->assertArrayHasKey('modifierNames', $item);
    }
}
