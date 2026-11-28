<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Ea\Domain\JournalRules;
use PHPUnit\Framework\TestCase;

/** EAR · Journal-Zeilenprüfung (EUE-032 … EUE-042). */
class JournalRulesTest extends TestCase
{
    /** EUE-032/041 */
    public function test_income_and_expense_fallback_codes(): void
    {
        $this->assertSame('9040', JournalRules::incomeCode('Dienstleistung', 100, null));
        $this->assertSame('9230', JournalRules::expenseCode('Beratung', 100, null));
        $this->assertSame('9090', JournalRules::incomeCode('Förderung', 100, ' 9090 '));
        $this->assertSame('9100', JournalRules::expenseCode('Wareneinkauf', 50, '9100'));
        $this->assertSame('', JournalRules::incomeCode('Dienstleistung', 0, null));
        $this->assertSame('', JournalRules::expenseCode('', 100, null));
    }

    /** EUE-034 */
    public function test_rental_detection_requires_e1b_and_9460(): void
    {
        $this->assertTrue(JournalRules::isRental('E1B 9460'));
        $this->assertTrue(JournalRules::isRental('e1b9460'));
        $this->assertFalse(JournalRules::isRental('9460'));
        $this->assertFalse(JournalRules::isRental('9040'));
        $this->assertFalse(JournalRules::isRental('E1B 9530'));

        $checks = JournalRules::checks([
            'kind' => 'income',
            'code' => 'E1B 9460',
            'day' => 1,
            'category' => 'Vermietung Einnahmen',
            'description' => 'Miete Mai',
        ]);
        $rental = $this->checkByCode($checks, 'RENTAL');
        $this->assertTrue($rental['ok']);
        $this->assertStringContainsString('E1B 9460', $rental['message']);
    }

    /** EUE-039/040 — half-up auf ganze Cent, reine Integer-Arithmetik. */
    public function test_ig_and_reverse_charge_vat_are_rounded_half_up(): void
    {
        // 6,5 ct → 7 ct (kaufmännisch), nicht 6.
        $this->assertSame(7, JournalRules::vatCents(50, 'IG-Erwerb 13 %')['ig_vat_cents']);
        $this->assertSame(7, JournalRules::vatCents(50, 'Reverse Charge 13 %')['rc_vat_cents']);
        $this->assertSame(200, JournalRules::vatCents(1000, 'IG-Erwerb 20 %')['ig_vat_cents']);
        $this->assertSame(100, JournalRules::vatCents(1000, 'IG-Erwerb 10 %')['ig_vat_cents']);
        $this->assertSame(49, JournalRules::vatCents(1000, 'IG-Erwerb 4,9 %')['ig_vat_cents']);
        $this->assertSame(49, JournalRules::vatCents(1000, 'IG-Erwerb 4.9 %')['ig_vat_cents']);
        $this->assertSame(130, JournalRules::vatCents(1000, 'Reverse Charge 13 %')['rc_vat_cents']);
        $this->assertSame(0, JournalRules::vatCents(1000, 'IG-Erwerb 20 %')['rc_vat_cents']);
        $this->assertSame(0, JournalRules::vatCents(1000, 'Reverse Charge 20 %')['ig_vat_cents']);

        foreach (JournalRules::vatCents(1021, 'IG-Erwerb 4,9 %') as $cents) {
            $this->assertIsInt($cents);
        }
    }

    /** EUE-030 — Betriebsanteile nur in 5-%-Schritten. */
    public function test_business_share_five_percent_boundaries(): void
    {
        foreach ([0, 5, 50, 95, 100] as $percent) {
            $this->assertTrue(JournalRules::validBusinessSharePercent($percent));
        }

        foreach ([-5, 1, 6, 99, 101] as $percent) {
            $this->assertFalse(JournalRules::validBusinessSharePercent($percent));
        }

        $checks = JournalRules::checks([
            'kind' => 'expense',
            'business_share' => 6,
            'code' => '9100',
            'day' => 1,
            'description' => 'Ware',
        ]);
        $share = $this->checkByCode($checks, 'SHARE');
        $this->assertFalse($share['ok']);
        $this->assertStringContainsString('⚠', $share['message']);
        $this->assertStringContainsString('💡', $share['hint']);
    }

