<?php

namespace Tests\Feature;

use App\Jobs\DeleteGalleryFolderJob;
use App\Jobs\DeletePhotoFilesJob;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AsyncDeletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryStorageDisk('photos');
    }

    public function test_delete_gallery_folder_job_removes_directory()
    {
        Storage::disk('photos')->makeDirectory('gallery-123');
        Storage::disk('photos')->put('gallery-123/file.jpg', 'content');

        $this->assertTrue(Storage::disk('photos')->exists('gallery-123/file.jpg'));

        (new DeleteGalleryFolderJob('gallery-123'))->handle();

        $this->assertFalse(Storage::disk('photos')->exists('gallery-123'));
    }

    public function test_public_cleanup_jobs_match_the_outbox_retry_budget(): void
    {
        $this->assertSame(5, (new DeleteGalleryFolderJob('gallery-1'))->tries);
        $this->assertSame([30, 60, 120, 300, 600], (new DeleteGalleryFolderJob('gallery-1'))->backoff);
        $this->assertSame(5, (new DeletePhotoFilesJob('gallery-1', 'test.jpg', 'photo-1'))->tries);
        $this->assertSame([30, 60, 120, 300, 600], (new DeletePhotoFilesJob('gallery-1', 'test.jpg', 'photo-1'))->backoff);
    }

    public function test_delete_photo_files_job_removes_all_derivatives()
    {
        $galId = 'gal-1';
        $filename = 'test.jpg';
        $photoId = 'photo-1';

        $paths = [
            "{$galId}/{$filename}",
            "{$galId}/_watermarked/{$filename}",
            "{$galId}/_thumbs/800/{$photoId}.webp",
            "{$galId}/_thumbs/_watermarked/1200/{$photoId}.webp",
        ];

        foreach ($paths as $path) {
            Storage::disk('photos')->makeDirectory(dirname($path));
            Storage::disk('photos')->put($path, 'content');
            $this->assertTrue(Storage::disk('photos')->exists($path));
        }

        (new DeletePhotoFilesJob($galId, $filename, $photoId))->handle();

        foreach ($paths as $path) {
            $this->assertFalse(Storage::disk('photos')->exists($path), 'Path was not deleted: '.$path);
        }
    }
}
