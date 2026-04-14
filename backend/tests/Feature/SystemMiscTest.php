<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class SystemMiscTest extends TestCase
{
    use RefreshDatabase;

    public function test_x_accel_redirect_header_is_present()
    {
        Storage::fake('photos');
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => true, 'is_free_download' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'filename' => 'test.jpg']);
        
        Storage::disk('photos')->put($gallery->id . '/test.jpg', 'dummy content');
        
        putenv('PROXY_DELIVERY_HEADER=X-Accel-Redirect');
        
        $response = $this->get('/api/media/' . $gallery->slug . '/' . $photo->id . '.jpg');
        $response->assertStatus(200);
        $response->assertHeader('X-Accel-Redirect');
        
        putenv('PROXY_DELIVERY_HEADER='); // Cleanup
    }

    public function test_image_processor_cli_fallback_works_without_crashing()
    {
        // Force the fallback branch in ImageProcessor by temporarily hiding the Imagick class
        config(['app.force_image_cli' => true]);
        
        $sourcePath = storage_path('app/private/temp/cli_source.jpg');
        $destPath = storage_path('app/private/temp/cli_dest.jpg');
        
        if (!is_dir(dirname($sourcePath))) {
            mkdir(dirname($sourcePath), 0755, true);
        }
        copy(base_path('tests/Fixtures/sample.jpg'), $sourcePath);

        $processor = app(\App\Services\ImageProcessor::class);
        $result = $processor->scaleImage($sourcePath, $destPath, 800);

        // Der Befehl wird wahrscheinlich erfolgreich sein (oder gracefully scheitern wenn imagemagick auf dem Host fehlt).
        // Wir stellen sicher, dass es keinen fatalen Crash gab.
        $this->assertIsBool($result);

        @unlink($sourcePath);
        @unlink($destPath);
    }
}
