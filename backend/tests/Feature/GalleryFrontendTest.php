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


    public function test_guest_can_view_public_gallery_with_breadcrumbs_without_500_error() {
        $group = \App\Models\GalleryGroup::factory()->create(['name' => 'Parent Group']);
        $gallery = \App\Models\Gallery::factory()->create([
            'is_public' => true, 
            'slug' => 'nested-gal', 
            'gallery_group_id' => $group->id
        ]);
        
        // This request previously crashed due to missing namespace in Breadcrumb generation
        $response = $this->getJson('/api/galleries/nested-gal');
        
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('breadcrumbs'));
        $this->assertEquals('Parent Group', $response->json('breadcrumbs.0.name'));
    }
}
