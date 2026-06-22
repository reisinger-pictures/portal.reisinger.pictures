<?php

namespace Tests\Unit;

use App\Services\PayoutCalculationService;
use PHPUnit\Framework\TestCase;

/**
 * BK-05 · P1 — Pure-Unit-Tests für PayoutCalculationService::getShareMultiplier().
 *
 * Framework-/DB-frei (PHPUnit\Framework\TestCase, NICHT Tests\TestCase).
 * Der match()-Default-Zweig deckt alle nicht explizit genannten Tiers ab
 * (inkl. null, leerer String, unbekannte Werte) → default => 1.
 */
class PayoutShareMultiplierTest extends TestCase
{
    private PayoutCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PayoutCalculationService();
    }

    public function test_original_tier_returns_multiplier_4(): void
    {
        $this->assertSame(4, $this->service->getShareMultiplier('original'));
    }

    public function test_print_tier_returns_multiplier_2(): void
    {
        $this->assertSame(2, $this->service->getShareMultiplier('print'));
    }

    public function test_web_tier_returns_multiplier_1(): void
    {
        $this->assertSame(1, $this->service->getShareMultiplier('web'));
    }

    public function test_unknown_tier_falls_back_to_default_1(): void
    {
        $this->assertSame(1, $this->service->getShareMultiplier('ultra'));
    }

    public function test_null_tier_falls_back_to_default_1(): void
    {
        $this->assertSame(1, $this->service->getShareMultiplier(null));
    }

    public function test_empty_string_tier_falls_back_to_default_1(): void
    {
        $this->assertSame(1, $this->service->getShareMultiplier(''));
    }

    public function test_multiplier_ordering_original_greater_print_greater_web(): void
    {
        // Explizite Hierarchie-Dokumentation: original (4) > print (2) > web (1).
        $this->assertGreaterThan(
            $this->service->getShareMultiplier('print'),
            $this->service->getShareMultiplier('original')
        );
        $this->assertGreaterThan(
            $this->service->getShareMultiplier('web'),
            $this->service->getShareMultiplier('print')
        );
    }
}
