<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Domain\AssetPlausibility;
use App\Eur\Domain\DepreciationCalculator;
use App\Eur\Parity\RuleValues;
use PHPUnit\Framework\TestCase;

final class AssetPlausibilityTest extends TestCase
{
    private function plausibility(): AssetPlausibility
    {
        $rules = new RuleValues;

        return new AssetPlausibility(
            new DepreciationCalculator($rules),
            $rules->parameterFor('EUE-056', 'Toleranz AfA', 2026, 'cents'),
        );
    }

    /** @return array<string, mixed> */
    private function linearAsset(array $overrides = []): array
    {
        return array_merge([
            'current_year' => 2026,
            'acquisition_year' => 2024,
            'acquisition_month' => 1,
            'cost_cents' => 30000,
            'useful_life_years' => 3,
            'method' => DepreciationCalculator::METHOD_LINEAR,
            'degressive_rate_per_10000' => 0,
            'switch_year' => null,
            'years' => [
                ['year' => 2024, 'depreciation_cents' => 10000, 'book_value_cents' => 20000],
                ['year' => 2025, 'depreciation_cents' => 10000, 'book_value_cents' => 10000],
            ],
        ], $overrides);
    }

    public function test_matching_prior_years_produce_no_warning(): void
    {
        $warnings = $this->plausibility()->warnings($this->linearAsset());

        $this->assertSame([], $warnings);
    }

    public function test_eue_056_warns_when_prior_year_depreciation_differs_by_more_than_one_cent(): void
    {
        $asset = $this->linearAsset([
            'years' => [
                ['year' => 2024, 'depreciation_cents' => 10000, 'book_value_cents' => 20000],
                ['year' => 2025, 'depreciation_cents' => 12000, 'book_value_cents' => 10000],
            ],
        ]);

        $warnings = $this->plausibility()->warnings($asset);

        $this->assertCount(1, $warnings);
        $this->assertSame('EUE-056', $warnings[0]['code']);
        $this->assertStringContainsString('Abschreibung 2025', $warnings[0]['text']);
        $this->assertStringContainsString('erwartet: 100,00 €', $warnings[0]['text']);
        $this->assertStringContainsString('tatsächlich: 120,00 €', $warnings[0]['text']);
    }

    public function test_pinned_one_cent_tolerance_does_not_warn_at_the_boundary(): void
    {
        $asset = $this->linearAsset([
            'years' => [
                ['year' => 2024, 'depreciation_cents' => 10000, 'book_value_cents' => 20000],
                ['year' => 2025, 'depreciation_cents' => 10001, 'book_value_cents' => 10000],
            ],
        ]);

        $this->assertSame([], $this->plausibility()->warnings($asset));
    }

    public function test_eue_057_warns_when_prior_year_book_value_is_missing(): void
    {
        $asset = $this->linearAsset([
            'years' => [
                ['year' => 2024, 'depreciation_cents' => 10000, 'book_value_cents' => 20000],
                ['year' => 2025, 'depreciation_cents' => 10000, 'book_value_cents' => null],
            ],
        ]);

        $warnings = $this->plausibility()->warnings($asset);

        $this->assertCount(1, $warnings);
        $this->assertSame('EUE-057', $warnings[0]['code']);
        $this->assertStringContainsString('Restbuchwert 2025 fehlt.', $warnings[0]['text']);
    }

    public function test_zero_book_value_is_the_portal_equivalent_of_a_missing_ods_cell(): void
    {
        $asset = $this->linearAsset([
            'years' => [
                ['year' => 2024, 'depreciation_cents' => 10000, 'book_value_cents' => 20000],
                ['year' => 2025, 'depreciation_cents' => 10000, 'book_value_cents' => 0],
            ],
        ]);

        $warnings = $this->plausibility()->warnings($asset);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Restbuchwert 2025 fehlt.', $warnings[0]['text']);
    }

    public function test_eue_057_warns_when_prior_year_book_value_differs(): void
    {
        $asset = $this->linearAsset([
            'years' => [
                ['year' => 2024, 'depreciation_cents' => 10000, 'book_value_cents' => 20000],
                ['year' => 2025, 'depreciation_cents' => 10000, 'book_value_cents' => 12345],
            ],
        ]);

        $warnings = $this->plausibility()->warnings($asset);

        $this->assertCount(1, $warnings);
        $this->assertSame('EUE-057', $warnings[0]['code']);
        $this->assertStringContainsString('Differenz: 2345 ct', $warnings[0]['text']);
    }

    public function test_eue_057_asks_to_remove_a_prior_year_value_in_the_acquisition_year(): void
    {
        $asset = $this->linearAsset([
            'acquisition_year' => 2026,
            'years' => [
                ['year' => 2025, 'depreciation_cents' => 10000, 'book_value_cents' => 20000],
            ],
        ]);

        $warnings = $this->plausibility()->warnings($asset);

        $this->assertCount(1, $warnings);
        $this->assertSame('EUE-057', $warnings[0]['code']);
        $this->assertStringContainsString('Abschreibung 2025 entfernen.', $warnings[0]['text']);
    }

    public function test_a_completed_method_switch_suspends_the_reconstructed_plan_comparison(): void
    {
        // DEG-009/014: gespeicherte degressive Historie + Wechsel auf linear.
        // Ein rekonstruierter Plan würde hier drei Fehlalarme erzeugen.
        $asset = $this->linearAsset([
            'method' => DepreciationCalculator::METHOD_LINEAR,
            'switched' => true,
            'degressive_rate_per_10000' => 2000,
            'years' => [
                ['year' => 2024, 'depreciation_cents' => 6000, 'book_value_cents' => 24000],
                ['year' => 2025, 'depreciation_cents' => 12000, 'book_value_cents' => 12000],
            ],
        ]);

        $this->assertSame([], $this->plausibility()->warnings($asset));
    }

    public function test_degressive_expectation_follows_the_chosen_plan(): void
    {
        $asset = $this->linearAsset([
            'method' => DepreciationCalculator::METHOD_DEGRESSIVE,
            'degressive_rate_per_10000' => 2000,
            'years' => [
                ['year' => 2024, 'depreciation_cents' => 6000, 'book_value_cents' => 24000],
                ['year' => 2025, 'depreciation_cents' => 5000, 'book_value_cents' => 19200],
            ],
        ]);

        $warnings = $this->plausibility()->warnings($asset);

        $this->assertCount(1, $warnings);
        $this->assertSame('EUE-056', $warnings[0]['code']);
        $this->assertStringContainsString('erwartet: 48,00 €', $warnings[0]['text']);
    }
}
