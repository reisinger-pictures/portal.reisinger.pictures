<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Factory;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Tests\TestCase;

/**
 * Regression: a transient guest has no persistent user row. The notification
 * pivot `user_galleries.user_id` is NOT NULL, so a guest opt-in must not be
 * persisted (previously it attempted a `user_id = null` write).
 */
class BrandScopingGuestOptInTest extends TestCase
{
    use RefreshDatabase;

    private function guestTokenWithGallery(string $galleryId): string
    {
        $invite = GalleryInvite::create([
            'gallery_id' => $galleryId,
            'token' => 'opt-in-guest-invite-'.Str::uuid(),
        ]);
        $guestId = (string) Str::uuid();
        $factory = app(Factory::class);
        $payload = $factory->customClaims([
            'sub' => 'guest_'.$guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Opt-In Guest',
            'guest_invite_id' => $invite->id,
            'transient_galleries' => [$galleryId],
        ])->make();

        return app(JWTAuth::class)->encode($payload)->get();
    }

    public function test_guest_opt_in_is_rejected_without_writing_null_user_row(): void
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        $token = $this->guestTokenWithGallery($gallery->id);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('user_galleries', 0);
    }
}
