<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SearchTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();
        \Illuminate\Support\Facades\Artisan::call('scout:flush', ['model' => \App\Models\Photo::class]);
        \Illuminate\Support\Facades\Artisan::call('scout:flush', ['model' => \App\Models\Gallery::class]);
        \Illuminate\Support\Facades\Artisan::call('scout:sync-index-settings');
    }


    public function test_search_discovery_returns_public_galleries() {
        Gallery::factory()->create(['is_public' => true, 'name' => 'Public Wedding']);
        Gallery::factory()->create(['is_public' => false, 'name' => 'Private Secret']);

        $response = $this->getJson('/api/search?q=');
        $response->assertStatus(200);
        $data = $response->json('galleries');
        $this->assertCount(1, $data);
        $this->assertEquals('Public Wedding', $data[0]['name']);
    }


    public function test_search_filters_photos_by_metadata() {
        $gallery = Gallery::factory()->create(['is_public' => true, 'type' => 'delivery', 'name' => 'Public Search Gallery']);
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => 'UniqueMountainView',
            'description' => 'A very specific description about mountains',
            'keywords' => 'Alps, Snow, Hiking'
        ]);

        // Wir warten kurz (Flaky-Test-Prevention für CI), falls Meilisearch im Hintergrund noch indexiert
        usleep(500000); 

        $response = $this->getJson('/api/search?q=UniqueMountainView');
        $response->assertStatus(200);
        
        $data = $response->json('photos');
        $this->assertIsArray($data);
        $this->assertCount(1, $data, 'Meilisearch hat das gesuchte Foto nicht gefunden');
        $this->assertEquals('UniqueMountainView', $data[0]['title']);
        $this->assertArrayHasKey('galleries', $response->json());
    }

    public function test_personal_feed_returns_allowed_galleries_and_photos() {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));

        $ownGallery = Gallery::factory()->create(['name' => 'Own Gallery']);
        $photographer->galleries()->attach($ownGallery);
        Photo::factory()->create(['gallery_id' => $ownGallery->id]);

        // Eine fremde Galerie, auf die der Fotograf keinen Zugriff hat
        Gallery::factory()->create(['name' => 'Other Gallery']);

        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->getJson('/api/search?personal=true');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertCount(1, $data['galleries']);
        $this->assertEquals('Own Gallery', $data['galleries'][0]['name']);
        $this->assertCount(1, $data['photos']);
    }

    public function test_search_respects_role_based_filtering() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));

        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => 'client']));

        Gallery::factory()->create(['is_public' => false, 'name' => 'Secret Admin Stuff']);
        Gallery::factory()->create(['is_public' => true, 'name' => 'Public Showcase']);

        // Admin sollte alles sehen
        $adminToken = auth('api')->login($admin);
        $adminResponse = $this->withHeaders(['Authorization' => "Bearer $adminToken"])
                              ->getJson('/api/search?q=');
        $adminResponse->assertStatus(200);
        $this->assertCount(2, $adminResponse->json('galleries'));

        // Client (ohne explizite Zuweisung) sollte nur öffentliche sehen
        $clientToken = auth('api')->login($client);
        $clientResponse = $this->withHeaders(['Authorization' => "Bearer $clientToken"])
                               ->getJson('/api/search?q=');
        $clientResponse->assertStatus(200);
        $this->assertCount(1, $clientResponse->json('galleries'));
        $this->assertEquals('Public Showcase', $clientResponse->json('galleries')[0]['name']);
    }


    public function test_client_cannot_find_photos_from_unauthorized_private_gallery() {
        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => 'client']));

        $privateGallery = Gallery::factory()->create(['is_public' => false, 'type' => 'delivery', 'name' => 'Top Secret']);
        Photo::factory()->create(['gallery_id' => $privateGallery->id, 'title' => 'SecretPhoto123']);

        usleep(500000); // Wait for Meilisearch index sync

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->getJson('/api/search?q=SecretPhoto123');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('photos'), 'Client found photos from unauthorized private gallery');
    }

    public function test_client_can_find_photos_from_authorized_private_gallery() {
        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => 'client']));

        $privateGallery = Gallery::factory()->create(['is_public' => false, 'type' => 'delivery', 'name' => 'My Secret']);
        $client->galleries()->attach($privateGallery);
        Photo::factory()->create(['gallery_id' => $privateGallery->id, 'title' => 'AllowedPhoto123']);

        usleep(500000);

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->getJson('/api/search?q=AllowedPhoto123');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('photos'), 'Client could not find photos from authorized gallery');
        $this->assertEquals('AllowedPhoto123', $response->json('photos')[0]['title']);
    }

    public function test_guest_cannot_find_photos_from_private_gallery() {
        $privateGallery = Gallery::factory()->create(['is_public' => false, 'type' => 'delivery', 'name' => 'Guest Hidden']);
        Photo::factory()->create(['gallery_id' => $privateGallery->id, 'title' => 'GuestSecretPhoto']);

        usleep(500000);

        $response = $this->getJson('/api/search?q=GuestSecretPhoto');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('photos'), 'Guest found photos from private gallery');
    }
    
    public function test_photographer_can_find_photos_from_own_gallery_but_not_others() {
        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));

        $ownGallery = Gallery::factory()->create(['is_public' => false, 'type' => 'delivery']);
        $photog->galleries()->attach($ownGallery);
        Photo::factory()->create(['gallery_id' => $ownGallery->id, 'title' => 'PhotogOwnPhoto']);

        $otherGallery = Gallery::factory()->create(['is_public' => false, 'type' => 'delivery']);
        Photo::factory()->create(['gallery_id' => $otherGallery->id, 'title' => 'PhotogOtherPhoto']);

        usleep(500000);

        $token = auth('api')->login($photog);
        
        $resOwn = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/search?q=PhotogOwnPhoto');
        $this->assertCount(1, $resOwn->json('photos'));

        $resOther = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/search?q=PhotogOtherPhoto');
        $this->assertCount(0, $resOther->json('photos'), 'Photographer found photos from unauthorized gallery');
    }

}
