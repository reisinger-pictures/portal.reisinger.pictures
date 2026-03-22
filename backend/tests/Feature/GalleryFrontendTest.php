<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GalleryFrontendTest extends TestCase {
    use RefreshDatabase;

    public function test_guest_can_view_public_gallery() {
        $gallery = Gallery::factory()->create(['is_public' => true, 'slug' => 'public-gal']);
        $response = $this->getJson('/api/galleries/public-gal');
        $response->assertStatus(200);
    }

    public function test_guest_cannot_view_private_gallery() {
        $gallery = Gallery::factory()->create(['is_public' => false, 'slug' => 'private-gal']);
        $response = $this->getJson('/api/galleries/private-gal');
        $response->assertStatus(401);
    }
}