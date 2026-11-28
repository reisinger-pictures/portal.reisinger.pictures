<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Ea\Domain\HoursLedger;
use PHPUnit\Framework\TestCase;

/** EAR · Stundenbuch und 3-Stunden-Regel (EUE-071, EUE-080, EUE-123). */
class HoursLedgerTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return [
            ['project' => 'P1', 'month' => '2026-05', 'hours_minutes' => 90],
            ['project' => 'P1', 'month' => '2026-05', 'hours_minutes' => 90],
            ['project' => 'P2', 'month' => '2026-06', 'minutes' => 45],
        ];
    }

    /** EUE-080 */
    public function test_sum_minutes_with_filters(): void
    {
        $entries = $this->entries();

        $this->assertSame(225, HoursLedger::sumMinutes($entries));
        $this->assertSame(180, HoursLedger::sumMinutes($entries, 'P1'));
        $this->assertSame(45, HoursLedger::sumMinutes($entries, null, '2026-06'));
    }

    /** EUE-071/080 — 179 nein, 180 ja, 181 ja. */
    public function test_three_hour_rule_boundary(): void
    {
        $below = HoursLedger::threeHourCheck(179);
        $at = HoursLedger::threeHourCheck(180);
        $above = HoursLedger::threeHourCheck(181);

        $this->assertFalse($below['flag']);
        $this->assertTrue($below['ok']);
        $this->assertTrue($at['flag']);
        $this->assertFalse($at['ok']);
        $this->assertStringContainsString('⚠', $at['message']);
        $this->assertStringContainsString('💡', $at['hint']);
        $this->assertTrue($above['flag']);
        $this->assertSame(180, HoursLedger::THREE_HOURS_MINUTES);
    }

    /** EUE-123 */
    public function test_project_and_month_totals_and_hints(): void
    {
        $summary = HoursLedger::summarize($this->entries());

        $this->assertSame(['P1' => 180, 'P2' => 45], $summary['by_project']);
        $this->assertSame(['2026-05' => 180, '2026-06' => 45], $summary['by_month']);
        $this->assertSame(225, $summary['total_minutes']);
        $this->assertTrue($summary['over_three_hours']);
        $this->assertCount(2, $summary['checks']);
        $this->assertTrue($summary['checks'][0]['flag']);
        $this->assertFalse($summary['checks'][1]['flag']);
    }

    /** Keine Float-Zeiten im Rechenpfad. */
    public function test_all_minutes_are_integers(): void
    {
        $summary = HoursLedger::summarize($this->entries());

        foreach ($summary['by_project'] as $minutes) {
            $this->assertIsInt($minutes);
        }

        foreach ($summary['by_month'] as $minutes) {
            $this->assertIsInt($minutes);
        }

        $this->assertIsInt($summary['total_minutes']);
    }
}
