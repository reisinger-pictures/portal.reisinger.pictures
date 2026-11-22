<?php

namespace Tests\Unit\Eur;

use App\Eur\Domain\DepreciationCalculator;
use App\Eur\Parity\RuleValues;
use PHPUnit\Framework\TestCase;

/**
 * EÜR · Anlagen und AfA (EUE-049 … EUE-061, DEG-001 … DEG-015).
 *
 * Die Erwartungswerte sind die im gepinnten DEG-Artefakt nachgerechneten
 * Beträge. Geprüft werden lineare AfA, Halbjahresregel, Erinnerungswert,
 * GWG-Grenze, Satzgrenzen und der einmalige Wechsel zur linearen AfA.
 */
class DepreciationCalculatorTest extends TestCase
{
    private DepreciationCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new DepreciationCalculator(new RuleValues);
    }

    public function test_limits_come_from_the_rule_catalogue(): void
    {
        $this->assertSame(100000, $this->calculator->lowValueLimitCents());
        $this->assertSame(50, $this->calculator->minimumBusinessSharePercent());
        $this->assertSame(2, $this->calculator->usefulLifeMinimum());
        $this->assertSame(99, $this->calculator->usefulLifeMaximum());
    }

    public function test_low_value_asset_boundary(): void
    {
        // assets-requirements.md §3: "unter 1.000 €"; genau 1.000 € löst keinen Hinweis aus.
        $this->assertTrue($this->calculator->isLowValueAsset(99999));
        $this->assertFalse($this->calculator->isLowValueAsset(100000));
        $this->assertFalse($this->calculator->isLowValueAsset(100001));
        $this->assertFalse($this->calculator->isLowValueAsset(0));
    }

    public function test_business_share_requires_fifty_to_hundred_percent_in_five_percent_steps(): void
    {
        $this->assertTrue($this->calculator->isBusinessShareValid(50));
        $this->assertTrue($this->calculator->isBusinessShareValid(100));
        $this->assertFalse($this->calculator->isBusinessShareValid(45));
        $this->assertFalse($this->calculator->isBusinessShareValid(53));
        $this->assertFalse($this->calculator->isBusinessShareValid(110));
    }

    public function test_linear_afa_and_half_year_rule(): void
    {
        $this->assertSame(200000, $this->calculator->linearAnnualCents(2000000, 10, 3));
        $this->assertSame(100000, $this->calculator->linearAnnualCents(2000000, 10, 7));
        $this->assertSame(0, $this->calculator->linearAnnualCents(2000000, 0, 3));
    }

    public function test_residual_keeps_one_cent_memory_value(): void
    {
        $this->assertSame(1, $this->calculator->residualCents(2000000, 2000000));
        $this->assertSame(1, $this->calculator->residualCents(2000000, 3000000));
        $this->assertSame(500000, $this->calculator->residualCents(2000000, 1500000));
    }

    public function test_degressive_rate_is_limited_to_one_through_thirty_percent(): void
    {
        $this->assertTrue($this->calculator->isDegressiveRateValid(100));
        $this->assertTrue($this->calculator->isDegressiveRateValid(3000));
        $this->assertFalse($this->calculator->isDegressiveRateValid(0));
        $this->assertFalse($this->calculator->isDegressiveRateValid(3100));
    }

    public function test_degressive_plan_matches_the_documented_example(): void
    {
        $plan = $this->calculator->degressivePlan(2000000, 10, 3000, 1);

        $this->assertSame(600000, $plan[0]['depreciation_cents']);
        $this->assertSame(1400000, $plan[0]['book_value_cents']);
        $this->assertSame(420000, $plan[1]['depreciation_cents']);
        $this->assertSame(980000, $plan[1]['book_value_cents']);
        $this->assertSame(294000, $plan[2]['depreciation_cents']);
        $this->assertSame(686000, $plan[2]['book_value_cents']);
        $this->assertSame(70589, $plan[6]['depreciation_cents']);
        $this->assertSame(164709, $plan[6]['book_value_cents']);
        $this->assertSame(1, $plan[9]['book_value_cents']);
    }

    public function test_july_acquisition_halves_the_first_degressive_year(): void
    {
        $plan = $this->calculator->degressivePlan(2000000, 10, 3000, 7);

        $this->assertSame(300000, $plan[0]['depreciation_cents']);
    }

    public function test_once_only_switch_spreads_the_residual_over_remaining_years(): void
    {
        $plan = $this->calculator->degressivePlan(2000000, 10, 3000, 1, 8);

        $this->assertSame('degressive', $plan[6]['method']);
        $this->assertSame('linear', $plan[7]['method']);
        $this->assertSame(54903, $plan[7]['depreciation_cents']);
        $this->assertSame(109806, $plan[7]['book_value_cents']);
        $this->assertSame(54903, $plan[8]['depreciation_cents']);
        $this->assertSame(54903, $plan[8]['book_value_cents']);
        $this->assertSame(54902, $plan[9]['depreciation_cents']);
        $this->assertSame(1, $plan[9]['book_value_cents']);
    }

    public function test_recommends_the_first_year_where_linear_catches_up(): void
    {
        $this->assertSame(8, $this->calculator->recommendedSwitchYear(2000000, 10, 3000, 1));
        $this->assertSame(25804, $this->calculator->switchAdvantageCents(2000000, 10, 3000, 1));
    }

    public function test_switch_year_outside_the_useful_life_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->degressivePlan(2000000, 10, 3000, 1, 11);
    }

    public function test_degressive_rate_outside_the_range_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->degressivePlan(2000000, 10, 3100, 1);
    }
}
