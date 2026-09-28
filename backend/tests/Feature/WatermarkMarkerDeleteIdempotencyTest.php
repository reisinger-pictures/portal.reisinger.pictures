<?php

namespace Tests\Feature;

use App\Jobs\DeletePhotoFilesJob;
use App\Models\Gallery;
use App\Models\Photo;
use App\Services\ImageProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression (AIS-2): a derivative delete removes the `.watermark.json`
 * provenance marker together with its image. The same delete must stay a
 * no-op when the markers are already gone — the marker sibling is probed for
 * every path, so a repeated delete must not fail on the absent files.
 */
class WatermarkMarkerDeleteIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryStorageDisk('photos');
    }

    public function test_delete_photo_files_job_is_idempotent_when_markers_are_already_gone(): void
    {
        $galleryId = (string) Str::uuid();
        $filename = 'photo.jpg';
        $photoId = (string) Str::uuid();

        $imagePaths = [
            "{$galleryId}/{$filename}",
            "{$galleryId}/_watermarked/{$filename}",
        ];
        foreach (Photo::DERIVATIVE_SIZES as $size) {
            $imagePaths[] = "{$galleryId}/_thumbs/{$size}/{$photoId}.webp";
            $imagePaths[] = "{$galleryId}/_thumbs/_watermarked/{$size}/{$photoId}.webp";
        }

        $paths = $this->withMarkers($imagePaths);
        foreach ($paths as $path) {
            Storage::disk('photos')->put($path, 'derivative-bytes');
        }

        $job = new DeletePhotoFilesJob($galleryId, $filename, $photoId);
        $job->handle();

        foreach ($paths as $path) {
            Storage::disk('photos')->assertMissing($path);
        }

        // Every path — images and markers — is already gone. The second run
        // must not throw and must leave the disk empty.
        $job->handle();

        foreach ($paths as $path) {
            Storage::disk('photos')->assertMissing($path);
        }
    }

    public function test_cleanup_derivatives_is_idempotent_when_markers_are_already_gone(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'last_accessed_at' => Carbon::now()->subDays(15),
        ]);

        $imagePaths = [
            $gallery->id.'/_thumbs/800/'.$photo->id.'.webp',
            $gallery->id.'/_thumbs/_watermarked/800/'.$photo->id.'.webp',
        ];
        $paths = $this->withMarkers($imagePaths);
        foreach ($paths as $path) {
            Storage::disk('photos')->put($path, 'derivative-bytes');
        }

        $this->artisan('app:cleanup-derivatives')->assertExitCode(0);
        // Second sweep over the same stale photo: nothing left to delete.
        $this->artisan('app:cleanup-derivatives')->assertExitCode(0);

        foreach ($paths as $path) {
            Storage::disk('photos')->assertMissing($path);
        }
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @return array<int, string>
     */
    private function withMarkers(array $imagePaths): array
    {
        $paths = $imagePaths;
        foreach ($imagePaths as $imagePath) {
            $paths[] = $imagePath.ImageProcessor::WATERMARK_MARKER_SUFFIX;
        }

        return $paths;
    }
}
