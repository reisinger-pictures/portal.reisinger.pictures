<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteGalleryFolderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    protected $galleryId;

    public function __construct(string $galleryId)
    {
        $this->galleryId = $galleryId;
    }

    public function handle(): void
    {
        $disk = Storage::disk('photos');

        // `deleteDirectory()` returns false when the underlying driver fails
        // (the `photos` disk uses `throw => false`). Treat that as a real
        // failure so the job is retried instead of reporting silent success.
        $deleted = $disk->deleteDirectory($this->galleryId);

        if (! $deleted || $disk->exists($this->galleryId)) {
            Log::error('DeleteGalleryFolderJob: gallery folder still exists after deletion', [
                'galleryId' => $this->galleryId,
            ]);

            throw new \RuntimeException("Failed to delete gallery folder {$this->galleryId}");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'galleryId' => $this->galleryId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
