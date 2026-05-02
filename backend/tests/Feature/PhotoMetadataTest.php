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
        $client->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        
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
                             'headline' => 'Awesome Headline',
                             'description' => 'New Description by Client'
                         ]);

        $response->assertStatus(200);

        // Das Foto muss den neuen Titel haben
        $this->assertDatabaseHas('photos', [
            'id' => $photo->id,
            'title' => 'New Title by Client',
            'headline' => 'Awesome Headline'
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
        $client->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        
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

    public function test_client_cannot_change_artist_metadata() {
        $client = clone User::factory()->create(['can_edit_metadata' => true]);
        $client->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        
        // Neu: Echten Fotografen anlegen, aus dem sich das 'artist' Attribut ableitet
        $photographer = clone User::factory()->create(['name' => 'Original Photographer']);

        $gallery = Gallery::factory()->create([
            'allow_client_metadata_edit' => true,
            'type' => 'delivery'
        ]);
        $client->galleries()->attach($gallery);

        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'user_id' => $photographer->id, // Neu: Referenz statt Fake-Spalte
            'title' => 'Old Title'
        ]);

        $token = auth('api')->login($client);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson("/api/photos/{$photo->id}/meta", [
                             'title' => 'Allowed Title Change',
                             'artist' => 'Hacker Artist Name' 
                         ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('photos', [
            'id' => $photo->id,
            'title' => 'Allowed Title Change',
            'user_id' => $photographer->id // Sicherstellen, dass die ID intakt bleibt
        ]);

        // Prüfen, ob der Accessor weiterhin den Namen des Original-Fotografen ausspuckt
        $this->assertEquals('Original Photographer', $photo->fresh()->artist);
    }

    public function test_photographer_cannot_update_or_delete_other_photographers_photo() {
        $photog1 = User::factory()->create();
        $photog1->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));

        $photog2 = User::factory()->create();
        $photog2->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));

        $gallery1 = Gallery::factory()->create(['restricted_photographers' => true]);
        $photog1->galleries()->attach($gallery1);
        
        $photo1 = Photo::factory()->create(['gallery_id' => $gallery1->id]);

        $token2 = auth('api')->login($photog2);

        // Update Meta
        $this->withHeaders(['Authorization' => "Bearer $token2"])
             ->putJson("/api/photos/{$photo1->id}/meta", ['title' => 'Hacked by P2'])
             ->assertStatus(403);

        // Delete
        $this->withHeaders(['Authorization' => "Bearer $token2"])
             ->deleteJson("/api/photos/{$photo1->id}")
             ->assertStatus(403);
    }


    public function test_photographer_can_update_editorial_flag_and_it_is_returned() {
        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $photog->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photog->id]);

        $token = auth('api')->login($photog);

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
             ->putJson("/api/photos/{$photo->id}/meta", [
                 'is_editorial_only' => true
             ]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('photos', [
            'id' => $photo->id,
            'is_editorial_only' => 1
        ]);

        $resContext = $this->withHeaders(['Authorization' => "Bearer $token"])
             ->getJson("/api/photos/{$photo->id}/context");
        
        $resContext->assertStatus(200);
        $this->assertTrue($resContext->json('photo.is_editorial_only'));
        $this->assertTrue($resContext->json('photo.effective_is_editorial_only'));
    }

    public function test_can_save_stress_keywords_longer_than_255_chars() {
        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        $gallery = Gallery::factory()->create();
        $photog->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photog->id]);

        $longKeywords = str_repeat('long_keyword_string, ', 50); // > 1000 Zeichen
        $this->assertGreaterThan(255, strlen($longKeywords));

        $token = auth('api')->login($photog);
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
             ->putJson("/api/photos/{$photo->id}/meta", [
                 'keywords' => $longKeywords
             ]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('photos', [
            'id' => $photo->id,
            'keywords' => $longKeywords
        ]);
    }
}