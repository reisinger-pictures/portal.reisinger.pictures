<?php

namespace Tests\Unit\Ea;

use App\Ea\Reference\Holidays;
use App\Ea\Reference\ReferenceTables;
use App\Ea\Reference\SocialInsurance;
use App\Ea\Reference\YearTables;
use App\Services\Ea\EaOverviewService;
use App\Services\SettingResolver;
use PHPUnit\Framework\TestCase;

/**
 * EAR · Referenzdaten (EUE-004 … EUE-022) aus dem gepinnten Artefakt.
 *
 * Framework-/DB-frei. Geprüft werden die kanonischen Werte je Tabelle, die
 * Auflösung nach Land und Jahr, die 5-%-Schritte der Betriebsanteile und die
 * Ablehnung eines nicht gepinnten Wirtschaftsjahres.
 */
class ReferenceTablesTest extends TestCase
{
    private ReferenceTables $tables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tables = ReferenceTables::load();
    }

    public function test_business_year_is_pinned_to_2026(): void
    {
        $this->assertSame(2026, $this->tables->businessYear());
        $this->tables->assertBusinessYear(2026);

        $this->expectException(\InvalidArgumentException::class);
        $this->tables->assertBusinessYear(2023);
    }

    public function test_kind_catalogues_have_the_pinned_size(): void
    {
        $this->assertCount(34, $this->tables->expenseKinds());
        $this->assertCount(9, $this->tables->incomeKinds());
        $this->assertCount(7, $this->tables->vatKinds());
        $this->assertCount(13, $this->tables->vehicleTypes());
        $this->assertCount(2, $this->tables->paymentMethods());
        $this->assertCount(3, $this->tables->projects());
        $this->assertCount(174, $this->tables->travel()->countries());
    }

    public function test_every_kind_share_is_a_multiple_of_five_percent(): void
    {
        foreach ([$this->tables->expenseKinds(), $this->tables->incomeKinds()] as $kinds) {
            foreach ($kinds as $kind) {
                if ($kind['share_percent'] === null) {
                    continue;
                }

                $this->assertSame(
                    0,
                    $kind['share_percent'] % 5,
                    $kind['type'].' hat keinen 5-%-Schritt.'
                );
                $this->assertGreaterThanOrEqual(0, $kind['share_percent']);
                $this->assertLessThanOrEqual(100, $kind['share_percent']);
            }
        }
    }

    public function test_fallbacks_are_named_by_the_rule_catalogue(): void
    {
        $catalog = json_decode(
            (string) file_get_contents(__DIR__.'/../../../../features/eur/cleanroom/domain-rules.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $rules = [];

        foreach ($catalog['bereiche'] as $bereich) {
            foreach ($bereich['regeln'] as $rule) {
                $rules[$rule['id']] = $rule;
            }
        }

        $this->assertStringContainsString(ReferenceTables::EXPENSE_FALLBACK_E1A, $rules['EUE-007']['wirkung']);
        $this->assertStringContainsString(ReferenceTables::INCOME_FALLBACK_E1A, $rules['EUE-008']['wirkung']);
    }

    public function test_domestic_flat_rates_are_30_and_17_euro(): void
    {
        $this->assertSame(
            ['day_cents' => 3000, 'night_cents' => 1700],
            $this->tables->domesticFlatRates()
        );
    }

    public function test_travel_allowance_resolves_by_country_and_year(): void
    {
        $travel = $this->tables->travel();

        $this->assertSame(
            ['country' => 'Österreich', 'day_cents' => 3000, 'night_cents' => 1700],
            $travel->for('Österreich', 2026)
        );

        $this->assertSame(
            ['country' => 'Afghanistan', 'day_cents' => 3180, 'night_cents' => 2770],
            $travel->for('afghanistan', 2026)
        );
    }

    public function test_travel_allowance_rejects_unknown_country_and_year(): void
    {
        $travel = $this->tables->travel();

        try {
            $travel->for('Atlantis', 2026);
            $this->fail('Unbekanntes Land wurde akzeptiert.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Atlantis', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $travel->for('Österreich', 2025);
    }

    public function test_holidays_match_the_pinned_2026_artifact(): void
    {
        $artifact = json_decode(
            (string) file_get_contents(__DIR__.'/../../../../features/eur/cleanroom/artifacts/analysis/tables.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($artifact['feiertage'], Holidays::datesForYear(2026));
        $this->assertCount(13, Holidays::datesForYear(2026));
    }

    public function test_easter_formula_matches_known_dates(): void
    {
        $this->assertSame('2024-03-31', Holidays::easterSunday(2024)->format('Y-m-d'));
        $this->assertSame('2025-04-20', Holidays::easterSunday(2025)->format('Y-m-d'));
        $this->assertSame('2026-04-05', Holidays::easterSunday(2026)->format('Y-m-d'));
    }

    public function test_income_tax_tariff_has_five_stages_and_basic_allowance(): void
    {
        $tariff = $this->tables->incomeTax();

        $this->assertCount(5, $tariff->stages());
        $this->assertSame(1353900, $tariff->taxFreeThresholdCents());
        $this->assertSame(
            ['limit_cents' => 3300000, 'rate_per_10000' => 1500, 'maximum_cents' => 495000],
            $tariff->basicAllowance()
        );
    }

    public function test_income_tax_is_zero_below_threshold_and_progressive_above(): void
    {
        $tariff = $this->tables->incomeTax();

        $this->assertSame(0, $tariff->taxCents(1353900));
        $this->assertSame(168000, $tariff->taxCents(2203900 - 10000));

        // 20 % auf den Anteil zwischen 13.539 € und 15.539 €, nichts darunter.
        $this->assertSame(40000, $tariff->taxCents(1553900));
    }

    public function test_basic_allowance_is_capped_and_never_negative(): void
    {
        $tariff = $this->tables->incomeTax();

        $this->assertSame(0, $tariff->basicAllowanceCents(-100));
        $this->assertSame(15000, $tariff->basicAllowanceCents(100000));
        $this->assertSame(495000, $tariff->basicAllowanceCents(10000000));
        $this->assertSame(74250, $tariff->basicAllowanceCents(495000));
    }

    public function test_social_insurance_values_are_integral(): void
    {
        $sv = $this->tables->socialInsurance();

        $this->assertSame(808500, $sv->maximumMonthlyBaseCents());
        $this->assertSame(9702000, $sv->maximumYearlyBaseCents());
        $this->assertSame(3747, $sv->minimumHealthMonthlyCents());
        $this->assertSame(44964, $sv->minimumHealthYearlyCents());
        $this->assertSame(10195, $sv->minimumPensionMonthlyCents('gsvg'));
        $this->assertSame(10195, $sv->minimumPensionMonthlyCents('ns_gsvg'));
        $this->assertSame(11022, $sv->minimumPensionMonthlyCents('fsvg'));
        $this->assertSame(1295, $sv->accidentMonthlyCents());

        $this->assertSame(680, $sv->healthRatePer10000());
        $this->assertSame(1850, $sv->pensionRatePer10000('gsvg'));
        $this->assertSame(2000, $sv->pensionRatePer10000('fsvg'));
        $this->assertSame(153, $sv->provisionRatePer10000());
    }

    public function test_social_insurance_rejects_unknown_mode(): void
    {
        $sv = $this->tables->socialInsurance();

        $this->expectException(\InvalidArgumentException::class);
        $sv->minimumPensionMonthlyCents('unbekannt');
    }

    private function svService(string $mode): EaOverviewService
    {
        $settings = $this->createMock(SettingResolver::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'ea_sv_mode' ? $mode : $default,
        );

        return new EaOverviewService($settings);
    }

    /** EUE-014: der Hinweis folgt dem bereits gedeckelten Beitragsgrundlagen-Wert. */
    public function test_sv_base_cap_hint_comes_from_the_capped_value(): void
    {
        $service = $this->svService('gsvg');
        $maximumYearlyBase = $this->tables->socialInsurance()->maximumYearlyBaseCents();

        $atCap = $service->svMonthly(2026, $maximumYearlyBase, 0);
        $this->assertCount(12, $atCap);
        foreach ($atCap as $row) {
            $this->assertSame(EaOverviewService::MAXIMUM_BASE_HINT, $row['base_at_cap_hint']);
        }

        $this->assertNull($service->svMonthly(2026, $maximumYearlyBase - 1, 0)[0]['base_at_cap_hint']);

        // EUE-115: bereits gebuchte 9225 zählen zur Basis — genau am Maximum.
        $this->assertSame(
            EaOverviewService::MAXIMUM_BASE_HINT,
            $service->svMonthly(2026, $maximumYearlyBase - 3800, 3800)[0]['base_at_cap_hint'],
        );
        $this->assertNull($service->svMonthly(2026, $maximumYearlyBase - 3801, 3800)[0]['base_at_cap_hint']);
        $this->assertSame('Wow! Maximale Beitragsgrundlage erreicht.', EaOverviewService::MAXIMUM_BASE_HINT);
    }

    /** EUE-131 / D-79: korrigierter FSVG-Mindestbeitrag ohne 101,95-€-Fallback. */
    public function test_fsvg_pension_minimum_has_no_10195_fallback(): void
    {
        foreach ([2024 => 10369, 2025 => 11022, 2026 => 11022] as $year => $expectedCents) {
            $amounts = YearTables::socialInsuranceAmountsCents($year);
            $this->assertSame($expectedCents, $amounts['fsvg_pv_min']);

            // YearTables liefert Cent, die Lesegrenze SocialInsurance erwartet Euro.
            $values = [];
            foreach ($amounts as $key => $cents) {
                $values[$key] = (float) bcdiv((string) $cents, '100', 2);
            }
            $sv = new SocialInsurance($values, $year);

            $this->assertSame($expectedCents, $sv->minimumPensionMonthlyCents('fsvg'));
            $this->assertNotSame(10195, $sv->minimumPensionMonthlyCents('fsvg'));
            $this->assertSame($expectedCents, $this->svService('fsvg')->svMonthly($year, 0, 0)[0]['pv_cents']);
        }

        // 101,95 € bleibt der GSVG-Wert 2025/26 — nie der FSVG-Fallback (D-79).
        foreach ([2025, 2026] as $year) {
            $values = [];
            foreach (YearTables::socialInsuranceAmountsCents($year) as $key => $cents) {
                $values[$key] = (float) bcdiv((string) $cents, '100', 2);
            }
            $sv = new SocialInsurance($values, $year);

            $this->assertSame(10195, $sv->minimumPensionMonthlyCents('gsvg'));
            $this->assertNotSame($sv->minimumPensionMonthlyCents('gsvg'), $sv->minimumPensionMonthlyCents('fsvg'));
        }
    }
}
