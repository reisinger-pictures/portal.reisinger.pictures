<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class RetroactiveGalleryDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
    }

    public function test_downloaded_images_contain_retroactively_applied_metadata()
    {
        $photog = User::factory()->create(['name' => 'Test Fotograf']);
        $photog->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));

        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'apply_metadata_to_photos' => true,
        ]);
        $photog->galleries()->attach($gallery);

        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'filename' => 'retro_test.jpg',
            'user_id' => $photog->id,
            'title' => null,
            'city' => null,
        ]);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        Storage::disk('photos')->put($gallery->id . '/retro_test.jpg', file_get_contents($fixturePath));

        $token = auth('api')->login($photog);

        // 1. Löst die retroaktive Übernahme aus
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/galleries/{$gallery->id}", [
                'apply_metadata_to_photos' => true,
                'default_title' => 'Retro Title',
                'default_city' => 'Retro City'
            ])->assertStatus(200);

        // 2. ✨ WICHTIG: Model refreshen, damit die neuen Werte für den Download-Controller bereitstehen
        $photo->refresh();

        // 3. Download via DownloadController
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->get('/api/photos/' . $photo->id . '/download');

        $response->assertStatus(200);
        $downloadedFilePath = $response->getFile()->getPathname();

        $process = new Process(['exiftool', '-json', $downloadedFilePath]);
        $process->run();
        $metaData = json_decode($process->getOutput(), true)[0];

        // 4. Verifizieren
        $this->assertArrayHasKey('ObjectName', $metaData, 'Title is missing in IPTC');
        $this->assertEquals('Retro Title', $metaData['ObjectName']);
        $this->assertEquals('Retro City', $metaData['City']);
    }
}
