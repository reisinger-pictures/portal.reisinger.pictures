<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

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
            'last_accessed_at' => Carbon::now()->subDays(15)
        ]);

        $thumbPath = $gallery->id . '/_thumbs/800/' . $photo->id . '.webp';
        Storage::disk('photos')->makeDirectory(dirname($thumbPath));
        Storage::disk('photos')->put($thumbPath, 'dummy');

        $this->assertTrue(Storage::disk('photos')->exists($thumbPath));

        $this->artisan('app:cleanup-derivatives')->assertExitCode(0);

        $this->assertFalse(Storage::disk('photos')->exists($thumbPath));
    }

    public function test_downscale_editorial_scales_old_editorial_images()
    {
        $gallery = Gallery::factory()->create(['is_editorial_only' => true]);
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'created_at' => Carbon::now()->subDays(8),
            'is_downscaled' => false
        ]);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        Storage::disk('photos')->put($gallery->id . '/' . $photo->filename, file_get_contents($fixturePath));

        $this->artisan('app:downscale-editorial')->assertExitCode(0);
    }
}
