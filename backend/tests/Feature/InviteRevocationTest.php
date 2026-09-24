<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPOpenSourceSaver\JWTAuth\JWTAuth as JwtAuthManager;
use Tests\TestCase;

class InviteRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['scout.driver' => 'null']);
        BrandRegistry::clearCache();
        BrandRegistry::set(Brand::B2B);
        Storage::set('photos', Storage::build([
            'driver' => 'local',
            'root' => storage_path(
                'framework/testing/disks/invite-photos-'.(string) Str::uuid(),
            ),
            'throw' => false,
        ]));
    }

    public function test_registered_invite_grants_are_revoked_after_refresh_but_direct_same_brand_access_remains(): void
    {
        [$admin, $adminToken] = $this->createAdmin();

        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'can_edit_metadata' => true,
        ]);

        $invitedGallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => false,
            'allow_client_metadata_edit' => true,
        ]);
        $invitedPhoto = Photo::factory()->create([
            'gallery_id' => $invitedGallery->id,
            'title' => 'Original title',
        ]);

        // This is independent, legitimate access and must survive revocation
        // of the invite grant.
        $directGallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => false,
            'allow_client_metadata_edit' => true,
        ]);
        $directPhoto = Photo::factory()->create([
            'gallery_id' => $directGallery->id,
            'title' => 'Direct title',
        ]);
        $user->galleries()->attach($directGallery->id);

        $invite = GalleryInvite::create([
            'gallery_id' => $invitedGallery->id,
            'token' => 'registered-revoke-token',
            'can_edit_metadata' => true,
        ]);

        $userToken = Auth::guard('api')->login($user);
        $redeem = $this->asToken($userToken)
            ->postJson('/api/invites/redeem', [
                'token' => $invite->token,
                'accept_privacy' => true,
            ]);

        $redeem->assertOk();
        $accessToken = (string) $redeem->getCookie('rp_jwt', false)->getValue();
        $refreshToken = (string) $redeem->getCookie('rp_jwt_refresh', false)->getValue();
        $this->assertNotSame('', $accessToken);
        $this->assertNotSame('', $refreshToken);

        $payload = app(JWT::class)->setToken($accessToken)->getPayload();
        $this->assertSame(
            [(string) $invite->id],
            $payload->get('transient_invite_ids')
        );
        $this->assertSame(
            [(string) $invitedGallery->id],
            $payload->get('transient_invites')[(string) $invite->id]['gallery_ids']
        );

        // Legitimate access exists before revocation.
        $this->asToken($accessToken)
            ->getJson("/api/galleries/{$invitedGallery->slug}")
            ->assertOk();
        $this->asToken($accessToken)
            ->putJson("/api/photos/{$invitedPhoto->id}/meta", [
                'title' => 'Edited through invite',
            ])
            ->assertOk();

        // The transient claims must also survive a normal refresh. Otherwise
        // the revocation regression could pass merely because refresh drops
        // access for everyone.
        $refresh = $this->asRefreshToken($refreshToken)
            ->postJson('/api/auth/refresh');
        $refresh->assertOk();
        $refreshedAccessToken = (string) $refresh->getCookie('rp_jwt', false)->getValue();
        $refreshedRefreshToken = (string) $refresh->getCookie('rp_jwt_refresh', false)->getValue();

        $this->asToken($refreshedAccessToken)
            ->getJson("/api/galleries/{$invitedGallery->slug}")
            ->assertOk();
        $this->asToken($refreshedAccessToken)
            ->putJson("/api/photos/{$invitedPhoto->id}/meta", [
                'title' => 'Edited after refresh',
            ])
            ->assertOk();

        $revoke = $this->asToken($adminToken)
            ->deleteJson("/api/management/invites/{$invite->id}");
        $revoke->assertOk();
        $this->assertDatabaseMissing('gallery_invites', ['id' => $invite->id]);
        $this->assertTrue(Cache::has('blacklisted_invite_'.$invite->id));

        // The already-issued access token loses only the revoked grant.
        $this->asToken($refreshedAccessToken)
            ->getJson("/api/galleries/{$invitedGallery->slug}")
            ->assertStatus(403);

        // Refresh after revocation must not resurrect the deleted invite's
        // grants. The registered account remains valid, so refresh itself is
        // allowed; the transient-only access is removed on the next request.
        $refreshAfterRevoke = $this->asRefreshToken($refreshedRefreshToken)
            ->postJson('/api/auth/refresh');
        $refreshAfterRevoke->assertOk();
        $accessAfterRevokeRefresh = (string) $refreshAfterRevoke->getCookie('rp_jwt', false)->getValue();

        $this->asToken($accessAfterRevokeRefresh)
            ->getJson("/api/galleries/{$invitedGallery->slug}")
            ->assertStatus(403);
        $this->asToken($accessAfterRevokeRefresh)
            ->putJson("/api/photos/{$invitedPhoto->id}/meta", [
                'title' => 'Must not be written',
            ])
            ->assertStatus(403);

        $invitedPhoto->refresh();
        $this->assertSame('Edited after refresh', $invitedPhoto->title);

        // Revoking the invite must not log out the account or remove a
        // separately assigned, same-brand gallery/metadata grant.
        $this->asToken($accessAfterRevokeRefresh)
            ->getJson("/api/galleries/{$directGallery->slug}")
            ->assertOk();
        $this->asToken($accessAfterRevokeRefresh)
            ->putJson("/api/photos/{$directPhoto->id}/meta", [
                'title' => 'Direct edit remains valid',
            ])
            ->assertOk();

        $this->asToken($accessAfterRevokeRefresh)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('transient_galleries', [])
            ->assertJsonPath('transient_meta_galleries', []);
    }

    public function test_direct_invite_delete_invalidates_registered_claims_without_a_cache_marker(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => false,
        ]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'direct-delete-token',
            'can_edit_metadata' => true,
        ]);

        $token = JWTAuth::claims([
            'transient_galleries' => [$gallery->id],
            'transient_meta_galleries' => [$gallery->id],
            'transient_invites' => [
                (string) $invite->id => [
                    'gallery_id' => (string) $gallery->id,
                    'can_edit_metadata' => true,
                ],
            ],
            'transient_invite_ids' => [(string) $invite->id],
        ])->fromUser($user);

        // Simulate a delete that bypasses the controller (and therefore its
        // fast-path cache marker). The database check must still fail closed.
        $invite->delete();
        Cache::forget('blacklisted_invite_'.$invite->id);

        $this->asToken($token)
            ->getJson("/api/galleries/{$gallery->slug}")
            ->assertStatus(403);
    }

    private function asToken(string $token): self
    {
        $this->resetJwtState();

        return $this->withToken($token);
    }

    private function asRefreshToken(string $token): self
    {
        $this->resetJwtState();

        return $this->withUnencryptedCookie('rp_jwt_refresh', $token)->withCredentials();
    }

    private function resetJwtState(): void
    {
        Auth::forgetGuards();
        app(JWT::class)->unsetToken();
        app(JwtAuthManager::class)->unsetToken();
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createAdmin(): array
    {
        $admin = User::factory()->create(['brand' => null]);
        $role = Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]);
        $admin->roles()->attach($role->id);

        return [$admin, Auth::guard('api')->login($admin)];
    }
}
