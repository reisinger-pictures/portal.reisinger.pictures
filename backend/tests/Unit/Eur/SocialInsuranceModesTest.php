<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Reference\ReferenceTables;
use App\Eur\Reference\SocialInsurance;
use App\Eur\Reference\YearTables;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * EAR · SV-Modi (EUE-107, EUE-108, EUE-110 … EUE-114).
 *
 * Der SV-Modus darf nie zu einer Exception im Rechenpfad führen, wenn er in
 * den Einstellungen zulässig ist. Alle Werte kommen aus dem gepinnten
 * Jahresartefakt; es werden keine Beträge im Test erfunden.
 */
class SocialInsuranceModesTest extends TestCase
{
    private function socialInsurance(int $year): SocialInsurance
    {
        // YearTables liefert Cent, die Lesegrenze SocialInsurance erwartet EUR.
        $amounts = [];

        foreach (YearTables::socialInsuranceAmountsCents($year) as $key => $cents) {
            $amounts[$key] = (float) bcdiv((string) $cents, '100', 2);
        }

        return new SocialInsurance(
            $amounts + YearTables::socialInsuranceRatesPer10000($year),
            $year,
        );
    }

    public function test_every_supported_mode_has_a_pension_value(): void
    {
        $sv = $this->socialInsurance(2026);

        $this->assertSame(10195, $sv->minimumPensionMonthlyCents('gsvg'));
        $this->assertSame(10195, $sv->minimumPensionMonthlyCents('ns_gsvg'));
        $this->assertSame(11022, $sv->minimumPensionMonthlyCents('fsvg'));
        // EUE-108: PV hat keine Jungunternehmer-Ausnahme → GSVG-Mindestbeitrag.
        $this->assertSame(10195, $sv->minimumPensionMonthlyCents(SocialInsurance::MODE_JUNGUNTERNEHMER));
        // EUE-107/111: nur UV bleibt — kein PV-Beitrag.
        $this->assertSame(0, $sv->minimumPensionMonthlyCents(SocialInsurance::MODE_UV_ONLY));
    }

    public function test_uv_only_is_the_flat_rate_only_mode(): void
    {
        $sv = $this->socialInsurance(2026);

        $this->assertTrue($sv->isFlatRateOnlyMode(SocialInsurance::MODE_UV_ONLY));
        $this->assertFalse($sv->isFlatRateOnlyMode(SocialInsurance::MODE_JUNGUNTERNEHMER));
        $this->assertFalse($sv->isFlatRateOnlyMode('gsvg'));
        $this->assertFalse($sv->isFlatRateOnlyMode('fsvg'));
        $this->assertFalse($sv->isFlatRateOnlyMode('ns_gsvg'));
    }

    public function test_uv_flat_rate_comes_from_the_pinned_year_table(): void
    {
        foreach ([2024 => 1135, 2025 => 1207, 2026 => 1295] as $year => $expected) {
            $sv = $this->socialInsurance($year);

            $this->assertSame($expected, $sv->accidentMonthlyCents());
            $this->assertSame(0, $sv->minimumPensionMonthlyCents(SocialInsurance::MODE_UV_ONLY));
        }
    }

    public function test_jungunternehmer_minimum_kv_is_still_paid_per_eue_108(): void
    {
        // 37,47 € × 12 = 449,64 € (2025/2026) — nur der KV-Satz wird als "-"
        // angezeigt; der Mindest-KV-Beitrag wird nicht erlassen.
        $this->assertSame(44964, $this->socialInsurance(2026)->minimumHealthYearlyCents());
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $sv = $this->socialInsurance(2026);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unbekannter SV-Modus');

        $sv->minimumPensionMonthlyCents('unbekannt');
    }

    public function test_missing_pinned_value_fails_closed(): void
    {
        $sv = new SocialInsurance(['kv_min' => 3747], 2026);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uv_pauschale');

        $sv->accidentMonthlyCents();
    }

    public function test_reference_tables_modes_remain_supported(): void
    {
        $sv = ReferenceTables::load()->socialInsurance();

        $this->assertSame(10195, $sv->minimumPensionMonthlyCents(SocialInsurance::MODE_JUNGUNTERNEHMER));
        $this->assertSame(0, $sv->minimumPensionMonthlyCents(SocialInsurance::MODE_UV_ONLY));
    }
}
