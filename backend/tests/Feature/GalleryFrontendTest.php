<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryFrontendTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_public_gallery()
    {
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => true, 'slug' => 'public-gal']);
        $response = $this->getJson('/api/galleries/public-gal');
        $response->assertStatus(200);
    }

    public function test_guest_cannot_view_private_gallery()
    {
        $gallery = Gallery::factory()->create(['is_public' => false, 'slug' => 'private-gal']);
        $response = $this->getJson('/api/galleries/private-gal');
        $response->assertStatus(401);
    }

    public function test_guest_can_view_public_gallery_with_breadcrumbs_without_500_error()
    {
        $group = GalleryGroup::factory()->create(['name' => 'Parent Group']);
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => true,
            'slug' => 'nested-gal',
            'gallery_group_id' => $group->id,
        ]);

        // This request previously crashed due to missing namespace in Breadcrumb generation
        $response = $this->getJson('/api/galleries/nested-gal');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('breadcrumbs'));
        $this->assertEquals('Parent Group', $response->json('breadcrumbs.0.name'));
    }

    public function test_user_cannot_rate_in_delivery_gallery()
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/photos/{$photo->id}/rate", ['rating' => 5]);

        $response->assertStatus(422)
            ->assertJson(['error' => 'Bewertungen sind nur in Auswahl-Galerien erlaubt.']);
    }
}
