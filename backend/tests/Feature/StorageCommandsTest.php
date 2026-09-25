<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\Photo;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

class StorageCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryStorageDisk('photos');
    }

    public function test_cleanup_temp_reports_failed_unlink_and_returns_failure(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cleanup-temp-');
        $this->assertNotFalse($path);
        touch($path, now()->subDays(2)->getTimestamp());

        $file = new SplFileInfo($path, '', $path);
        File::shouldReceive('allFiles')
            ->once()
            ->with(storage_path('app/private/temp'))
            ->andReturn([$file]);
        File::shouldReceive('delete')
            ->once()
            ->with($path)
            ->andReturn(false);
        Log::spy();

        try {
            $this->artisan('app:cleanup-temp')
                ->expectsOutput('Temp-Ordner bereinigt: 0 alte Dateien gelöscht; 1 Löschvorgang fehlgeschlagen.')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($path): bool {
                return $message === 'Automated cleanup: temp-file deletion failures'
                    && $context['deleted_count'] === 0
                    && $context['failed_count'] === 1
                    && count($context['failures']) === 1
                    && $context['failures'][0]['path'] === $path;
            });
    }

    public function test_cleanup_temp_bounds_failure_log_details(): void
    {
        $paths = [];
        $files = [];

        try {
            for ($index = 0; $index < 21; $index++) {
                $path = tempnam(sys_get_temp_dir(), 'cleanup-temp-');
                $this->assertNotFalse($path);
                touch($path, now()->subDays(2)->getTimestamp());
                $paths[] = $path;
                $files[] = new SplFileInfo($path, '', $path);
            }

            File::shouldReceive('allFiles')
                ->once()
                ->with(storage_path('app/private/temp'))
                ->andReturn($files);
            File::shouldReceive('delete')
                ->times(count($paths))
                ->andReturn(false);
            Log::spy();

            $this->artisan('app:cleanup-temp')
                ->expectsOutput('Temp-Ordner bereinigt: 0 alte Dateien gelöscht; 21 Löschvorgänge fehlgeschlagen.')
                ->assertExitCode(1);

            Log::shouldHaveReceived('error')
                ->once()
                ->withArgs(function (string $message, array $context): bool {
                    return $message === 'Automated cleanup: temp-file deletion failures'
                        && $context['failed_count'] === 21
                        && count($context['failures']) === 20
                        && $context['failures_omitted'] === 1;
                });
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
        }
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
