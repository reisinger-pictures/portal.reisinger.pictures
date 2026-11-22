<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Domain\VatReturnService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** EUE-087 — Quartals- und Jahresgruppen aus einem bebuchten Monat. */
class VatMonthGroupsTest extends TestCase
{
    public function test_only_the_selected_quarter_and_the_year_are_filled(): void
    {
        $result = [
            '000' => 10000,
            '016' => 10000,
            '057' => null,
            '070' => null,
            '072' => null,
            '073' => null,
            '008' => null,
            '125' => null,
            'zahllast' => null,
        ];

        $groups = VatReturnService::groupMonth(5, $result);

        $this->assertNull($groups['quarters'][1]['000']);
        $this->assertSame(10000, $groups['quarters'][2]['000']);
        $this->assertNull($groups['quarters'][3]['000']);
        $this->assertNull($groups['quarters'][4]['000']);
        $this->assertSame(10000, $groups['year_totals']['000']);
        $this->assertNull($groups['year_totals']['zahllast']);
    }

    public function test_invalid_month_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VatReturnService::groupMonth(13, []);
    }
}
