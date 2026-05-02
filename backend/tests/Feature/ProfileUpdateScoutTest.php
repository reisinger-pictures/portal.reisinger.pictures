<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Photo;
use App\Models\Gallery;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProfileUpdateScoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_metadata_copyright_updates_scout_index_for_photos()
    {
        $photographer = User::factory()->create(['name' => 'Old Name', 'metadata_copyright' => 'Old Copyright']);
        $photographer->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        
        $gallery = Gallery::factory()->create();
        $photographer->galleries()->attach($gallery);

        $photo = Photo::factory()->create(['user_id' => $photographer->id, 'gallery_id' => $gallery->id]);

        // Mock für Scout
        \Illuminate\Support\Facades\Bus::fake();

        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson('/api/auth/profile', [
                             'name' => 'New Name',
                             'metadata_copyright' => 'New Awesome Copyright'
                         ]);

        $response->assertStatus(200);

        // Sicherstellen, dass das Profil aktualisiert wurde
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'metadata_copyright' => 'New Awesome Copyright'
        ]);

        // Prüfen, ob der Accessor den neuen Wert auswirft
        $photo->refresh();
        $this->assertEquals('New Awesome Copyright', $photo->artist);
    }

    public function test_updating_ftp_slug_validates_uniqueness_and_formats_it()
    {
        $user1 = User::factory()->create(['ftp_slug' => 'florian']);
        $user2 = User::factory()->create(['ftp_slug' => 'max']);
        $token = auth('api')->login($user2);

        // 1. Conflict Test (422)
        $responseConflict = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson('/api/auth/profile', [
                             'name' => 'Max',
                             'ftp_slug' => 'florian' // Gehört schon user1
                         ]);
        $responseConflict->assertStatus(422);

        // 2. Format & Success Test (200)
        $responseSuccess = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson('/api/auth/profile', [
                             'name' => 'Max',
                             'ftp_slug' => 'Max NeÚ' // Sollte zu max-neu werden
                         ]);
        $responseSuccess->assertStatus(200);
        
        $this->assertDatabaseHas('users', [
            'id' => $user2->id,
            'ftp_slug' => 'max-neu'
        ]);
    }
}