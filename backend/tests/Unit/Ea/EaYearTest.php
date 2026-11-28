<?php

declare(strict_types=1);

namespace Tests\Unit\Ea;

use App\Ea\Reference\EaYear;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * EAR · Wirtschaftsjahr — Regeljahre 2024–2026, Buchungsjahre ab 2026.
 *
 * Owner-Entscheidungen #75/#78: 2024 und 2025 bleiben als Regeljahre
 * eingepflegt, sind aber kein Buchungsjahr; ein Buchungsjahr ohne gepinnte
 * Basis (heute 2027) rechnet über den Fallback des letzten Regeljahres, und es
 * gibt keinen Lizenz- oder Versionshinweis mehr.
 *
 * Request-Fallbacks kommen aus {@see EaYear::currentOrLast()}; der Test nutzt
 * die Laravel-Basisklasse, weil `travelTo()` die Uhr von `now()` stellt.
 */
class EaYearTest extends TestCase
{
    public function test_pinned_and_bookable_years_are_two_ranges(): void
    {
        $this->assertSame([2024, 2025, 2026], EaYear::pinned());
        $this->assertSame([2026], EaYear::bookable());
        $this->assertSame(2026, EaYear::LAST_PINNED);
        $this->assertSame(2026, EaYear::BOOKING_FROM);
    }

    public function test_pinning_starts_at_2024_and_ends_at_2026(): void
    {
        $this->assertTrue(EaYear::isPinned(2024));
        $this->assertTrue(EaYear::isPinned(2026));
        $this->assertFalse(EaYear::isPinned(2023));
        $this->assertFalse(EaYear::isPinned(2027));
    }

    public function test_booking_is_open_from_2026_but_not_for_2024_or_2025(): void
    {
        $this->assertFalse(EaYear::accepts(2023));
        $this->assertFalse(EaYear::accepts(2024));
        $this->assertFalse(EaYear::accepts(2025));
        $this->assertTrue(EaYear::accepts(2026));
        $this->assertTrue(EaYear::accepts(2027));
    }

    public function test_effective_year_falls_back_to_the_last_pinned_year(): void
    {
        $this->assertSame(2026, EaYear::effective(2027));
        $this->assertSame(2026, EaYear::effective(2031));
        $this->assertSame(2026, EaYear::effective(2026));
        $this->assertSame(2025, EaYear::effective(2025));
        $this->assertSame(2024, EaYear::effective(2024));
        $this->assertSame(2024, EaYear::effective(2023));
    }

    public function test_the_rule_lookup_assertion_allows_2024_and_later_years(): void
    {
        EaYear::assert(2024);
        EaYear::assert(2025);
        EaYear::assert(2026);
        EaYear::assert(2027);

        $this->expectException(InvalidArgumentException::class);
        EaYear::assert(2023);
    }

    public function test_rule_only_years_are_rejected_as_booking_years(): void
    {
        foreach ([2023, 2024, 2025] as $year) {
            try {
                EaYear::assertBookable($year);
                $this->fail(sprintf('%d hätte als Buchungsjahr abgelehnt werden müssen.', $year));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Buchungsjahre ab 2026', $exception->getMessage());
            }
        }

        EaYear::assertBookable(2026);
        EaYear::assertBookable(2027);
    }

    public function test_current_or_last_prefers_the_current_booking_year(): void
    {
        $this->travelTo('2027-03-01 09:00:00');
        $this->assertSame(2027, EaYear::currentOrLast());

        $this->travelTo('2025-06-01 09:00:00');
        $this->assertSame(EaYear::LAST_PINNED, EaYear::currentOrLast());
        $this->assertSame(2026, EaYear::currentOrLast());
    }
}
