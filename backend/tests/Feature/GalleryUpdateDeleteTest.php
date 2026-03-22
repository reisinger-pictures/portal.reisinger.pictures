<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class GalleryUpdateDeleteTest extends TestCase {
    use RefreshDatabase;

    public function test_photographer_can_delete_gallery_and_files_are_removed() {
        Storage::fake('photos');

        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));
        $token = auth('api')->login($photog);

        $gallery = Gallery::factory()->create();
        $photog->galleries()->attach($gallery);

        // Simuliere, dass Dateien im Galerie-Ordner existieren
        Storage::disk('photos')->makeDirectory((string)$gallery->id);
        Storage::disk('photos')->put($gallery->id . '/test.jpg', 'dummy content');

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->deleteJson("/api/management/galleries/{$gallery->id}");

        $response->assertStatus(200);

        // Datenbankeintrag muss weg sein
        $this->assertDatabaseMissing('galleries', ['id' => $gallery->id]);

        // Verzeichnis muss vom Storage gelöscht worden sein
        $this->assertFalse(Storage::disk('photos')->exists((string)$gallery->id));
    }
}