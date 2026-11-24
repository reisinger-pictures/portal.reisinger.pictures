<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Domain\IncomeTaxService;
use App\Eur\Reference\IncomeTaxTariff;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * EAR · EUE-095/103/126 — Cent-Vektoren aus dem gepinnten Tarif und der
 * vom Eigentümer freigegebenen Integrationsnotiz (D-88).
 *
 * Der Vektor „Gesamt 2 905 000 ct" wurde vor der Assertion gegen die
 * gepinnten Tarifgrenzen/-sätze nachgerechnet: U23 380 800, U27 129 220,
 * U25 28 000, U24 223 580, U29 230 800 ct.
 */
class IncomeTaxServiceTest extends TestCase
{
    private IncomeTaxService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IncomeTaxService(IncomeTaxTariff::forYear(2026));
    }

    public function test_tariff_vector_2026_matches_the_pinned_stage_amounts(): void
    {
        // 12 000 000 ct: die fünf Stufen sind voll ausgeschöpft.
        $this->assertSame(169060, $this->stageShare(12000000, 0));
        $this->assertSame(433980, $this->stageShare(12000000, 1));
        $this->assertSame(1356280, $this->stageShare(12000000, 2));
        $this->assertSame(1655712, $this->stageShare(12000000, 3));
        $this->assertSame(757050, $this->stageShare(12000000, 4));
        $this->assertSame(4372082, $this->service->progressiveTaxCents(12000000));
    }

    public function test_total_income_uses_the_mode_specific_components(): void
    {
        // U10 765 000 (900 000 Gewinn − 135 000 GFB), U11 2 000 000,
        // U12 140 000 (E1B), U13 0.
        $this->assertSame(2905000, $this->service->totalIncomeCents('total', 765000, 2000000, 140000, 0));
        $this->assertSame(2765000, $this->service->totalIncomeCents('business', 765000, 2000000, 140000, 0));
        $this->assertSame(2140000, $this->service->totalIncomeCents('rental', 765000, 2000000, 140000, 0));
        $this->assertSame(2490000, $this->service->totalIncomeCents('Nur Vermietung', 0, 2000000, 140000, 350000));
    }

    public function test_allocation_matches_the_owner_approved_cent_vector(): void
    {
        $result = $this->service->allocate('total', 2905000, 2000000, 140000, 100000, 50000);

        $this->assertSame(2905000, $result['total_income_cents']);
        $this->assertSame(380800, $result['tax_cents']);          // U23 = ue_est
        $this->assertSame(129220, $result['employment_tax_cents']); // U27
        $this->assertSame(28000, $result['rental_tax_cents']);      // U25
        $this->assertSame(223580, $result['business_tax_cents']);   // U24
        $this->assertSame(230800, $result['due_cents']);            // U29
        // EUE-103: U29 = ue_est − U26 − U28; beide Abzüge bleiben in der Antwort.
        $this->assertSame(100000, $result['prepayment_cents']);     // U26
        $this->assertSame(50000, $result['wage_tax_cents']);        // U28 / KZ 260
        $this->assertSame(380800 - 100000 - 50000, $result['due_cents']);
        $this->assertSame(129220 + 28000 + 223580, $result['tax_cents']); // U23 = U27 + U25 + U24
    }

    public function test_zero_prepayment_keeps_the_assessed_tax_and_gross_wage_hides_u24(): void
    {
        $plain = $this->service->allocate('total', 2905000, 2000000, 140000, 0, 0);
        $this->assertSame(380800, $plain['due_cents']);

        $grossWage = $this->service->allocate('Nur Bruttolohn', 2905000, 2000000, 140000, 0, 0);
        $this->assertNull($grossWage['business_tax_cents']);

        // EUE-105: der Portal-Alias wird auf denselben Modus abgebildet.
        $grossWageAlias = $this->service->allocate('gross_wage', 2905000, 2000000, 140000, 0, 0);
        $this->assertNull($grossWageAlias['business_tax_cents']);
        $this->assertSame('Nur Bruttolohn', $this->service->normaliseMode('gross_wage'));
        $this->assertSame('Nur andere Gewinne', $this->service->normaliseMode('other_profits'));
    }

    public function test_monthly_results_allocate_the_annual_burden_in_whole_cents(): void
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = ['gross_profit_cents' => 100000, 'hours_minutes' => 600];
        }

        $result = $this->service->monthlyResults(2026, 'total', [
            'tax_cents' => 380800,
            'employment_tax_cents' => 129220,
            'business_tax_cents' => 223580,
            'rental_tax_cents' => 28000,
            'sv_cents' => 192960, // 12 × I12, der gepinnte Mindest-SV-Fall
            'minimum_sv_cents' => 16080,
        ], $months);

        $this->assertSame(16080, $result['annual']['minimum_sv_cents']);
        // I14 = ABS(MIN(ue_gewinn_pro_monat)) = 100 000 ct (gepinnte Formel).
        $this->assertSame(100000, $result['annual']['loss_basis_cents']);
        // I16 = Σ(Gewinn + I14) = 12 × 200 000 ct.
        $this->assertSame(2400000, $result['annual']['allocation_basis_cents']);
        $this->assertSame(251580, $result['annual']['distributable_cents']);     // I13
        $this->assertSame(444540, $result['annual']['allocated_cents']);

        foreach ($result['months'] as $index => $row) {
            $this->assertSame($index + 1, $row['month']);
            $this->assertSame(100000, $row['gross_profit_cents']);
            $this->assertSame(37045, $row['est_sv_cents']);       // J12
            $this->assertSame(62955, $row['net_profit_cents']);   // K12
            $this->assertSame(6296, $row['net_per_hour_cents']);  // N5
            $this->assertSame(2400000, $row['basis_cents']);      // I16
        }
    }

    public function test_months_without_data_or_hours_are_null(): void
    {
        $result = $this->service->monthlyResults(2026, 'total', [
            'tax_cents' => 380800,
            'employment_tax_cents' => 129220,
            'business_tax_cents' => 223580,
            'rental_tax_cents' => 28000,
            'sv_cents' => 192960,
            'minimum_sv_cents' => 16080,
        ], [
            1 => ['gross_profit_cents' => 100000, 'hours_minutes' => 0],
        ]);

        $this->assertCount(12, $result['months']);
        $this->assertNull($result['months'][0]['net_per_hour_cents']);
        $this->assertSame(100000, $result['months'][0]['gross_profit_cents']);
        $this->assertNull($result['months'][1]['gross_profit_cents']);
        $this->assertNull($result['months'][1]['est_sv_cents']);
        $this->assertNull($result['months'][1]['net_profit_cents']);
        $this->assertNull($result['months'][1]['basis_cents']);
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->normaliseMode('unknown');
    }

    /** EUE-094: Anteil einer einzelnen Tarifstufe bei gegebenem Einkommen. */
    private function stageShare(int $incomeCents, int $stage): int
    {
        $limits = [1353900, 2199200, 3645800, 7036500, 10485900, PHP_INT_MAX];
        $rates = [2000, 3000, 4000, 4800, 5000];
        $lower = $limits[$stage];
        $upper = $limits[$stage + 1] ?? null;
        $portion = ($incomeCents - $lower) - ($upper === null ? 0 : max($incomeCents - $upper, 0));

        return (int) bcmul((string) max(0, $portion), bcdiv((string) $rates[$stage], '10000', 8), 0);
    }
}
