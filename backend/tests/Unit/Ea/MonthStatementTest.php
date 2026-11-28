<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Ea\Domain\MonthStatement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** EAR · Monatsansicht (EUE-116, EUE-117, EUE-118, EUE-124, EUE-129). */
class MonthStatementTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return [
            ['kind' => 'income', 'code' => '9040', 'description' => 'Honorar', 'day' => 5, 'amount_cents' => 200000, 'month' => 5],
            ['kind' => 'expense', 'code' => '9100', 'description' => 'Ware A', 'day' => 6, 'amount_cents' => 1000, 'month' => 5],
            ['kind' => 'income', 'code' => 'E1B 9460', 'description' => 'Miete', 'day' => 7, 'amount_cents' => 70000, 'month' => 5],
        ];
    }

    /** EUE-116/118 — Zeilen E/A/WEB/FR und die fünf Summen. */
    public function test_lines_and_totals(): void
    {
        $view = MonthStatement::build(
            2026,
            5,
            $this->entries(),
            [['description' => 'Ware A', 'amount_cents' => 1000]],
            [['project' => 'P1', 'hours_minutes' => 180, 'month' => 5]],
        );

        $this->assertSame(['E', 'E', 'A', 'WEB', 'FR'], array_column($view['lines'], 'type'));
        $this->assertSame(200000, $view['totals']['income_cents']);
        $this->assertSame(1000, $view['totals']['expense_cents']);
        $this->assertSame(1000, $view['totals']['warehouse_cents']);
        $this->assertSame(180, $view['totals']['hours_minutes']);
        $this->assertIsInt($view['totals']['vat_cents']);
        // EUE-124: alle acht USt-Kennzahlen werden durchgereicht, nicht nur die Zahllast.
        $this->assertSame(
            ['000', '016', '057', '070', '072', '073', '008', 125],
            array_keys($view['vat']['kennzahlen']),
        );
        $this->assertArrayHasKey('zahllast_cents', $view['vat']);
        $this->assertSame(
            '💡 Rechenaufwendige Kalkulation: Bitte nach Verwendung wieder auf OFF setzten!',
            $view['hint'],
        );
    }

    /** EUE-129 — alle vier Vollständigkeitsprüfungen sind grün. */
    public function test_reconciliation_checks(): void
    {
        $view = MonthStatement::build(
            2026,
            5,
            $this->entries(),
            [['description' => 'Ware A', 'amount_cents' => 1000]],
            [],
        );

        $this->assertSame(['BE-ART', 'BA-ART', 'ZA', 'E1A-E1B'], array_column($view['checks'], 'code'));

        foreach ($view['checks'] as $check) {
            $this->assertTrue($check['ok'], $check['code'].': '.$check['message']);
            $this->assertStringContainsString('💡', $check['hint']);
        }
    }

    /** EUE-014 … EUE-022 und EUE-094/098 — SV-Werte und ESt-Schätzung. */
    public function test_social_insurance_and_income_tax_estimate(): void
    {
        $view = MonthStatement::build(2026, 5, [
            ['kind' => 'income', 'code' => '9040', 'description' => 'Honorar', 'amount_cents' => 2000000, 'month' => 5],
        ]);

        $this->assertSame(808500, $view['social_insurance']['amounts_cents']['max_bgl']);
        $this->assertSame(680, $view['social_insurance']['rates_per_10000']['kv_satz']);
        $this->assertSame(2000000, $view['income_tax']['profit_cents']);
        $this->assertSame(300000, $view['income_tax']['basic_allowance_cents']);
        // (2.000.000 − 300.000 − 1.353.900) × 20 % = 69.220 ct.
        $this->assertSame(69220, $view['income_tax']['tax_cents']);
    }

    /** EUE-124 — Berichts- und Signatur-Platzhalter. */
    public function test_report_and_signature_placeholders(): void
    {
        $view = MonthStatement::build(2026, 5, [], [], [], [
            'report_status' => 'closed',
            'closed_at' => '2026-05-31T10:00:00+02:00',
            'signature_status' => 'signed',
            'signature_provider' => 'Trust/Test',
        ]);

        $this->assertSame(['status' => 'closed', 'closed_at' => '2026-05-31T10:00:00+02:00'], $view['report']);
        $this->assertSame([
            'status' => 'signed',
            'provider' => 'Trust/Test',
            'is_test' => true,
        ], $view['signature']);
    }

    /** EUE-030 — der 50-%-Betriebsanteil landet als ganze Cent-Summe. */
    public function test_five_percent_business_share_is_applied(): void
    {
        $view = MonthStatement::build(2026, 5, [
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 1000, 'business_share' => 50, 'month' => 5],
        ]);

        $this->assertSame(500, $view['totals']['income_cents']);
        $this->assertSame(500, $view['lines'][0]['business_amount_cents']);
    }

    /** EUE-001 — ein Jahr unterhalb der Regeljahre und ein ungültiger Monat werden abgewiesen. */
    public function test_a_year_before_the_first_rule_year_and_an_invalid_month_are_rejected(): void
    {
        try {
            MonthStatement::build(2023, 5, []);
            $this->fail('Ein Jahr ohne Regelbasis wurde nicht abgewiesen.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        MonthStatement::build(2026, 13, []);
    }

    /** Keine Float-Geldwerte oder -Minuten im Monatsblatt. */
    public function test_no_floats_in_view(): void
    {
        $view = MonthStatement::build(2026, 5, $this->entries());

        foreach ($view['totals'] as $value) {
            $this->assertIsInt($value);
        }

        foreach ($view['income_tax'] as $value) {
            $this->assertIsInt($value);
        }
    }
}
