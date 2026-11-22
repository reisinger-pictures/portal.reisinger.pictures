<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Domain\TravelCalculator;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\ReferenceTables;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * EAR · Reisen und Fahrtkosten (EUE-062 … EUE-081).
 */
class TravelCalculatorTest extends TestCase
{
    private TravelCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $tables = ReferenceTables::load();
        $this->calculator = new TravelCalculator(new RuleValues, $tables->travel());
    }

    public function test_km_rates_come_from_the_pinned_catalogue(): void
    {
        // EUE-062/063/064
        $this->assertSame(50, $this->calculator->kilometreRateCents('PKW'));
        $this->assertSame(25, $this->calculator->kilometreRateCents('Motorrad'));
        $this->assertSame(25, $this->calculator->kilometreRateCents('Fahrrad'));
    }

    public function test_passenger_surcharge_and_limit(): void
    {
        // EUE-062/066: 50 + 3 × 15 Cent je Kilometer, höchstens 6 Mitfahrer.
        $this->assertSame(9500, $this->calculator->kilometreAllowanceCents('PKW', 100, 3));
        $this->assertSame(6, $this->calculator->maximumPassengers('PKW'));
        $this->assertSame(0, $this->calculator->maximumPassengers('Fahrrad'));

        $this->expectException(InvalidArgumentException::class);
        $this->calculator->kilometreAllowanceCents('PKW', 100, 7);
    }

    public function test_vehicle_and_bicycle_quotas(): void
    {
        // EUE-065
        $this->assertSame(30000, $this->calculator->kilometreQuota('PKW'));
        $this->assertSame(30000, $this->calculator->kilometreQuota('Motorrad'));
        $this->assertSame(3000, $this->calculator->kilometreQuota('Fahrrad'));
    }

    public function test_distance_accepts_readings_or_distance_but_never_both(): void
    {
        // EUE-067
        $this->assertSame(120, $this->calculator->distance(1000, 1120, null));
        $this->assertSame(85, $this->calculator->distance(null, null, 85));

        $this->expectException(InvalidArgumentException::class);
        $this->calculator->distance(1000, 1120, 85);
    }

    public function test_meal_deduction_domestic_and_abroad(): void
    {
        // EUE-068: Inland halbe/keine Pauschale, Ausland volle/ein Drittel.
        $this->assertSame(3000, $this->calculator->perDiemCents(3000, 0, true));
        $this->assertSame(1500, $this->calculator->perDiemCents(3000, 1, true));
        $this->assertSame(0, $this->calculator->perDiemCents(3000, 2, true));
        $this->assertSame(3000, $this->calculator->perDiemCents(3000, 1, false));
        $this->assertSame(1000, $this->calculator->perDiemCents(3000, 2, false));
    }

    public function test_five_and_fifteen_day_rule_stops_day_and_night_money(): void
    {
        // EUE-070: ab Tag 5 kein Tagesgeld, ab Tag 15 keine Nächtigung.
        $short = $this->calculator->dailyAllowancesCents('Österreich', 3, 0, true);
        $long = $this->calculator->dailyAllowancesCents('Österreich', 20, 0, true);

        $this->assertSame(9000, $short['day_cents']);
        $this->assertSame(5100, $short['night_cents']);
        $this->assertSame(12000, $long['day_cents']);
        $this->assertSame(23800, $long['night_cents']);
    }

    public function test_night_without_receipt_uses_the_flat_rate(): void
    {
        // EUE-069
        $domestic = $this->calculator->dailyAllowancesCents('Österreich', 2, 0, false);
        $abroad = $this->calculator->dailyAllowancesCents('Deutschland', 2, 0, false);

        $this->assertSame(900, $domestic['night_cents']);
        $this->assertSame(1170, $abroad['night_cents']);
    }

    public function test_business_share_rounds_below_ten_to_zero_and_from_ninety_to_hundred(): void
    {
        // EUE-072
        $this->assertSame(0, $this->calculator->businessSharePercent(5, 100));
        $this->assertSame(100, $this->calculator->businessSharePercent(90, 100));
        $this->assertSame(50, $this->calculator->businessSharePercent(50, 100));
    }

    public function test_travel_leg_uses_time_and_distance_limits(): void
    {
        // EUE-071: Hin-/Rückfahrt ab 3 Stunden und Mindestentfernung
        // (50 km Einzelzeile, 25 km verkettet). Die Bedingung war invertiert.
        $this->assertTrue($this->calculator->isTravelLeg(240, 50, false));
        $this->assertFalse($this->calculator->isTravelLeg(60, 50, false));
        $this->assertTrue($this->calculator->isTravelLeg(240, 25, true));
        $this->assertFalse($this->calculator->isTravelLeg(240, 24, true));

        // AE3: auch die Stunden des Belegs erfüllen die Zeitgrenze.
        $this->assertTrue($this->calculator->isTravelLeg(60, 50, false, 180));
    }

    public function test_km_rates_switch_at_the_2025_half_year_boundary(): void
    {
        // EUE-063/064: 30.06./01.07.2025 — 0,50 €/km vorher, 0,25 €/km danach.
        $this->assertSame(50, $this->calculator->kilometreRateCents('Motorrad', 2025, 6));
        $this->assertSame(25, $this->calculator->kilometreRateCents('Motorrad', 2025, 7));
        $this->assertSame(50, $this->calculator->kilometreRateCents('Fahrrad', 2025, 6));
        $this->assertSame(25, $this->calculator->kilometreRateCents('Fahrrad', 2025, 7));

        // Das API übermittelt keinen Monat: der Wert bleibt lesbar (kein HTTP 500).
        $this->assertSame(25, $this->calculator->kilometreRateCents('Motorrad', 2025));
        $this->assertSame(25, $this->calculator->kilometreRateCents('Fahrrad', 2025));
        $this->assertSame(2500, $this->calculator->kilometreAllowanceCents('Motorrad', 100, 0, 2025));
    }

    public function test_km_rates_for_2024_and_2026_remain_stable(): void
    {
        // EUE-062/063/064
        $this->assertSame(42, $this->calculator->kilometreRateCents('PKW', 2024));
        $this->assertSame(24, $this->calculator->kilometreRateCents('Motorrad', 2024));
        $this->assertSame(38, $this->calculator->kilometreRateCents('Fahrrad', 2024));
        $this->assertSame(50, $this->calculator->kilometreRateCents('PKW', 2026));
        $this->assertSame(25, $this->calculator->kilometreRateCents('Motorrad', 2026));
        $this->assertSame(25, $this->calculator->kilometreRateCents('Fahrrad', 2026));
    }

    public function test_business_travel_costs_apply_the_business_share(): void
    {
        // EUE-081: 100 km × 50 Cent + 2 × 3000 Cent Tagesgeld + 2 × 1700 Cent
        // Nächtigung = 14400 Cent; 50 % = 7200 Cent.
        $costs = $this->calculator->businessTravelCostsCents(
            'PKW', 100, 0, 'Österreich', 2, 0, true, 50,
        );

        $this->assertSame(7200, $costs);
    }

    public function test_validation_reports_missing_receipt_and_reversed_period(): void
    {
        // EUE-075 … EUE-079
        $warnings = $this->calculator->validateTrip([
            'year' => 2026,
            'start' => '2026-05-10T08:00',
            'end' => '2026-05-09T18:00',
            'receipt_no' => '',
            'country' => 'Österreich',
            'vehicle' => 'PKW',
        ]);

        $this->assertContains('Das Reiseende muss nach dem Reisestart liegen.', $warnings);
        $this->assertContains('Belegnummer fehlt. Bitte eine Belegnummer vergeben.', $warnings);
    }
}
