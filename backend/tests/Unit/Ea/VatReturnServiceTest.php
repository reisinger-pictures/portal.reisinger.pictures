<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Ea\Domain\VatReturnService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** EAR · USt-Voranmeldung aus Buchungen (EUE-082 … EUE-088). */
class VatReturnServiceTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return [
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 125000, 'month' => 5],
            ['kind' => 'expense', 'code' => '9230', 'amount_cents' => 2000, 'month' => 5, 'vat_kind' => 'Reverse Charge 20 %'],
            ['kind' => 'expense', 'code' => '9130', 'amount_cents' => 100000, 'month' => 5, 'vat_kind' => 'IG-Erwerb 20 %'],
            ['kind' => 'expense', 'code' => '9130', 'amount_cents' => 50000, 'month' => 5, 'vat_kind' => 'IG-Erwerb 10 %'],
            ['kind' => 'expense', 'code' => '9130', 'amount_cents' => 30000, 'month' => 5, 'vat_kind' => 'IG-Erwerb 13 %'],
            ['kind' => 'expense', 'code' => '9130', 'amount_cents' => 10000, 'month' => 5, 'vat_kind' => 'IG-Erwerb 4,9 %'],
        ];
    }

    /** EUE-082 … EUE-086 */
    public function test_eight_kennzahlen_and_zahllast(): void
    {
        $result = VatReturnService::forMonth($this->entries());
        $kenn = $result['kennzahlen'];

        $this->assertSame(VatReturnService::CODES, array_map('strval', array_keys($kenn)));
        $this->assertSame(125000, $kenn['000']);
        $this->assertSame(125000, $kenn['016']);
        $this->assertSame(400, $kenn['057']);
        $this->assertSame(190000, $kenn['070']);
        $this->assertSame(100000, $kenn['072']);
        $this->assertSame(50000, $kenn['073']);
        $this->assertSame(30000, $kenn['008']);
        $this->assertSame(10000, $kenn['125']);
        // 400 + 20 % von 100000 + 10 % von 50000 + 13 % von 30000 + 4,9 % von 10000.
        $this->assertSame(29790, $result['zahllast_cents']);
        $this->assertFalse($result['empty']);
    }

    /**
     * EUE-039/040 — die ODS verwendet ausdrücklich die Brutto-Basis: A!L2
     * ("Bruttobetrag, also den Betrag inkl. Steuern … direkt im Blatt UST
     * verwendet") und E1A!C8 ("USt-Bruttosystem"). Der Regelsatz-Wortlaut
     * "Nettobetrag" widerspricht seiner eigenen Formel und gilt als Tippfehler.
     */
    public function test_ig_base_is_the_gross_amount_not_a_net_amount(): void
    {
        $result = VatReturnService::forMonth([
            ['kind' => 'expense', 'code' => '9130', 'amount_cents' => 119000, 'month' => 5, 'vat_kind' => 'IG-Erwerb 20 %'],
        ]);

        $this->assertSame(119000, $result['kennzahlen']['072']);
        $this->assertSame(23800, $result['zahllast_cents']);
    }

    /** EUE-034 — Mieteinnahmen erhöhen nicht KZ 016/000. */
    public function test_rental_income_is_excluded_from_kz_016(): void
    {
        $entries = $this->entries();
        $entries[] = ['kind' => 'income', 'code' => 'E1B 9460', 'amount_cents' => 50000, 'month' => 5];

        $kenn = VatReturnService::forMonth($entries)['kennzahlen'];

        $this->assertSame(125000, $kenn['016']);
        $this->assertSame(125000, $kenn['000']);
    }

    /** EUE-087 — Monate zu Quartalen und Jahr, leere Gruppen bleiben leer. */
    public function test_quarterly_and_yearly_sums(): void
    {
        $entries = [
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 10000, 'month' => 1],
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 20000, 'month' => 2],
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 30000, 'month' => 3],
            ['kind' => 'expense', 'code' => '9230', 'amount_cents' => 1000, 'month' => 5, 'vat_kind' => 'Reverse Charge 20 %'],
        ];

        $result = VatReturnService::forYear(2026, $entries);

        $this->assertCount(12, $result['months']);
        $this->assertSame(60000, $result['quarters'][1]['000']);
        $this->assertSame(200, $result['quarters'][2]['057']);
        $this->assertNull($result['quarters'][3]['000']);
        $this->assertSame(60000, $result['year_totals']['000']);
        $this->assertSame(200, $result['year_totals']['057']);
    }

    /** EUE-086 — Zahllast wird gegen null abgerundet (9 ct × 10 % → 0 ct). */
    public function test_zahllast_truncates_toward_zero(): void
    {
        $nine = VatReturnService::forMonth([
            ['kind' => 'expense', 'amount_cents' => 9, 'vat_kind' => 'IG-Erwerb 10 %'],
        ]);
        $nineteen = VatReturnService::forMonth([
            ['kind' => 'expense', 'amount_cents' => 19, 'vat_kind' => 'IG-Erwerb 10 %'],
        ]);

        $this->assertSame(9, $nine['kennzahlen']['070']);
        $this->assertSame(0, $nine['zahllast_cents']);
        $this->assertSame(19, $nineteen['kennzahlen']['070']);
        $this->assertSame(1, $nineteen['zahllast_cents']);
    }

    /** EUE-001 — unterhalb des ältesten Regeljahres wird abgewiesen. */
    public function test_a_year_before_the_first_rule_year_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VatReturnService::forYear(2023, []);
    }

    /** EUE-087 und keine Float-Geldwerte. */
    public function test_empty_month_stays_blank_and_has_no_floats(): void
    {
        $result = VatReturnService::forMonth([]);

        $this->assertTrue($result['empty']);
        $this->assertNull($result['zahllast_cents']);

        foreach ($result['kennzahlen'] as $cents) {
            $this->assertNull($cents);
        }

        $filled = VatReturnService::forMonth($this->entries());

        foreach ([...$filled['kennzahlen'], $filled['zahllast_cents']] as $cents) {
            $this->assertIsInt($cents);
        }
    }
}
