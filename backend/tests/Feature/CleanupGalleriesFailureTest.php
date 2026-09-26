<?php

namespace Tests\Feature;

use App\Jobs\DeleteGalleryFolderJob;
use App\Models\Gallery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression: the scheduled command must enqueue public photos-disk cleanup
 * only after the gallery row has committed, while retaining a retryable queue
 * operation for the asynchronous worker.
 */
class CleanupGalleriesFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryStorageDisk('photos');
        config(['scout.driver' => 'null']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_record_is_deleted_and_folder_job_is_dispatched_after_commit(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 3, 24, 12, 0, 0));

        $gallery = Gallery::factory()->create([
            'expires_at' => Carbon::now()->subMonths(4),
        ]);
        $galleryId = (string) $gallery->id;
        Storage::disk('photos')->put("{$galleryId}/test.jpg", 'dummy content');

        $this->artisan('app:cleanup-galleries')->assertExitCode(0);

        $this->assertDatabaseMissing('galleries', ['id' => $galleryId]);
        Queue::assertPushed(DeleteGalleryFolderJob::class);
        // Queue::fake intentionally leaves the external cleanup to its worker.
        Storage::disk('photos')->assertExists("{$galleryId}/test.jpg");
        (new DeleteGalleryFolderJob($galleryId))->handle();
        Storage::disk('photos')->assertMissing($galleryId);
    }
}
