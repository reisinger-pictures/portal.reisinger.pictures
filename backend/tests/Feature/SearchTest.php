<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\Gallery;
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
}