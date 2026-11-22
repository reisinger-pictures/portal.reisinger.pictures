<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Domain\E1Statement;
use App\Services\Eur\EurTaxStatementService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * EAR · Ergänzende Steuerwerte (EUE-095, EUE-101 … EUE-105).
 *
 * Prüft, dass die Per-KZ-Arrays aus E1Statement kommen, die Pauschalen aus
 * den gepinnten Regelparametern klassifiziert werden und der Modus die
 * Sichtbarkeit steuert — ohne neue Regelquelle und ohne Float.
 */
class EurTaxStatementServiceTest extends TestCase
{
    private EurTaxStatementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EurTaxStatementService;
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return [
            ['direction' => 'income', 'code' => '9040', 'amount_cents' => 200000],
            ['direction' => 'expense', 'code' => '9130', 'amount_cents' => 20000],
            ['direction' => 'income', 'code' => 'E1B 9460', 'amount_cents' => 50000],
        ];
    }

    public function test_by_code_exposes_the_per_code_arrays_of_both_groups(): void
    {
        $byCode = E1Statement::byCode($this->entries(), 2026);

        $this->assertSame(['9040' => 200000, '9130' => 20000], $byCode[E1Statement::GROUP_E1A]);
        $this->assertSame(['9460' => 50000], $byCode[E1Statement::GROUP_E1B]);
    }

    public function test_kz_map_and_steuerbilanz_are_derived_from_pinned_values(): void
    {
        $result = $this->service->build(2026, 'total', $this->entries(), [
            'employment_income_cents' => 30000,
            'wage_tax_cents' => 4000,
            'prepayment_cents' => 5000,
            'rental_forecast_cents' => 7000,
            'arbeitsplatzpauschale_cents' => 20000,
            'ergonomisches_mobiliar_cents' => 10000,
        ], 30000);

        $this->assertSame([
            '245' => 30000,
            '260' => 4000,
            'U13' => 7000,
            'U26' => 5000,
            'E40' => 20000,
            'E39' => 10000,
        ], $result['kz']);

        $this->assertSame(200000, $result['steuerbilanz']['betriebliche_einnahmen_cents']);
        $this->assertSame(20000, $result['steuerbilanz']['betriebliche_ausgaben_cents']);
        $this->assertSame(180000, $result['steuerbilanz']['gewinn_cents']);
        // 2.000,00 € Gewinn − 300,00 € Grundfreibetrag.
        $this->assertSame(150000, $result['steuerbilanz']['steuerlicher_gewinn_cents']);
        $this->assertSame(20000, $result['steuerbilanz']['afa_cents']);
        $this->assertSame(['9040' => 200000, '9130' => 20000], $result['e1a_e1b'][E1Statement::GROUP_E1A]);
    }

    public function test_pauschalen_classification_uses_eue_101_and_102_parameters(): void
    {
        $small = $this->service->build(2026, 'total', [], [
            'arbeitsplatzpauschale_cents' => 30000,
            'ergonomisches_mobiliar_cents' => 30000,
        ], 0);
        $this->assertSame('9215', $small['steuerbilanz']['pauschalen']['e40_code']);
        $this->assertSame('9216', $small['steuerbilanz']['pauschalen']['e39_code']);
        $this->assertNotNull($small['steuerbilanz']['pauschalen']['e40_label']);

        $large = $this->service->build(2026, 'total', [], ['arbeitsplatzpauschale_cents' => 120000], 0);
        $this->assertSame('9217', $large['steuerbilanz']['pauschalen']['e40_code']);

        $suppressed = $this->service->build(2026, 'total', [], [
            'arbeitsplatzpauschale_cents' => 30000,
            'ergonomisches_mobiliar_cents' => 10000,
            'arbeitszimmer_cents' => 5000,
        ], 0);
        $this->assertNull($suppressed['steuerbilanz']['pauschalen']['e40_code']);
        $this->assertNull($suppressed['steuerbilanz']['pauschalen']['e39_code']);
    }

    public function test_mode_controls_visibility_of_business_and_rental_values(): void
    {
        $input = [
            'rental_forecast_cents' => 7000,
            'arbeitsplatzpauschale_cents' => 20000,
            'ergonomisches_mobiliar_cents' => 10000,
        ];

        $business = $this->service->build(2026, 'business', $this->entries(), $input, 0);
        $this->assertSame(0, $business['kz']['U13']);
        $this->assertSame(20000, $business['kz']['E40']);

        $rental = $this->service->build(2026, 'rental', $this->entries(), $input, 0);
        $this->assertSame(7000, $rental['kz']['U13']);
        $this->assertSame(0, $rental['kz']['E40']);
        $this->assertSame(0, $rental['steuerbilanz']['betriebliche_einnahmen_cents']);
        $this->assertSame(0, $rental['steuerbilanz']['afa_cents']);

        $forecast = $this->service->build(2026, 'forecast', $this->entries(), [], 0);
        // Ohne expliziten Wert wird U13 aus den E1B-Zeilen abgeleitet.
        $this->assertSame(50000, $forecast['kz']['U13']);

        $withCosts = $this->service->build(2026, 'forecast', [
            ['direction' => 'income', 'code' => 'E1B 9460', 'amount_cents' => 50000],
            ['direction' => 'expense', 'code' => 'E1B 9530', 'amount_cents' => 20000],
        ], [], 0);
        // EUE-104: Einnahmen minus Werbungskosten, nicht deren Summe.
        $this->assertSame(30000, $withCosts['kz']['U13']);
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unbekannter Steuer-Modus');

        $this->service->build(2026, 'unknown', [], [], 0);
    }
}
