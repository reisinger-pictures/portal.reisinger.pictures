<?php

namespace Tests\Feature;

use App\Jobs\DeleteGalleryFolderJob;
use App\Models\Gallery;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Regression: files were deleted before the DB record without a transaction,
 * so a failed DB delete left a broken gallery record behind. Now the record is
 * removed first and file-deletion failures are surfaced and retried.
 */
class CleanupGalleriesFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_record_is_deleted_and_retry_dispatched_when_file_deletion_fails(): void
    {
        Queue::fake();

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('deleteDirectory')->once()->andReturn(false);
        $disk->shouldReceive('exists')->andReturn(true);
        Storage::set('photos', $disk);

        Carbon::setTestNow(Carbon::create(2026, 3, 24, 12, 0, 0));

        $gallery = Gallery::factory()->create([
            'expires_at' => Carbon::now()->subMonths(4),
        ]);

        $this->artisan('app:cleanup-galleries')->assertExitCode(0);

        $this->assertDatabaseMissing('galleries', ['id' => $gallery->id]);
        Queue::assertPushed(DeleteGalleryFolderJob::class);
    }
}
