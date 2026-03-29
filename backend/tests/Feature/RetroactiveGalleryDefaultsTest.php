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

    public function test_changing_gallery_defaults_retroactively_updates_photos_and_search_index()
    {
        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));

        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'apply_metadata_to_photos' => true,
            'default_city' => 'Old City'
        ]);
        $photog->galleries()->attach($gallery);

        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'city' => null, // leer, sollte durch das Update überschrieben werden
        ]);

        \Illuminate\Support\Facades\Bus::fake();

        $token = auth('api')->login($photog);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/galleries/{$gallery->id}", [
                'apply_metadata_to_photos' => true,
                'default_city' => 'New City',
                'default_country' => 'Austria'
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('photos', [
            'id' => $photo->id,
            'city' => 'New City',
            'country' => 'Austria'
        ]);
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

        // 2. Download via DownloadController
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->get('/api/photos/' . $photo->id . '/download');

        $response->assertStatus(200);
        $downloadedFilePath = $response->getFile()->getPathname();

        $process = new Process(['exiftool', '-json', $downloadedFilePath]);
        $process->run();
        $metaData = json_decode($process->getOutput(), true)[0];

        // 3. Verifizieren, ob ExifTool die neuen Defaults injiziert hat
        $this->assertArrayHasKey('ObjectName', $metaData, 'Title is missing in IPTC');
        $this->assertEquals('Retro Title', $metaData['ObjectName']);
        
        $this->assertArrayHasKey('City', $metaData, 'City is missing in IPTC');
        $this->assertEquals('Retro City', $metaData['City']);
    }
}
