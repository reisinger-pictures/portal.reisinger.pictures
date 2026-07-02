<?php

namespace App\Jobs;

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

    public $tries = 3;

    public $backoff = [30, 60, 120];

    protected $galleryId;
    protected $filename;
    protected $photoId;

    public function __construct(string $galleryId, string $filename, string $photoId)
    {
        $this->galleryId = $galleryId;
        $this->filename = $filename;
        $this->photoId = $photoId;
    }

    public function handle(): void
    {
        $paths = [
            "{$this->galleryId}/{$this->filename}",
            "{$this->galleryId}/_watermarked/{$this->filename}",
        ];
        
        $sizes = \App\Models\Photo::DERIVATIVE_SIZES;
        foreach ($sizes as $size) {
            // Alte Thumbs (md5) und neue Thumbs berücksichtigen
            $oldThumbName = md5($this->filename . '1024') . '.webp';
            $newThumbName = $this->photoId . '.webp';
            
            $paths[] = "{$this->galleryId}/_thumbs/{$oldThumbName}";
            $paths[] = "{$this->galleryId}/_thumbs/_watermarked/{$oldThumbName}";
            
            $paths[] = "{$this->galleryId}/_thumbs/{$size}/{$newThumbName}";
            $paths[] = "{$this->galleryId}/_thumbs/_watermarked/{$size}/{$newThumbName}";
        }

        Storage::disk('photos')->delete($paths);
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
