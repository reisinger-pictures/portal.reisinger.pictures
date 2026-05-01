<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GalleryModalFieldsTest extends TestCase {
    use RefreshDatabase;

    public function test_can_create_and_update_entities_with_all_modal_fields() {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));
        $token = auth('api')->login($user);

        // 1. Group erstellen mit allen Settings
        $resGroup = $this->withHeaders(['Authorization' => "Bearer " . $token])
            ->postJson('/api/management/gallery-groups', [
                'name' => 'Secret Group',
                'is_hidden' => true,
                'is_editorial_only' => true,
                'is_free_download' => true
            ]);
        
        $resGroup->assertStatus(200);
        $groupId = $resGroup->json('id') ?? $resGroup->json('group.id');

        $this->assertDatabaseHas('gallery_groups', [
            'id' => $groupId,
            'is_hidden' => 1,
            'is_editorial_only' => 1,
            'is_free_download' => 1
        ]);

        // 2. Gallery erstellen mit denselben Feldern
        $resGallery = $this->withHeaders(['Authorization' => "Bearer " . $token])
            ->postJson('/api/management/galleries', [
                'name' => 'Secret Gallery',
                'type' => 'delivery',
                'gallery_group_id' => $groupId,
                'is_hidden' => true,
                'is_editorial_only' => false, // Bewusst false für den Update-Test
                'is_free_download' => false,
                'is_live' => true
            ]);
            
        $resGallery->assertStatus(200);
        $galleryId = $resGallery->json('id') ?? $resGallery->json('gallery.id');

        $this->assertDatabaseHas('galleries', [
            'id' => $galleryId,
            'is_hidden' => 1,
            'is_editorial_only' => 0,
            'is_free_download' => 0,
            'is_live' => 1
        ]);

        // 3. Roundtrip / Update Check: Gallery Felder nachträglich ändern
        $resUpdate = $this->withHeaders(['Authorization' => "Bearer " . $token])
            ->putJson("/api/management/galleries/" . $galleryId, [
                'name' => 'Secret Gallery Updated',
                'type' => 'delivery',
                'is_hidden' => false,
                'is_editorial_only' => true,
                'is_free_download' => true,
                'is_live' => false
            ]);

        $resUpdate->assertStatus(200);

        $this->assertDatabaseHas('galleries', [
            'id' => $galleryId,
            'is_hidden' => 0,
            'is_editorial_only' => 1,
            'is_free_download' => 1,
            'is_live' => 0
        ]);
    }
}
