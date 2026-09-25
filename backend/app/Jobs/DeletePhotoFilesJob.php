<?php

namespace App\Jobs;

use App\Models\Photo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeletePhotoFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    protected $galleryId;

    protected $filename;

    protected $photoId;

    public function __construct(string $galleryId, string $filename, string $photoId)
    {
        $this->galleryId = $galleryId;
        $this->filename = $filename;
        $this->photoId = $photoId;
    }

    public function galleryId(): string
    {
        return $this->galleryId;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function photoId(): string
    {
        return $this->photoId;
    }

    public function handle(): void
    {
        $disk = Storage::disk('photos');

        $paths = [
            "{$this->galleryId}/{$this->filename}",
            "{$this->galleryId}/_watermarked/{$this->filename}",
        ];

        $sizes = Photo::DERIVATIVE_SIZES;
        foreach ($sizes as $size) {
            // Alte Thumbs (md5) und neue Thumbs berücksichtigen
            $oldThumbName = md5($this->filename.'1024').'.webp';
            $newThumbName = $this->photoId.'.webp';

            $paths[] = "{$this->galleryId}/_thumbs/{$oldThumbName}";
            $paths[] = "{$this->galleryId}/_thumbs/_watermarked/{$oldThumbName}";

            $paths[] = "{$this->galleryId}/_thumbs/{$size}/{$newThumbName}";
            $paths[] = "{$this->galleryId}/_thumbs/_watermarked/{$size}/{$newThumbName}";
        }

        $paths = array_values(array_unique($paths));

        // The photos disk is configured with `throw => false`, so a failed
        // unlink only returns false and is otherwise swallowed. Verify that the
        // files are actually gone and surface a real failure so the job is
        // retried and `failed()` runs instead of reporting a silent success.
        $disk->delete($paths);

        $remaining = array_values(array_filter(
            $paths,
            static fn (string $path): bool => $disk->exists($path)
        ));

        if ($remaining !== []) {
            Log::error('DeletePhotoFilesJob: files still exist after deletion', [
                'galleryId' => $this->galleryId,
                'filename' => $this->filename,
                'photoId' => $this->photoId,
                'remaining' => $remaining,
            ]);

            throw new \RuntimeException(sprintf(
                'Failed to delete %d file(s) for photo %s',
                count($remaining),
                $this->photoId
            ));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Queue job failed', [
            'job' => static::class,
            'galleryId' => $this->galleryId,
            'filename' => $this->filename,
            'photoId' => $this->photoId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
