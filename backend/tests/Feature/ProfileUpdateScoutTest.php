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
        $photographer->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));
        
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
}
