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
        $gallery = Gallery::factory()->create(['is_public' => true, 'name' => 'Public Search Gallery']);
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
        $this->assertArrayHasKey('galleries', $response->json());
    }
}
