<?php

namespace Tests\Unit;

use App\Support\AgeHelper;
use Tests\TestCase;

class AgeHelperTest extends TestCase
{
    public function test_calculate_returns_completed_years_on_the_birthday(): void
    {
        $this->assertSame(26, AgeHelper::calculate('2000-06-15', '2026-06-15'));
    }

    public function test_calculate_returns_previous_year_one_day_before_the_birthday(): void
    {
        $this->assertSame(25, AgeHelper::calculate('2000-06-15', '2026-06-14'));
    }

    public function test_calculate_returns_null_for_a_future_birthdate(): void
    {
        $this->assertNull(AgeHelper::calculate('2026-09-13', '2026-09-12'));
    }

    public function test_calculate_returns_null_for_a_null_birthdate(): void
    {
        $this->assertNull(AgeHelper::calculate(null, '2026-09-12'));
    }

    public function test_format_includes_age_and_birthdate(): void
    {
        $this->assertSame(
            'Alter: 26 Jahre (geb. 15.06.2000)',
            AgeHelper::format('2000-06-15', '2026-06-15')
        );
    }

    public function test_format_returns_null_for_a_future_birthdate(): void
    {
        $this->assertNull(AgeHelper::format('2026-09-13', '2026-09-12'));
    }
}
