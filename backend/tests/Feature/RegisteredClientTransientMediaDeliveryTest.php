<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Factory;
use Tests\TestCase;

/**
 * D-44 — a re-resolved registered actor keeps the transient claims of the
 * request.
 *
 * The media delivery and the photo download re-resolve a registered actor from
 * the database. The fresh instance carried none of the transient claims of the
 * request, so a client who had redeemed an invite lost the grant and was
 * refused the media of their own selection gallery — while a guest with the
 * same invite was served, because the guest branch returns the actor itself.
 */
class RegisteredClientTransientMediaDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureContent;

    protected function setUp(): void
    {
        parent::setUp();

        // A per-test storage root keeps this class independent from the
        // shared fake-storage root that other test classes write into.
        $this->useTemporaryStorageDisk('photos');

        // Meilisearch is not available locally; the collection engine keeps
        // the search index in memory.
        config(['scout.driver' => 'collection']);
        Cache::flush();

        $this->fixtureContent = (string) file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $this->createWatermarkAssets();
    }

    // ---------------------------------------------------------------
    // REGRESSION
    // ---------------------------------------------------------------

    /**
     * The defect: a registered client who redeemed an invite is refused the
     * watermarked media of their own selection gallery (403 'Forbidden'),
     * although a guest with the same invite is served.
     *
     * Without the fix this fails on the status (403 instead of 200) and on
     * the error body ('Forbidden' instead of a delivered image).
     */
    public function test_registered_client_with_redeemed_invite_receives_watermarked_selection_gallery_media(): void
    {
        $client = $this->registeredClient();
        $gallery = $this->selectionGallery();
        $invite = $this->inviteFor($gallery);
        $photo = $this->photoIn($gallery);

        $token = $this->tokenWithInviteClaims($client, $gallery, $invite);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');

        $delivered = (string) file_get_contents($response->getFile()->getPathname());
        $this->assertNotSame($this->fixtureContent, $delivered, 'The delivered media must be watermarked.');
        $this->assertNotFalse(@getimagesizefromstring($delivered));
    }

    // ---------------------------------------------------------------
    // NO WIDENED ACCESS
    // ---------------------------------------------------------------

    /**
     * The copy must not become a way around the invite check: a registered
     * user without a redeemed invite is still refused.
     *
     * This passes before and after the fix — it pins the boundary that the
     * fix must not move.
     */
    public function test_registered_client_without_invite_is_still_refused(): void
    {
        $client = $this->registeredClient();
        $gallery = $this->selectionGallery();
        $photo = $this->photoIn($gallery);

        $token = $this->tokenWithoutClaims($client);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg')
            ->assertStatus(403)
            ->assertJson(['error' => 'Forbidden']);
    }

    /**
     * A claim for another gallery in the same token must not leak into the
     * authorization of a different gallery.
     *
     * The invited gallery is served (the claim is carried over), the gallery
     * that is only named in the denormalized claim list stays refused (the
     * claim is re-validated against the live invite records).
     *
     * Without the fix this fails on the first assertion: the invited gallery
     * is refused with 403 'Forbidden' instead of 200.
     */
    public function test_claim_of_another_gallery_does_not_leak_into_a_different_gallery(): void
    {
        $client = $this->registeredClient();
        $invited = $this->selectionGallery();
        $invite = $this->inviteFor($invited);
        $invitedPhoto = $this->photoIn($invited);

        $foreign = $this->selectionGallery();
        $foreignPhoto = $this->photoIn($foreign);

        // The token names a second gallery that has no invite record.
        $token = $this->tokenWithInviteClaims($client, $invited, $invite, [
            (string) $foreign->id,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/media/'.$invited->slug.'/watermarked/'.$invitedPhoto->id.'.jpg')
            ->assertStatus(200);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/media/'.$foreign->slug.'/watermarked/'.$foreignPhoto->id.'.jpg')
            ->assertStatus(403)
            ->assertJson(['error' => 'Forbidden']);
    }

    /**
     * A revoked invite must not survive the copy: the claims are the
     * provenance the provider validated, and the authorization layer
     * re-validates them against the live invite state. A grant whose invite
     * was deleted is refused again.
     *
     * This passes before and after the fix — it pins that the copy is not a
     * new authorization.
     */
    public function test_revoked_invite_does_not_grant_the_media_delivery(): void
    {
        $client = $this->registeredClient();
        $gallery = $this->selectionGallery();
        $invite = $this->inviteFor($gallery);
        $photo = $this->photoIn($gallery);

        $token = $this->tokenWithInviteClaims($client, $gallery, $invite);

        // The invite is revoked after the token was issued.
        $invite->delete();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg')
            ->assertStatus(403)
            ->assertJson(['error' => 'Forbidden']);
    }

    // ---------------------------------------------------------------
    // GUEST BRANCH (unchanged)
    // ---------------------------------------------------------------

    /**
     * The guest branch keeps its behaviour: the guest is served the same
     * watermarked media.
     *
     * This passes before and after the fix.
     */
    public function test_guest_with_redeemed_invite_still_receives_watermarked_selection_gallery_media(): void
    {
        $gallery = $this->selectionGallery();
        $invite = $this->inviteFor($gallery);
        $photo = $this->photoIn($gallery);

        $token = $this->guestToken($gallery, $invite);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    // ---------------------------------------------------------------
    // DOWNLOAD RULES (unchanged)
    // ---------------------------------------------------------------

    /**
     * A selection gallery stays a download-free surface: the download path
     * refuses with the established message even for an invited client.
     *
     * Without the fix this fails on the message: the client is refused
     * earlier with 'Unauthorized access to this gallery.' because the
     * transient claim is missing on the re-resolved actor.
     */
    public function test_download_from_selection_gallery_is_still_refused_with_the_established_message(): void
    {
        $client = $this->registeredClient();
        $gallery = $this->selectionGallery();
        $invite = $this->inviteFor($gallery);
        $photo = $this->photoIn($gallery);

        $token = $this->tokenWithInviteClaims($client, $gallery, $invite);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/photos/'.$photo->id.'/download')
            ->assertStatus(403)
            ->assertJson(['message' => 'Auswahl-Galerien erlauben keinen Download.']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createWatermarkAssets(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            return;
        }

        Storage::disk('photos')->makeDirectory('_watermarks');
        foreach ([500, 1000, 2000] as $bucket) {
            foreach (['master_', 'master_selection_'] as $prefix) {
                $image = imagecreatetruecolor(32, 32);
                $color = imagecolorallocatealpha($image, 255, 255, 255, 40);
                imagefill($image, 0, 0, $color);
                $path = Storage::disk('photos')->path('_watermarks/'.$prefix.$bucket.'.png');
                imagepng($image, $path);
                imagedestroy($image);
            }
        }
    }

    private function registeredClient(): User
    {
        return User::factory()->create([
            'brand' => Brand::B2B,
            'flatrate_level' => 'none',
        ]);
    }

    private function selectionGallery(): Gallery
    {
        return Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'selection',
            'is_public' => false,
        ]);
    }

    private function inviteFor(Gallery $gallery): GalleryInvite
    {
        return GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'invite-'.Str::random(12),
        ]);
    }

    private function photoIn(Gallery $gallery): Photo
    {
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        return $photo;
    }

    /**
     * A token that carries the transient claims of a redeemed invite, exactly
     * as the invite redemption issues them.
     *
     * @param  array<int, string>  $additionalGalleries
     */
    private function tokenWithInviteClaims(
        User $client,
        Gallery $gallery,
        GalleryInvite $invite,
        array $additionalGalleries = [],
    ): string {
        return JWTAuth::claims([
            'transient_galleries' => array_merge([(string) $gallery->id], $additionalGalleries),
            'transient_meta_galleries' => [],
            'transient_invites' => [
                (string) $invite->id => [
                    'gallery_id' => (string) $gallery->id,
                    'can_edit_metadata' => false,
                ],
            ],
            'transient_invite_ids' => [(string) $invite->id],
        ])->fromUser($client);
    }

    private function tokenWithoutClaims(User $client): string
    {
        return JWTAuth::claims([])->fromUser($client);
    }

    private function guestToken(Gallery $gallery, GalleryInvite $invite): string
    {
        $guestId = (string) Str::uuid();
        $payload = app(Factory::class)->customClaims([
            'sub' => 'guest_'.$guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Gast',
            'guest_invite_id' => (string) $invite->id,
            'transient_galleries' => [(string) $gallery->id],
            'transient_meta_galleries' => [],
        ])->make();

        return app(\PHPOpenSourceSaver\JWTAuth\JWTAuth::class)->encode($payload)->get();
    }
}
