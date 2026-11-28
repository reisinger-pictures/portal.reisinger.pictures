<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Services\Ea\EaSvBookingReminder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(EaSvBookingReminder::class)]
class EaSvBookingReminderTest extends TestCase
{
    public function test_no_bookings_leaves_all_four_quarters_open(): void
    {
        $result = (new EaSvBookingReminder)->forYear(2026, new Collection);

        $this->assertSame(3800, $result['target_per_quarter_cents']);
        $this->assertSame([1, 2, 3, 4], $result['open_quarters']);
        $this->assertStringContainsString('Q1, Q2, Q3, Q4 2026', (string) $result['message']);
        $this->assertStringContainsString('KZ 9225', (string) $result['message']);
    }

    public function test_a_full_quarter_booking_marks_only_that_quarter_done(): void
    {
        $entries = new Collection([
            $this->entry(2, '9225', 3800),
            $this->entry(7, '9100', 90000),
        ]);

        $result = (new EaSvBookingReminder)->forYear(2026, $entries);

        $this->assertSame([2, 3, 4], $result['open_quarters']);
        $this->assertTrue($result['quarters'][0]['done']);
        $this->assertSame(3800, $result['quarters'][0]['booked_cents']);
    }

    public function test_several_bookings_in_one_quarter_add_up_in_whole_cents(): void
    {
        $entries = new Collection([
            $this->entry(1, '9225', 1500),
            $this->entry(3, '9225', 2300),
        ]);

        $result = (new EaSvBookingReminder)->forYear(2026, $entries);

        $this->assertSame([2, 3, 4], $result['open_quarters']);
        $this->assertSame(3800, $result['quarters'][0]['booked_cents']);
    }

    public function test_a_shortfall_stays_open_and_a_9225_prefix_counts(): void
    {
        $entries = new Collection([
            $this->entry(4, '9225', 3700),
            $this->entry(5, '9225_extra', 100),
        ]);

        $result = (new EaSvBookingReminder)->forYear(2026, $entries);

        $this->assertSame(3800, $result['quarters'][1]['booked_cents']);
        $this->assertSame([1, 3, 4], $result['open_quarters']);
    }

    public function test_quarter_end_check_only_runs_in_quarter_months(): void
    {
        $reminder = new EaSvBookingReminder;

        $this->assertNull($reminder->quarterEndCheck(2026, 4, 0));
        $this->assertNull($reminder->quarterEndCheck(2026, 11, 3800));

        $missing = $reminder->quarterEndCheck(2026, 3, 0);
        $this->assertSame('SV-9225', $missing['code']);
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('⚠', $missing['message']);
        $this->assertStringContainsString('💡', $missing['hint']);

        $done = $reminder->quarterEndCheck(2026, 12, 3800);
        $this->assertTrue($done['ok']);
        $this->assertStringContainsString('SV-Buchung Q4 2026 erfasst', $done['message']);
    }

    private function entry(int $month, string $code, int $cents): object
    {
        return (object) [
            'month' => $month,
            'e1a_code' => $code,
            'business_amount_cents' => $cents,
        ];
    }
}
