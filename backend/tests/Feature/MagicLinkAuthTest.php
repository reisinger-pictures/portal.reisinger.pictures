<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
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
            'accept_privacy' => true
        ]);

        $res->assertStatus(200)->assertCookie('rp_jwt');
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);
    }

    // --- TEST 2: Fachliche Logik: Gast bewertet mit transientem JWT ---
    public function test_anonymous_guest_with_transient_jwt_can_rate_photo()
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        // Wir fälschen uns das JWT für den Test, genau so wie der Server es ausstellen würde
        $guestId = (string)Str::uuid();
        $factory = app(\PHPOpenSourceSaver\JWTAuth\Factory::class);
        $payload = $factory->customClaims([
            'sub' => 'guest_' . $guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Test Guest',
            'transient_galleries' => [$gallery->id]
        ])->make();
        $token = app(\PHPOpenSourceSaver\JWTAuth\JWTAuth::class)->encode($payload)->get();

        // Wir senden das Token als Bearer-Header, was JWT-Auth standardmäßig unterstützt
        $rateRes = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/photos/{$photo->id}/rate", ['rating' => 5]);

        $rateRes->assertStatus(200);

        $this->assertDatabaseHas('ratings', [
            'photo_id' => $photo->id,
            'user_id' => null,
            'guest_name' => 'Test Guest',
            'rating' => 5
        ]);
    }

    // --- TEST 3: Einlösen durch angemeldeten User (Erzeugt neues, gemergtes Cookie) ---
    public function test_authenticated_user_can_redeem_invite_and_receive_merged_cookie()
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        $invite = GalleryInvite::create(['gallery_id' => $gallery->id, 'token' => 'test-token-2']);

        $initialToken = JWTAuth::fromUser($user);

        $res = $this->withHeaders(['Authorization' => "Bearer $initialToken"])
            ->postJson('/api/invites/redeem', [
                'token' => 'test-token-2',
                'accept_privacy' => true
            ]);

        $res->assertStatus(200)->assertCookie('rp_jwt');
    }

    // --- TEST 4: Fachliche Logik: Angemeldeter User bewertet mit transientem JWT ---
    public function test_authenticated_user_with_transient_claim_can_rate_photo()
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        // FIX: Token über die Fassade generieren, anstatt `login($user)` aufzurufen.
        // Das verhindert, dass der Test-User im Speicher gecached wird, und zwingt
        // den anschließenden Request durch unseren TransientUserProvider!
        $transientToken = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::claims(['transient_galleries' => [$gallery->id]])
            ->fromUser($user);

        $rateRes = $this->withHeaders(['Authorization' => "Bearer $transientToken"])
            ->postJson("/api/photos/{$photo->id}/rate", ['rating' => 4]);

        $rateRes->assertStatus(200);

        $this->assertDatabaseHas('ratings', [
            'photo_id' => $photo->id,
            'user_id' => $user->id,
            'guest_id' => null,
            'rating' => 4
        ]);
    }
}
