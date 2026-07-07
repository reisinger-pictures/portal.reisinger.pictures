<?php

namespace Tests\Feature;

use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use App\Models\PayoutPool;
use App\Models\User;
use App\Services\PayoutCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private PayoutCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PayoutCalculationService();
    }

    private function logDownload(array $attributes): DownloadLog
    {
        $createdAt = $attributes['created_at'] ?? null;
        $log = DownloadLog::factory()->create($attributes);
        if ($createdAt !== null) {
            DownloadLog::where('id', $log->id)->update(['created_at' => $createdAt]);
            return DownloadLog::find($log->id);
        }
        return $log;
    }

    public function test_calculate_pool_shares_is_idempotent(): void
    {
        $pool = PayoutPool::factory()
            ->forMonth(6, 2026)->withNetPool(10000)
            ->create(['photographer_share_percent' => 100]);
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        $this->logDownload([
            'user_id' => User::factory()->create()->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'original',
            'photo_count' => 1,
            'created_at' => '2026-06-15 12:00:00',
        ]);

        // First run
        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertGreaterThan(0, (float)$pool->total_shares);
        $this->assertSame(1, $pool->total_unique_downloads);

        $statements = PhotographerStatement::all();
        $this->assertCount(1, $statements);
        $firstStatement = $statements->first();
        $firstPoolEarnings = $firstStatement->pool_earnings_cents;
        $firstShares = $firstStatement->total_shares_earned;

        // Simulate admin approval (status changes to 'approved' — the guard blocks re-processing)
        $firstStatement = PhotographerStatement::first();
        $firstStatement->update(['status' => 'approved']);

        // Second run — must produce the same result, not double
        $this->service->calculatePoolShares($pool->fresh());

        $statementsAfter = PhotographerStatement::all();
        $this->assertCount(1, $statementsAfter);
        $secondStatement = $statementsAfter->first();
        $this->assertSame($firstPoolEarnings, $secondStatement->pool_earnings_cents);
        $this->assertSame($firstShares, $secondStatement->total_shares_earned);
    }
}