    /** EUE-036 — fehlende Pflichtfelder sind Hinweise mit Lösungsweg. */
    public function test_missing_day_category_and_description_produce_hints(): void
    {
        $checks = JournalRules::checks(['kind' => 'expense', 'amount_cents' => 1000]);

        foreach (['DAY', 'CATEGORY', 'DESCRIPTION', 'MONTH', 'RECEIPT'] as $code) {
            $check = $this->checkByCode($checks, $code);
            $this->assertFalse($check['ok'], $code.' darf bei fehlendem Pflichtfeld nicht ok sein.');
            $this->assertStringContainsString('⚠', $check['message']);
            $this->assertStringContainsString('💡', $check['hint']);
        }

        $code = $this->checkByCode($checks, 'CODE');
        $this->assertTrue($code['ok']);
        $this->assertStringContainsString('9230', $code['message']);
    }

    /** EUE-036/037/043 — gültige Zeile bleibt ok, 9100 verlangt WEB. */
    public function test_complete_entry_has_no_failed_checks_and_kz_9100_requires_web(): void
    {
        $complete = JournalRules::checks([
            'kind' => 'expense', 'day' => 5, 'month' => 5, 'category' => 'Büro',
            'description' => 'Bürobedarf', 'receipt_no' => 'R-1', 'business_share' => 100,
            'amount_cents' => 1000,
        ]);

        foreach ($complete as $check) {
            $this->assertTrue($check['ok'], $check['code'].': '.$check['message']);
        }

        $withWeb = JournalRules::checks([
            'kind' => 'expense', 'day' => 5, 'month' => 5, 'category' => 'Ware',
            'description' => 'Wareneingang', 'receipt_no' => 'R-2', 'business_share' => 100,
            'amount_cents' => 1000, 'e1a_code' => '9100',
        ]);

        $web = $this->checkByCode($withWeb, 'WEB');
        $this->assertFalse($web['ok']);
        $this->assertStringContainsString('Lieferschein', $web['message']);
    }

    /** EUE-037/042 — Tagesbereich, USt-Arten, Monatsnamen, Kopfhinweis. */
    public function test_day_range_vat_kind_months_and_header_hint(): void
    {
        $this->assertTrue(JournalRules::validDay(1));
        $this->assertTrue(JournalRules::validDay(31));
        $this->assertFalse(JournalRules::validDay(0));
        $this->assertFalse(JournalRules::validDay(32));
        $this->assertFalse(JournalRules::validDay('5'));

        $this->assertTrue(JournalRules::validVatType('IG-Erwerb 4,9 %'));
        $this->assertTrue(JournalRules::validVatType('IG-Erwerb 4.9 %'));
        $this->assertTrue(JournalRules::validVatType('Reverse Charge 10 %'));
        $this->assertFalse(JournalRules::validVatType('Reverse Charge 4,9 %'));
        $this->assertFalse(JournalRules::validVatType('Unbekannt'));

        $checks = JournalRules::checks([
            'kind' => 'expense',
            'day' => 5,
            'category' => 'Software',
            'description' => 'Abo',
            'vat_kind' => 'Unbekannt',
        ]);
        $vat = $this->checkByCode($checks, 'VAT_KIND');
        $this->assertFalse($vat['ok']);
        $this->assertStringContainsString('⚠', $vat['message']);

        $this->assertCount(12, JournalRules::MONTHS);
        $this->assertSame('Jänner', JournalRules::MONTHS[0]);
        $this->assertSame('Dezember', JournalRules::MONTHS[11]);
        $this->assertSame(
            '💡 Rechenaufwendige Kalkulation: Bitte nach Verwendung wieder auf OFF setzten!',
            JournalRules::headerHint(),
        );
    }

    /**
     * @param  list<array{code: string, ok: bool, message: string, hint: string}>  $checks
     * @return array{code: string, ok: bool, message: string, hint: string}
     */
    private function checkByCode(array $checks, string $code): array
    {
        foreach ($checks as $check) {
            if ($check['code'] === $code) {
                return $check;
            }
        }

        $this->fail(sprintf('Prüfung %s fehlt.', $code));
    }
}
