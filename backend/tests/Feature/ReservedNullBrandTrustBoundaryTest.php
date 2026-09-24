<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Org;
use App\Models\OrgInvite;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\StatsCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Factory;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Tests\TestCase;

/**
 * Regression coverage for the reserved NULL user-brand trust boundary.
 */
class ReservedNullBrandTrustBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_only_promotion_to_super_admin_sets_brand_null_atomically(): void
    {
        $superAdminRole = Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]);
        $actor = User::factory()->create(['brand' => null]);
        $actor->roles()->attach($superAdminRole);

        $target = User::factory()->create(['brand' => Brand::B2B]);
        $target->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.Auth::guard('api')->login($actor),
        ])->putJson("/api/management/users/{$target->id}", [
            'role_ids' => [$superAdminRole->id],
        ]);

        $response->assertOk();
        $this->assertNull($target->fresh()->brand);
        $this->assertTrue($target->fresh()->roles->contains($superAdminRole));
    }

    public function test_null_brand_non_super_admin_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'brand' => null,
            'password' => Hash::make('password123'),
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertForbidden();
        $response->assertJsonMissingPath('token');
    }

    public function test_null_brand_non_super_admin_cannot_reset_password(): void
    {
        $user = User::factory()->create([
            'brand' => null,
            'password' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $resetToken = 'reserved-null-reset-token';

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($resetToken),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'new-password-123',
        ]);

        $response->assertForbidden();
        $this->assertNull($user->fresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_null_brand_non_super_admin_refresh_cookie_is_denied(): void
    {
        $user = User::factory()->create(['brand' => null]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $token = Auth::guard('api')->login($user);

        $this->withUnencryptedCookie('rp_jwt_refresh', $token)
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized();
    }

    public function test_null_brand_non_super_admin_is_denied_at_management_and_gallery_authorization(): void
    {
        $user = User::factory()->create(['brand' => null]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $gallery = Gallery::factory()->create(['brand' => Brand::B2B]);

        $token = Auth::guard('api')->login($user);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/management/users')
            ->assertForbidden();

        $org = Org::factory()->create();
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->putJson("/api/management/orgs/{$org->id}", [
                'name' => 'Should Not Be Managed',
                'invoice_frequency' => 'immediate',
            ])
            ->assertForbidden();

        $orgInvite = OrgInvite::create([
            'org_id' => $org->id,
            'email' => 'legacy-null-actor@example.test',
            'token' => 'legacy-null-actor-invite',
            'expires_at' => now()->addDay(),
        ]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/org-invites/redeem', [
                'token' => $orgInvite->token,
                'accept_privacy' => true,
            ])
            ->assertForbidden();

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->isReservedNullBrandActor($user));
        $this->assertFalse($authorization->isAdmin($user));
        $this->assertFalse($authorization->canAccessGallery($user, $gallery->id));
        $this->assertFalse($authorization->canManageGallery($user, $gallery->id));
        $this->assertSame(0, app(StatsCalculationService::class)->getStatsForUser($user)['galleries_count']);

        $providerToken = Auth::guard('api')->login($user);
        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$providerToken])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_brand_bound_super_admin_remains_brand_isolated(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));
        $ownGallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $foreignGallery = Gallery::factory()->create(['brand' => 'srp']);

        $authorization = app(AuthorizationService::class);

        $this->assertTrue($authorization->canAccessGallery($user, $ownGallery->id));
        $this->assertFalse($authorization->canAccessGallery($user, $foreignGallery->id));
        $this->assertTrue($authorization->canManageGallery($user, $ownGallery->id));
        $this->assertFalse($authorization->canManageGallery($user, $foreignGallery->id));
    }

    public function test_guest_invite_access_remains_available_for_an_active_current_host_gallery(): void
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'is_public' => false,
        ]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'valid-current-host-invite',
        ]);

        $redeem = $this->postJson('/api/invites/redeem', [
            'token' => $invite->token,
            'name' => 'Invite Guest',
            'accept_privacy' => true,
        ])->assertOk();

        $token = (string) $redeem->getCookie('rp_jwt', false)->getValue();
        $this->assertNotSame('', $token);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/galleries/'.$gallery->slug)
            ->assertOk();
    }

    public function test_guest_without_active_current_host_invite_is_denied(): void
    {
        $gallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $guestId = (string) Str::uuid();
        $factory = app(Factory::class);
        $token = app(JWTAuth::class)
            ->encode($factory->customClaims([
                'sub' => 'guest_'.$guestId,
                'guest_id' => $guestId,
                'guest_name' => 'Forged Guest',
                'transient_galleries' => [$gallery->id],
            ])->make())
            ->get();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_guest_scalar_invite_provenance_can_rehydrate_its_gallery_grant(): void
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'is_public' => false,
        ]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'scalar-provenance-invite',
        ]);
        $guestId = (string) Str::uuid();
        $token = app(JWTAuth::class)->encode(app(Factory::class)->customClaims([
            'sub' => 'guest_'.$guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Scalar Provenance Guest',
            'guest_invite_id' => $invite->id,
        ])->make())->get();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/galleries/'.$gallery->slug)
            ->assertOk();
    }

    public function test_guest_metadata_claim_cannot_escalate_a_read_only_invite(): void
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'allow_client_metadata_edit' => false,
        ]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'read-only-guest-invite',
            'can_edit_metadata' => false,
        ]);
        $guestId = (string) Str::uuid();
        $token = app(JWTAuth::class)->encode(app(Factory::class)->customClaims([
            'sub' => 'guest_'.$guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Read-only Guest',
            'guest_invite_id' => $invite->id,
            'transient_galleries' => [$gallery->id],
            'transient_meta_galleries' => [$gallery->id],
        ])->make())->get();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('transient_galleries.0', $gallery->id)
            ->assertJsonPath('transient_meta_galleries', []);
    }

    public function test_guest_invite_for_foreign_host_is_rejected(): void
    {
        $gallery = Gallery::factory()->create(['brand' => 'srp']);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'foreign-host-gallery-invite',
        ]);

        $this->postJson('/api/invites/redeem', [
            'token' => $invite->token,
            'name' => 'Foreign Host Guest',
            'accept_privacy' => true,
        ])->assertNotFound();
    }

    public function test_legacy_null_brand_user_cannot_be_linked_to_a_concrete_org(): void
    {
        $actor = User::factory()->create(['brand' => null]);
        $actor->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));
        $legacyUser = User::factory()->create(['brand' => null]);
        $legacyUser->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $org = Org::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.Auth::guard('api')->login($actor),
        ])->putJson("/api/management/orgs/{$org->id}/users", [
            'user_ids' => [$legacyUser->id],
        ]);

        $response->assertStatus(422);
        $this->assertNull($legacyUser->fresh()->org_id);
    }

    public function test_brandless_org_invite_is_rejected_without_creating_a_null_brand_user(): void
    {
        $org = Org::create([
            'name' => 'Legacy Brandless Org',
            'invoice_frequency' => 'immediate',
        ]);
        $invite = OrgInvite::create([
            'org_id' => $org->id,
            'email' => 'legacy-invite@example.test',
            'token' => 'legacy-brandless-org-invite',
            'expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/org-invites/'.$invite->token)->assertNotFound();

        $response = $this->postJson('/api/org-invites/redeem', [
            'token' => $invite->token,
            'name' => 'Should Not Exist',
            'password' => 'password123',
            'accept_privacy' => true,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'legacy-invite@example.test']);
        $this->assertDatabaseHas('org_invites', ['token' => $invite->token]);
    }
}
