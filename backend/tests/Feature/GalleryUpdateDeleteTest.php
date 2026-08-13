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
        $photog->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
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

    public function test_photographer_cannot_update_or_delete_other_photographers_gallery() {
        $photog1 = User::factory()->create();
        $photog1->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        $photog2 = User::factory()->create();
        $photog2->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));

        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);
        $photog1->galleries()->attach($gallery);

        $token2 = auth('api')->login($photog2);

        // Update
        $this->withHeaders(['Authorization' => "Bearer $token2"])
             ->putJson("/api/management/galleries/{$gallery->id}", ['name' => 'Hacked'])
             ->assertStatus(403);

        // Delete
        $this->withHeaders(['Authorization' => "Bearer $token2"])
             ->deleteJson("/api/management/galleries/{$gallery->id}")
             ->assertStatus(403);
    }

    public function test_gallery_update_rejects_volume_preset_from_other_brand() {
        \App\Support\BrandRegistry::set(\App\Enums\Brand::B2B);

        // Preset einer "fremden" Brand — Host-Brand ist rp.
        $foreignPreset = \App\Models\VolumePreset::create(['brand' => 'other', 'name' => 'Fremd', 'is_default' => false]);

        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($photog);

        $gallery = Gallery::factory()->create();
        $photog->galleries()->attach($gallery);

        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->putJson("/api/management/galleries/{$gallery->id}", ['volume_preset_id' => $foreignPreset->id])
             ->assertStatus(422);
    }

    public function test_gallery_update_accepts_volume_preset_from_current_brand() {
        \App\Support\BrandRegistry::set(\App\Enums\Brand::B2B);

        $preset = \App\Models\VolumePreset::create(['brand' => 'rp', 'name' => 'Eigen', 'is_default' => false]);

        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($photog);

        $gallery = Gallery::factory()->create();
        $photog->galleries()->attach($gallery);

        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->putJson("/api/management/galleries/{$gallery->id}", ['volume_preset_id' => $preset->id])
             ->assertStatus(200);

        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'volume_preset_id' => $preset->id]);
    }

    public function test_deleting_group_moves_galleries_to_root() {
        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($photog);

        $group = \App\Models\GalleryGroup::factory()->create();
        $gallery = Gallery::factory()->create(['gallery_group_id' => $group->id]);
        $photog->galleries()->attach($gallery);

        $response = $this->withHeaders(['Authorization' => "Bearer " . $token])
                         ->deleteJson("/api/management/gallery-groups/{$group->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('gallery_groups', ['id' => $group->id]);
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'gallery_group_id' => null
        ]);
    }

}
