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

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    protected $galleryId;

    public function __construct(string $galleryId)
    {
        $this->galleryId = $galleryId;
    }

    public function galleryId(): string
    {
        return $this->galleryId;
    }

    public function handle(): void
    {
        $disk = Storage::disk('photos');

        // A retry can legitimately arrive after a previous attempt already
        // removed the directory. Delete first, then treat an absent directory
        // as success; only a directory that still exists after the attempt is
        // a real failure.
        //
        // `deleteDirectory()` returns false when the underlying driver fails
        // (the `photos` disk uses `throw => false`). The postcondition check
        // distinguishes that failure from an already-completed retry.
        $deleted = $disk->deleteDirectory($this->galleryId);

        $stillExists = $disk->exists($this->galleryId);
        if (! $stillExists) {
            return;
        }

        Log::error('DeleteGalleryFolderJob: gallery folder still exists after deletion', [
            'galleryId' => $this->galleryId,
        ]);

        throw new \RuntimeException("Failed to delete gallery folder {$this->galleryId}");
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
