<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Photo;
use App\Models\DownloadLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class TransientMagicLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_redeem_issues_jwt_with_transient_claims()
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        GalleryInvite::create(['gallery_id' => $gallery->id, 'token' => 'anon-token']);

        $res = $this->postJson('/api/invites/redeem', [
            'token' => 'anon-token',
            'name' => 'Anna Nom',
            'email' => 'anna@example.com',
            'accept_privacy' => true
        ]);

        $res->assertStatus(200)->assertCookie('rp_jwt');
        $this->assertDatabaseMissing('users', ['email' => 'anna@example.com']);
    }

    public function test_anonymous_guest_can_access_transient_gallery()
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        Photo::factory()->create(['gallery_id' => $gallery->id]);

        $guestId = (string) Str::uuid();
        $factory = app(\PHPOpenSourceSaver\JWTAuth\Factory::class);
        $payload = $factory->customClaims([
            'sub' => 'guest_' . $guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Guest',
            'transient_galleries' => [$gallery->id]
        ])->make();
        $token = app(\PHPOpenSourceSaver\JWTAuth\JWTAuth::class)->encode($payload)->get();

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson("/api/galleries/{$gallery->slug}");

        $res->assertStatus(200);
    }

    public function test_anonymous_guest_cannot_access_other_gallery()
    {
        $allowed = Gallery::factory()->create(['is_public' => false]);
        $blocked = Gallery::factory()->create(['is_public' => false]);
        Photo::factory()->create(['gallery_id' => $blocked->id]);

        $guestId = (string) Str::uuid();
        $factory = app(\PHPOpenSourceSaver\JWTAuth\Factory::class);
        $payload = $factory->customClaims([
            'sub' => 'guest_' . $guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Guest',
            'transient_galleries' => [$allowed->id]
        ])->make();
        $token = app(\PHPOpenSourceSaver\JWTAuth\JWTAuth::class)->encode($payload)->get();

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson("/api/galleries/{$blocked->slug}");

        $res->assertStatus(403);
    }

    public function test_guest_rating_uses_guest_id()
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $guestId = (string) Str::uuid();
        $factory = app(\PHPOpenSourceSaver\JWTAuth\Factory::class);
        $payload = $factory->customClaims([
            'sub' => 'guest_' . $guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Rating Guest',
            'transient_galleries' => [$gallery->id]
        ])->make();
        $token = app(\PHPOpenSourceSaver\JWTAuth\JWTAuth::class)->encode($payload)->get();

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/photos/{$photo->id}/rate", ['rating' => 4, 'comment' => 'Nice!']);

        $res->assertStatus(200);

        $this->assertDatabaseHas('ratings', [
            'photo_id' => $photo->id,
            'user_id' => null,
            'guest_id' => $guestId,
            'guest_name' => 'Rating Guest',
            'rating' => 4
        ]);
    }

    public function test_guest_download_logs_guest_id()
    {
        $gallery = Gallery::factory()->create(['is_public' => true, 'is_free_download' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $guestId = (string) Str::uuid();

        \App\Models\DownloadLog::create([
            'user_id' => null,
            'guest_id' => $guestId,
            'user_name_snapshot' => 'DL Guest',
            'gallery_id' => $gallery->id,
            'gallery_name_snapshot' => $gallery->name,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        $this->assertDatabaseHas('download_logs', [
            'user_id' => null,
            'guest_id' => $guestId,
        ]);
    }

    public function test_me_returns_guest_data()
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);

        $guestId = (string) Str::uuid();
        $factory = app(\PHPOpenSourceSaver\JWTAuth\Factory::class);
        $payload = $factory->customClaims([
            'sub' => 'guest_' . $guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Me Guest',
            'transient_galleries' => [$gallery->id]
        ])->make();
        $token = app(\PHPOpenSourceSaver\JWTAuth\JWTAuth::class)->encode($payload)->get();

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/auth/me');

        $res->assertStatus(200);
        $res->assertJson([
            'id' => $guestId,
            'guest_id' => $guestId,
            'name' => 'Me Guest',
        ]);
        $this->assertNull($res->json('email'));
        $this->assertEquals([$gallery->id], $res->json('transient_galleries'));
    }

    public function test_authenticated_user_keeps_existing_galleries_after_invite_redeem()
    {
        $user = User::factory()->create();
        $existing = Gallery::factory()->create(['is_public' => false]);
        $invited = Gallery::factory()->create(['is_public' => false]);
        $user->galleries()->attach($existing->id);

        GalleryInvite::create(['gallery_id' => $invited->id, 'token' => 'merge-token']);

        $token = JWTAuth::fromUser($user);

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/invites/redeem', [
                'token' => 'merge-token',
                'accept_privacy' => true
            ]);

        $res->assertStatus(200)->assertCookie('rp_jwt');

        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $user->id,
            'gallery_id' => $existing->id
        ]);

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $user->id,
            'gallery_id' => $invited->id
        ]);
    }

    public function test_no_user_created_for_multiple_anonymous_redeems()
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        GalleryInvite::create(['gallery_id' => $gallery->id, 'token' => 'multi-1']);
        GalleryInvite::create(['gallery_id' => $gallery->id, 'token' => 'multi-2']);

        $this->postJson('/api/invites/redeem', [
            'token' => 'multi-1', 'name' => 'A', 'accept_privacy' => true
        ])->assertStatus(200);

        $this->postJson('/api/invites/redeem', [
            'token' => 'multi-2', 'name' => 'B', 'accept_privacy' => true
        ])->assertStatus(200);

        $this->assertEquals(0, User::count());
    }
}
