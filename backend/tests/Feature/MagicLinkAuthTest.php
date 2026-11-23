<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AuthTokenFactory;
use Tests\TestCase;

class MagicLinkAuthTest extends TestCase
{
    use RefreshDatabase;

    // --- TEST 1: Einlösen durch Gast (Erzeugt das Cookie) ---
    public function test_anonymous_guest_can_redeem_invite_and_receive_cookie()
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        $invite = GalleryInvite::create(['gallery_id' => $gallery->id, 'token' => 'test-token']);

        $res = $this->postJson('/api/invites/redeem', [
            'token' => 'test-token',
            'name' => 'Test Guest',
            'email' => 'guest@example.com',
            'accept_privacy' => true,
        ]);

        $res->assertStatus(200)->assertCookie('rp_jwt');
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);
    }

    // --- TEST 2: Fachliche Logik: Gast bewertet mit transientem JWT ---
    public function test_anonymous_guest_with_transient_jwt_can_rate_photo()
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'magic-guest-rating-invite',
        ]);

        // Wir fälschen uns das JWT für den Test, genau so wie der Server es ausstellen würde
        $guestId = (string) Str::uuid();
        $token = AuthTokenFactory::claims([
            'sub' => 'guest_'.$guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Test Guest',
            'guest_invite_id' => $invite->id,
            'transient_galleries' => [$gallery->id],
        ])->asGuest();

        // Wir senden das Token als Bearer-Header, was JWT-Auth standardmäßig unterstützt
        $rateRes = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/photos/{$photo->id}/rate", ['rating' => 5]);

        $rateRes->assertStatus(200);

        $this->assertDatabaseHas('ratings', [
            'photo_id' => $photo->id,
            'user_id' => null,
            'guest_name' => 'Test Guest',
            'rating' => 5,
        ]);
    }

    // --- TEST 3: Einlösen durch angemeldeten User (Erzeugt neues, gemergtes Cookie) ---
    public function test_authenticated_user_can_redeem_invite_and_receive_merged_cookie()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        $invite = GalleryInvite::create(['gallery_id' => $gallery->id, 'token' => 'test-token-2']);

        $initialToken = AuthTokenFactory::forUser($user);

        $res = $this->withHeaders(['Authorization' => "Bearer $initialToken"])
            ->postJson('/api/invites/redeem', [
                'token' => 'test-token-2',
                'accept_privacy' => true,
            ]);

        $res->assertStatus(200)->assertCookie('rp_jwt');
    }

    // Gallery invite tokens are revocable access grants and remain redeemable.
    public function test_gallery_invite_can_be_redeemed_by_guest_then_registered_user(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'is_public' => false,
        ]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'guest-then-registered-token',
        ]);

        $this->postJson('/api/invites/redeem', [
            'token' => $invite->token,
            'name' => 'Anonymous Guest',
            'email' => 'anonymous-guest@example.com',
            'accept_privacy' => true,
        ])
            ->assertOk()
            ->assertJsonPath('full_path', $gallery->full_path);

        // The registered user token is issued separately from the guest token:
        // Sanctum carries claims as explicit abilities, so no shared claim
        // state can leak between the two credentials (D-73).
        $userToken = AuthTokenFactory::forUser($user);
        $registered = $this->withHeaders(['Authorization' => "Bearer $userToken"])
            ->postJson('/api/invites/redeem', [
                'token' => $invite->token,
                'accept_privacy' => true,
            ]);
        $registered->assertOk()
            ->assertCookie('rp_jwt')
            ->assertJsonPath('full_path', $gallery->full_path);

        $accessToken = (string) $registered->getCookie('rp_jwt', false)->getValue();
        $claims = AuthTokenFactory::claimsOf($accessToken);
        $this->assertSame((string) $user->id, (string) $claims['sub']);
        $this->assertSame([(string) $invite->id], $claims['transient_invite_ids']);

        $this->assertDatabaseHas('gallery_invites', ['id' => $invite->id]);
        $this->assertDatabaseMissing('users', ['email' => 'anonymous-guest@example.com']);
    }

    // --- TEST 5: Fachliche Logik: Angemeldeter User bewertet mit transientem JWT ---
    public function test_authenticated_user_with_transient_claim_can_rate_photo()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'transient-rating-'.Str::uuid(),
        ]);

        // Issue the token through the factory instead of caching a user on the
        // guard, so the request resolves the credential through
        // SanctumTransientGuard. Invite provenance is part of the claim contract:
        // transient galleries without a live invite are revalidated away (D-39).
        $transientToken = AuthTokenFactory::claims([
            'transient_galleries' => [$gallery->id],
            'transient_invite_ids' => [(string) $invite->getKey()],
            'transient_invites' => [
                (string) $invite->getKey() => [
                    'gallery_id' => (string) $gallery->getKey(),
                    'can_edit_metadata' => false,
                ],
            ],
        ])->fromUser($user);

        $rateRes = $this->withHeaders(['Authorization' => "Bearer $transientToken"])
            ->postJson("/api/photos/{$photo->id}/rate", ['rating' => 4]);

        $rateRes->assertStatus(200);

        $this->assertDatabaseHas('ratings', [
            'photo_id' => $photo->id,
            'user_id' => $user->id,
            'guest_id' => null,
            'rating' => 4,
        ]);
    }
}
