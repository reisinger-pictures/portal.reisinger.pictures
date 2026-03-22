<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class DownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
    }

    public function test_authorized_user_can_download_single_image_and_log_is_created()
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'filename' => 'download_test.jpg']);

        // Lege ein Dummy-File in den fake Storage
        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        $content = file_exists($fixturePath) ? file_get_contents($fixturePath) : 'dummy content';
        Storage::disk('photos')->put($gallery->id . '/download_test.jpg', $content);
        
        $token = auth('api')->login($user);
        
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('/api/photos/' . $photo->id . '/download');

        $response->assertStatus(200);
        $response->assertDownload();
        
        // Assert: Audit-Log wurde DSGVO-konform (ohne IP) geschrieben
        $this->assertDatabaseHas('download_logs', [
            'user_id' => $user->id,
            'item_type' => 'single_image',
            'item_identifier' => 'download_test.jpg'
        ]);
    }

    public function test_guest_can_download_public_gallery_zip_and_log_is_created()
    {
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => true]);
        Photo::factory()->create(['gallery_id' => $gallery->id, 'filename' => 'pic1.jpg']);
        
        Storage::disk('photos')->put($gallery->id . '/pic1.jpg', 'dummy');

        // Aufruf ohne Authentifizierung
        $response = $this->get('/api/galleries/' . $gallery->id . '/download-zip');

        $response->assertStatus(200);
        // Da wir ZipStream verwenden, ist der Header Content-Type application/zip oder application/octet-stream
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        
        $this->assertDatabaseHas('download_logs', [
            'user_id' => null,
            'user_name_snapshot' => 'Gast',
            'item_type' => 'full_zip',
            'gallery_id' => $gallery->id
        ]);
    }
}
