<?php

namespace Tests\Feature;

use App\Jobs\DeleteGalleryFolderJob;
use App\Jobs\DeletePhotoFilesJob;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Regression: with `throw => false` on the photos disk, the jobs used to
 * ignore failed deletions and report success, leaving orphaned files behind.
 */
class DeleteJobsFailureTest extends TestCase
{
    public function test_delete_photo_files_job_throws_when_files_remain(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once();
        $disk->shouldReceive('exists')->andReturn(true);

        Storage::shouldReceive('disk')->with('photos')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete');

        (new DeletePhotoFilesJob('gal-1', 'test.jpg', 'photo-1'))->handle();
    }

    public function test_delete_gallery_folder_job_throws_when_deletion_fails(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('deleteDirectory')->once()->andReturn(false);
        $disk->shouldReceive('exists')->andReturn(true);

        Storage::shouldReceive('disk')->with('photos')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete gallery folder gal-1');

        (new DeleteGalleryFolderJob('gal-1'))->handle();
    }

    public function test_delete_gallery_folder_job_throws_when_folder_still_exists(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('deleteDirectory')->once()->andReturn(true);
        $disk->shouldReceive('exists')->once()->andReturn(true);

        Storage::shouldReceive('disk')->with('photos')->andReturn($disk);

        $this->expectException(\RuntimeException::class);

        (new DeleteGalleryFolderJob('gal-1'))->handle();
    }
}
