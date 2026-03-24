<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PhotoMetadataTest extends TestCase {
    use RefreshDatabase;

    public function test_client_with_rights_can_update_metadata_and_creates_version() {
        $client = User::factory()->create(['can_edit_metadata' => true]);
        $client->roles()->attach(Role::firstOrCreate(['name' => 'client']));
        
        $gallery = Gallery::factory()->create([
            'allow_client_metadata_edit' => true,
            'type' => 'delivery'
        ]);
        $client->galleries()->attach($gallery);

        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => 'Original Title',
            'description' => 'Original Description'
        ]);

        $token = auth('api')->login($client);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson("/api/photos/{$photo->id}/meta", [
                             'title' => 'New Title by Client',
                             'description' => 'New Description by Client'
                         ]);

        $response->assertStatus(200);

        // Das Foto muss den neuen Titel haben
        $this->assertDatabaseHas('photos', [
            'id' => $photo->id,
            'title' => 'New Title by Client'
        ]);

        // Es muss eine Versionierung des ORIGINAL-Zustands existieren
        $this->assertDatabaseHas('photo_metadata_versions', [
            'photo_id' => $photo->id,
            'user_id' => $client->id,
            'title' => 'Original Title',
            'description' => 'Original Description'
        ]);
    }

    public function test_client_without_rights_cannot_update_metadata() {
        $client = User::factory()->create(['can_edit_metadata' => false]); // Recht fehlt
        $client->roles()->attach(Role::firstOrCreate(['name' => 'client']));
        
        $gallery = Gallery::factory()->create([
            'allow_client_metadata_edit' => true,
        ]);
        $client->galleries()->attach($gallery);

        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $token = auth('api')->login($client);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson("/api/photos/{$photo->id}/meta", [
                             'title' => 'Hacked Title'
                         ]);

        $response->assertStatus(403);
    }

    public function test_client_cannot_overwrite_artist_metadata_even_if_in_payload() {
        $client = User::factory()->create(['can_edit_metadata' => true]);
        $client->roles()->attach(Role::firstOrCreate(['name' => 'client']));
        
        $gallery = Gallery::factory()->create([
            'allow_client_metadata_edit' => true,
            'type' => 'delivery'
        ]);
        $client->galleries()->attach($gallery);

        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'artist' => 'Original Photographer'
        ]);

        $token = auth('api')->login($client);

        // Der Client versucht, den Urheber im Payload mitzusenden (Hacking-Versuch)
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson("/api/photos/{$photo->id}/meta", [
                             'title' => 'Allowed Title Change',
                             'artist' => 'Hacker Artist Name' 
                         ]);

        $response->assertStatus(200);

        // Das Foto muss den neuen Titel haben...
        $this->assertDatabaseHas('photos', [
            'id' => $photo->id,
            'title' => 'Allowed Title Change'
        ]);

        // ... ABER der Artist muss zwingend erhalten geblieben sein!
        $this->assertDatabaseHas('photos', [
            'id' => $photo->id,
            'artist' => 'Original Photographer'
        ]);
    }
}
