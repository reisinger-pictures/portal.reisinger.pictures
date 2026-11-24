<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Exceptions\EurDocumentPositionException;
use App\Services\Eur\EurAssetCreationService;
use App\Services\Eur\EurAssetEigenbelegService;
use App\Services\Eur\EurAssetGroupService;
use App\Services\Eur\EurDocumentPositionService;
use App\Services\Eur\EurEntnahmeService;
use PHPUnit\Framework\TestCase;

/** Portal #59/D-90: reine Allokationsarithmetik in ganzen Cent. */
class EurDocumentPositionServiceTest extends TestCase
{
    public function test_residual_is_reported_in_whole_cents(): void
    {
        $totals = $this->service()->allocationTotals([
            ['amount_cents' => 240000],
            ['amount_cents' => 60000],
        ], 320000);

        $this->assertSame(300000, $totals['allocated_cents']);
        $this->assertSame(20000, $totals['remaining_cents']);
        $this->assertIsInt($totals['allocated_cents']);
        $this->assertIsInt($totals['remaining_cents']);
    }

    public function test_without_a_receipt_total_the_remainder_is_null(): void
    {
        $totals = $this->service()->allocationTotals([['amount_cents' => 12345]], null);

        $this->assertSame(12345, $totals['allocated_cents']);
        $this->assertNull($totals['remaining_cents']);
    }

    public function test_over_allocation_is_refused(): void
    {
        $this->expectException(EurDocumentPositionException::class);
        $this->expectExceptionMessage(EurDocumentPositionException::OVER_ALLOCATION_MESSAGE);

        $this->service()->allocationTotals([
            ['amount_cents' => 240000],
            ['amount_cents' => 60001],
        ], 300000);
    }

    public function test_exact_allocation_leaves_no_remainder(): void
    {
        $totals = $this->service()->allocationTotals([
            ['amount_cents' => 14900],
            ['amount_cents' => 1],
        ], 14901);

        $this->assertSame(14901, $totals['allocated_cents']);
        $this->assertSame(0, $totals['remaining_cents']);
    }

    private function service(): EurDocumentPositionService
    {
        return new EurDocumentPositionService(
            new EurAssetCreationService(
                new EurAssetEigenbelegService,
                new EurEntnahmeService,
                new EurAssetGroupService,
            ),
            new EurAssetGroupService,
        );
    }
}
