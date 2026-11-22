<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Domain\E1Statement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** EÜR · E1A/E1B-Aggregation (EUE-095, EUE-099, EUE-100). */
class E1StatementTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return [
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 100000],
            ['kind' => 'expense', 'code' => '9100', 'amount_cents' => 20000],
            ['kind' => 'income', 'code' => 'E1B 9460', 'amount_cents' => 50000],
            ['kind' => 'expense', 'code' => 'E1B 9530', 'amount_cents' => 10000],
        ];
    }

    /** EUE-099/100 */
    public function test_e1a_and_e1b_totals_are_split_by_code(): void
    {
        $result = E1Statement::fromEntries($this->entries());

        $this->assertSame(['9040' => 100000, '9100' => 20000], $result['e1a']);
        $this->assertSame(['9460' => 50000, '9530' => 10000], $result['e1b']);
        $this->assertSame(120000, $result['totals_cents'][E1Statement::GROUP_E1A]);
        $this->assertSame(60000, $result['totals_cents'][E1Statement::GROUP_E1B]);
        $this->assertSame(100000, $result['income_cents'][E1Statement::GROUP_E1A]);
        $this->assertSame(20000, $result['expense_cents'][E1Statement::GROUP_E1A]);
        $this->assertTrue($result['complete'][E1Statement::GROUP_E1A]);
        $this->assertTrue($result['complete'][E1Statement::GROUP_E1B]);
    }

    /** EUE-034/099 — Mieteinnahmen wandern in die E1B, nie in die E1A. */
    public function test_rental_is_routed_to_e1b(): void
    {
        $result = E1Statement::fromEntries([
            ['kind' => 'income', 'code' => 'e1b 9460', 'amount_cents' => 50000],
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 10000],
        ]);

        $this->assertSame(['9460' => 50000], $result['e1b']);
        $this->assertSame(['9040' => 10000], $result['e1a']);
        $this->assertSame(80000, E1Statement::e1aProfitCents([
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 100000],
            ['kind' => 'expense', 'code' => '9100', 'amount_cents' => 20000],
            ['kind' => 'income', 'code' => 'E1B 9460', 'amount_cents' => 50000],
        ]));
    }

    /** EUE-032/041 — fehlende Kennzahlen nutzen 9040/9230, Privatanteile entfallen. */
    public function test_fallback_codes_and_business_share(): void
    {
        $result = E1Statement::fromEntries([
            ['kind' => 'income', 'amount_cents' => 100000],
            ['kind' => 'expense', 'amount_cents' => 1000, 'business_share' => 50],
            ['kind' => 'expense', 'amount_cents' => 1000, 'business_share' => 0],
        ]);

        $this->assertSame(['9040' => 100000, '9230' => 500], $result['e1a']);
        $this->assertSame(500, $result['e1a']['9230']);
        $this->assertSame(100500, $result['totals_cents'][E1Statement::GROUP_E1A]);
        $this->assertSame(0, $result['totals_cents'][E1Statement::GROUP_E1B]);
    }

    /** EUE-001 — ein unbekanntes Jahr wird abgewiesen, nicht gemappt. */
    public function test_unknown_year_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        E1Statement::fromEntries([], 2027);
    }

    /** Keine Float-Geldwerte im Rechenpfad. */
    public function test_all_totals_are_integer_cents(): void
    {
        $result = E1Statement::fromEntries($this->entries());

        foreach ($result['totals_cents'] as $cents) {
            $this->assertIsInt($cents);
        }

        foreach ([...$result['e1a'], ...$result['e1b']] as $cents) {
            $this->assertIsInt($cents);
        }
    }
}
