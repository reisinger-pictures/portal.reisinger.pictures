<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Domain\E1Statement;
use PHPUnit\Framework\TestCase;

/** D-91/#63: Jahresaggregation über A, WEB, AV und FR (EUE-099/100). */
class E1StatementSourcesTest extends TestCase
{
    public function test_annual_distributes_the_four_sources_by_prefix_and_keeps_residuals(): void
    {
        $result = E1Statement::annual([
            'A' => [
                ['kind' => 'income', 'code' => '9040', 'business_amount_cents' => 100000],
                ['kind' => 'income', 'code' => '9050', 'business_amount_cents' => 20000],
                ['kind' => 'income', 'code' => 'E1B 9460', 'business_amount_cents' => 50000],
                ['kind' => 'expense', 'code' => '9100', 'business_amount_cents' => 10000],
                ['kind' => 'expense', 'code' => '9100 - Waren', 'business_amount_cents' => 5000],
                ['kind' => 'expense', 'code' => '9160', 'business_amount_cents' => 2000],
                ['kind' => 'expense', 'code' => '9230', 'business_amount_cents' => 3000],
                ['kind' => 'expense', 'code' => 'E1B 9530', 'business_amount_cents' => 7000],
            ],
            'WEB' => [
                ['kind' => 'expense', 'code' => '9100 - Waren', 'business_amount_cents' => 4000],
            ],
            'AV' => [
                ['kind' => 'expense', 'code' => '9130', 'business_amount_cents' => 6000],
                ['kind' => 'expense', 'code' => '9134', 'business_amount_cents' => 1500],
                ['kind' => 'expense', 'code' => 'E1B 9500', 'business_amount_cents' => 2500],
            ],
            'FR' => [
                ['kind' => 'expense', 'code' => '9160', 'business_amount_cents' => 3000],
            ],
        ], 2026);

        $this->assertSame(100000, $result['income']['9040']);
        $this->assertSame(20000, $result['income']['9050']);
        $this->assertSame(0, $result['income']['9060']);
        $this->assertSame(19000, $result['expense']['9100']);
        $this->assertSame(6000, $result['expense']['9130']);
        $this->assertSame(1500, $result['expense']['9134']);
        $this->assertSame(0, $result['expense']['9135']);
        $this->assertSame(5000, $result['expense']['9160']);
        $this->assertSame(3000, $result['expense']['9230']);
        $this->assertSame(120000, $result['income_cents']);
        $this->assertSame(34500, $result['expense_cents']);
        $this->assertSame(85500, $result['profit_cents']);

        $this->assertSame(50000, $result['e1b']['9460']);
        $this->assertSame(2500, $result['e1b']['9500']);
        $this->assertSame(7000, $result['e1b']['9530']);
        $this->assertSame(0, $result['e1b']['E15']);
        $this->assertSame(40500, $result['e1b']['E18']);
    }

    public function test_annual_is_all_zero_without_bookings_and_keeps_existing_by_code_behaviour(): void
    {
        $result = E1Statement::annual([], 2026);

        $this->assertSame(0, $result['income']['9040']);
        $this->assertSame(0, $result['expense']['9230']);
        $this->assertSame(0, $result['profit_cents']);
        $this->assertSame(0, $result['e1b']['E18']);

        $entries = [
            ['kind' => 'income', 'code' => '9040', 'amount_cents' => 100],
            ['kind' => 'expense', 'code' => 'E1B 9530', 'amount_cents' => 20],
        ];

        $this->assertSame(
            ['E1A' => ['9040' => 100], 'E1B' => ['9530' => 20]],
            E1Statement::byCode($entries, 2026),
        );
    }
}
