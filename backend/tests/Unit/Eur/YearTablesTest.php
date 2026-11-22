<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Parity\RuleValues;
use App\Eur\Reference\EurYear;
use App\Eur\Reference\ReferenceTables;
use App\Eur\Reference\YearTables;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** EÜR · Jahresabhängige Tabellen 2024–2026 (EUE-001 … EUE-022, EUE-062 … EUE-098). */
class YearTablesTest extends TestCase
{
    public function test_tariff_limits_are_year_specific(): void
    {
        $this->assertSame(1281600, YearTables::tariffStages(2024)[0]['grenze']);
        $this->assertSame(1330800, YearTables::tariffStages(2025)[0]['grenze']);
        $this->assertSame(1353900, YearTables::tariffStages(2026)[0]['grenze']);
        $this->assertSame([2000, 3000, 4000, 4800, 5000], array_column(YearTables::tariffStages(2026), 'satz'));
    }

    public function test_basic_allowance_is_pinned_for_all_supported_years(): void
    {
        foreach ([2024, 2025, 2026] as $year) {
            $this->assertSame(['grenze' => 3300000, 'satz' => 1500], YearTables::basicAllowance($year));
        }
    }

    public function test_social_insurance_values_are_year_specific(): void
    {
        $this->assertSame(707000, YearTables::socialInsuranceAmountsCents(2024)['max_bgl']);
        $this->assertSame(752500, YearTables::socialInsuranceAmountsCents(2025)['max_bgl']);
        $this->assertSame(808500, YearTables::socialInsuranceAmountsCents(2026)['max_bgl']);
        $this->assertSame(1295, YearTables::socialInsuranceAmountsCents(2026)['uv_pauschale']);
    }

    public function test_travel_rates_cover_the_2025_half_year_switch(): void
    {
        $this->assertSame(42, YearTables::travelPerKmCents('PKW', 2024));
        $this->assertSame(50, YearTables::travelPerKmCents('PKW', 2025));
        $this->assertSame(50, YearTables::travelPerKmCents('Motorrad', 2025, 6));
        $this->assertSame(25, YearTables::travelPerKmCents('Motorrad', 2025, 7));
        $this->assertSame(25, YearTables::travelPerKmCents('Fahrrad', 2026));
        $this->assertSame(1500, YearTables::travelQuota('Fahrrad', 2024));
        $this->assertSame(3000, YearTables::travelQuota('Fahrrad', 2026));
        $this->assertSame(6, YearTables::maximumPassengers('PKW'));
        $this->assertSame(15, YearTables::passengerSurchargeCents('PKW', 2026));
    }

    public function test_unknown_year_is_rejected_instead_of_defaulting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        YearTables::tariffStages(2027);
    }

    public function test_year_warnings_follow_eue_002_and_003(): void
    {
        $this->assertTrue(EurYear::warning(2026)['supported']);
        $this->assertSame('EUE-002', EurYear::warning(2027)['code']);
        $this->assertSame('EUE-003', EurYear::warning(2023)['code']);
    }

    public function test_rule_values_and_reference_tables_accept_the_supported_range(): void
    {
        $this->assertNotSame(
            (new RuleValues)->canonicalFor('EUE-014', 2024, 'cents'),
            (new RuleValues)->canonicalFor('EUE-014', 2026, 'cents'),
        );

        ReferenceTables::load()->assertBusinessYear(2024);
        ReferenceTables::load()->assertBusinessYear(2026);

        $this->expectException(InvalidArgumentException::class);
        ReferenceTables::load()->assertBusinessYear(2027);
    }
}
