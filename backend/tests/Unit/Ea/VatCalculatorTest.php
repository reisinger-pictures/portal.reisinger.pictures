<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Ea\Domain\VatCalculator;
use PHPUnit\Framework\TestCase;

/**
 * EAR · Umsatzsteuer (EUE-082 … EUE-088).
 */
class VatCalculatorTest extends TestCase
{
    private VatCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new VatCalculator;
    }

    public function test_kz_016_and_000_carry_the_small_business_income(): void
    {
        // EUE-083
        $result = $this->calculator->forMonth(['small_business_income_cents' => 125000]);

        $this->assertSame(125000, $result[VatCalculator::KZ_SMALL_BUSINESS]);
        $this->assertSame(125000, $result[VatCalculator::KZ_TOTAL]);
        $this->assertNull($result['zahllast']);
    }

    public function test_reverse_charge_goes_to_kz_057_and_not_into_kz_000(): void
    {
        // EUE-084
        $result = $this->calculator->forMonth([
            'small_business_income_cents' => 50000,
            'reverse_charge_cents' => 2000,
        ]);

        $this->assertSame(2000, $result[VatCalculator::KZ_REVERSE_CHARGE]);
        $this->assertSame(50000, $result[VatCalculator::KZ_TOTAL]);
        $this->assertSame(2000, $result['zahllast']);
    }

    public function test_ig_acquisitions_are_distributed_to_the_four_rates(): void
    {
        // EUE-085
        $result = $this->calculator->forMonth([
            'ig_bases_cents' => [20 => 100000, 10 => 50000, 13 => 30000, 49 => 10000],
        ]);

        $this->assertSame(100000, $result[VatCalculator::KZ_IG_20]);
        $this->assertSame(50000, $result[VatCalculator::KZ_IG_10]);
        $this->assertSame(30000, $result[VatCalculator::KZ_IG_13]);
        $this->assertSame(10000, $result[VatCalculator::KZ_IG_49]);
        $this->assertSame(190000, $result[VatCalculator::KZ_IG_TOTAL]);
    }

    public function test_zahllast_adds_reverse_charge_and_all_four_rate_shares(): void
    {
        // EUE-086: 2000 + 20 % von 100000 + 10 % von 50000 + 13 % von 30000
        // + 4,9 % von 10000 = 2000 + 20000 + 5000 + 3900 + 490 = 31390.
        $result = $this->calculator->forMonth([
            'reverse_charge_cents' => 2000,
            'ig_bases_cents' => [20 => 100000, 10 => 50000, 13 => 30000, 49 => 10000],
        ]);

        $this->assertSame(31390, $result['zahllast']);
    }

    public function test_empty_month_stays_empty_instead_of_zero(): void
    {
        // EUE-087: leere Gruppen bleiben leer.
        $result = $this->calculator->forMonth([]);

        $this->assertNull($result[VatCalculator::KZ_TOTAL]);
        $this->assertNull($result[VatCalculator::KZ_REVERSE_CHARGE]);
        $this->assertNull($result[VatCalculator::KZ_IG_TOTAL]);
        $this->assertNull($result['zahllast']);
    }

    public function test_quarters_and_year_aggregate_only_present_values(): void
    {
        // EUE-087
        $months = [
            1 => $this->calculator->forMonth(['small_business_income_cents' => 10000]),
            2 => $this->calculator->forMonth(['small_business_income_cents' => 20000]),
            4 => $this->calculator->forMonth(['reverse_charge_cents' => 500]),
        ];

        $result = $this->calculator->aggregate($months);

        $this->assertSame(30000, $result['quarters'][1][VatCalculator::KZ_TOTAL]);
        $this->assertSame(500, $result['quarters'][2][VatCalculator::KZ_REVERSE_CHARGE]);
        $this->assertNull($result['quarters'][3][VatCalculator::KZ_TOTAL]);
        $this->assertSame(30000, $result['year'][VatCalculator::KZ_TOTAL]);
        $this->assertSame(500, $result['year'][VatCalculator::KZ_REVERSE_CHARGE]);
    }
}
