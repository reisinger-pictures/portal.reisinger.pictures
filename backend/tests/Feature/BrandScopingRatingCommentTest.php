<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
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

        // A client reaches a selection gallery through an invite. A persistent
        // client grant of a selection gallery is fail-closed (D-39), so the
        // invite provenance is the access this test needs.
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'brand-scoping-rating-comment-'.uniqid(),
        ]);

        return JWTAuth::claims([
            'transient_galleries' => [$gallery->id],
            'transient_invites' => [
                (string) $invite->id => [
                    'gallery_ids' => [(string) $gallery->id],
                    'meta_gallery_ids' => [],
                ],
            ],
            'transient_invite_ids' => [(string) $invite->id],
        ])->fromUser($user);
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
