<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use App\Jobs\DeleteGalleryFolderJob;
use App\Jobs\DeletePhotoFilesJob;

class AsyncDeletionTest extends TestCase {
    public function test_delete_gallery_folder_job_removes_directory() {
        Storage::fake('photos');
        Storage::disk('photos')->makeDirectory('gallery-123');
        Storage::disk('photos')->put('gallery-123/file.jpg', 'content');
        
        $this->assertTrue(Storage::disk('photos')->exists('gallery-123/file.jpg'));
        
        (new DeleteGalleryFolderJob('gallery-123'))->handle();
        
        $this->assertFalse(Storage::disk('photos')->exists('gallery-123'));
    }

    public function test_delete_photo_files_job_removes_all_derivatives() {
        Storage::fake('photos');
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
            $this->assertFalse(Storage::disk('photos')->exists($path), "Path was not deleted: " . $path);
        }
    }
}
