<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: the rating comment is bounded so a client cannot store an
 * arbitrarily large payload.
 */
class BrandScopingRatingCommentTest extends TestCase
{
    use RefreshDatabase;

    private function tokenForGallery(Gallery $gallery): string
    {
        $user = User::factory()->create(['brand' => 'rp']);
        $user->galleries()->attach($gallery->id);

        return auth('api')->login($user);
    }

    public function test_comment_longer_than_max_is_rejected(): void
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $token = $this->tokenForGallery($gallery);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/photos/{$photo->id}/rate", [
                'rating' => 5,
                'comment' => str_repeat('a', 2001),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('comment');
    }

    public function test_comment_at_max_is_accepted(): void
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $token = $this->tokenForGallery($gallery);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/photos/{$photo->id}/rate", [
                'rating' => 5,
                'comment' => str_repeat('a', 2000),
            ]);

        $response->assertStatus(200);
    }
}
