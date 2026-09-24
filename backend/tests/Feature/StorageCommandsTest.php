<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\Photo;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class StorageCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
    }

    public function test_cleanup_derivatives_removes_stale_webp()
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'last_accessed_at' => Carbon::now()->subDays(15),
        ]);

        $thumbPath = $gallery->id.'/_thumbs/800/'.$photo->id.'.webp';
        Storage::disk('photos')->makeDirectory(dirname($thumbPath));
        Storage::disk('photos')->put($thumbPath, 'dummy');

        $this->assertTrue(Storage::disk('photos')->exists($thumbPath));

        $this->artisan('app:cleanup-derivatives')->assertExitCode(0);

        $this->assertFalse(Storage::disk('photos')->exists($thumbPath));
    }

    public function test_cleanup_derivatives_reports_failed_unlink_without_counting_the_photo_as_deleted(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'last_accessed_at' => Carbon::now()->subDays(15),
        ]);
        $thumbPath = $gallery->id.'/_thumbs/800/'.$photo->id.'.webp';
        $remainingPaths = [$thumbPath => true];

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')
            ->andReturnUsing(fn (string $path): bool => $remainingPaths[$path] ?? false);
        $disk->shouldReceive('delete')
            ->once()
            ->with($thumbPath)
            ->andReturn(false);
        Storage::set('photos', $disk);
        Log::spy();

        $this->artisan('app:cleanup-derivatives')
            ->expectsOutput('WebP-Derivate für 0 inaktive Bilder bereinigt; 1 Löschvorgang fehlgeschlagen.')
            ->assertExitCode(1);

        $this->assertTrue($disk->exists($thumbPath));
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Automated cleanup: failed to delete derivative', [
                'photo_id' => $photo->id,
                'path' => $thumbPath,
            ]);
    }

    public function test_downscale_editorial_scales_old_editorial_images()
    {
        $gallery = Gallery::factory()->create(['is_editorial_only' => true]);
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'created_at' => Carbon::now()->subDays(8),
            'is_downscaled' => false,
        ]);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, file_get_contents($fixturePath));

        $this->artisan('app:downscale-editorial')->assertExitCode(0);
    }
}
