<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Domain\E1Statement;
use App\Eur\Domain\MonthStatement;
use PHPUnit\Framework\TestCase;

/** EUE-099/100/116 — Per-KZ-Werte erreichen die Monatsansicht. */
class MonthStatementE1ByCodeTest extends TestCase
{
    public function test_e1_by_code_is_additive_to_the_existing_statement(): void
    {
        $view = MonthStatement::build(2026, 5, [
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 100000, 'month' => 5],
            ['kind' => 'expense', 'code' => '9100', 'amount_cents' => 30000, 'month' => 5],
            ['kind' => 'income', 'code' => 'E1B 9460', 'amount_cents' => 50000, 'month' => 5],
        ]);

        foreach (['year', 'month', 'lines', 'totals', 'checks', 'social_insurance', 'income_tax', 'report', 'signature', 'hint'] as $key) {
            $this->assertArrayHasKey($key, $view);
        }

        $this->assertSame(
            [E1Statement::GROUP_E1A => ['9040' => 100000, '9100' => 30000], E1Statement::GROUP_E1B => ['9460' => 50000]],
            $view['e1_by_code'],
        );
    }

    public function test_e1_by_code_contains_only_the_selected_month(): void
    {
        $view = MonthStatement::build(2026, 6, [
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 100000, 'month' => 5],
            ['kind' => 'income', 'code' => '9050', 'amount_cents' => 70000, 'month' => 6],
        ]);

        $this->assertSame(['9050' => 70000], $view['e1_by_code'][E1Statement::GROUP_E1A]);
    }
}
