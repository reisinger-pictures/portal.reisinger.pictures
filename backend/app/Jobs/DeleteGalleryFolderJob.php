<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class DeleteGalleryFolderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $galleryId;

    public function __construct(string $galleryId)
    {
        $this->galleryId = $galleryId;
    }

    public function handle(): void
    {
        Storage::disk('photos')->deleteDirectory($this->galleryId);
    }
}
