<?php

namespace Tests\Feature\Authorization;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\GalleryInvite;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuthorizationService;
    }

    // ──────────────────────────────────────────────
    //  Guest User
    // ──────────────────────────────────────────────

    public function test_guest_without_invite_provenance_returns_no_galleries(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->guest_id = 'guest-without-invite';
        $user->transient_galleries = ['g1', 'g2'];

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertSame([], $ids);
    }

    public function test_guest_gets_only_active_current_host_invite_gallery(): void
    {
        $gallery = Gallery::factory()->create();
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'authorization-guest-invite',
        ]);
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->guest_id = 'guest-with-invite';
        $user->transient_galleries = [$gallery->id];
        $user->transient_invites = [
            (string) $invite->id => [
                'gallery_ids' => [(string) $gallery->id],
                'meta_gallery_ids' => [],
            ],
        ];
        $user->transient_invite_ids = [(string) $invite->id];

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertSame([(string) $gallery->id], $ids);
    }

    public function test_guest_user_with_empty_transient_returns_empty(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->guest_id = 'guest-2';
        $user->transient_galleries = [];

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertSame([], $ids);
    }

    // ──────────────────────────────────────────────
    //  Registered user — invite claims are clamped to live state (AUTH-2)
    // ──────────────────────────────────────────────

    /**
     * AUTH-2: a registered-user token carries `gallery_ids` in its invite
     * record, but that is self-contained claim data. It must be clamped to the
     * live invite's real gallery, exactly like the guest branch, and
     * `can_edit_metadata` must be derived from the invite rather than the claim.
     */
    public function test_registered_invite_claim_is_clamped_to_the_live_invite_gallery(): void
    {
        $inviteGallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $foreignGallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $invite = GalleryInvite::create([
            'gallery_id' => $inviteGallery->id,
            'token' => 'registered-clamp-'.uniqid(),
            'can_edit_metadata' => false,
        ]);

        $sanitized = $this->service->sanitizeTransientClaims([
            'transient_galleries' => [$inviteGallery->id, $foreignGallery->id],
            'transient_meta_galleries' => [$inviteGallery->id, $foreignGallery->id],
            'transient_invites' => [
                (string) $invite->id => [
                    'gallery_ids' => [$inviteGallery->id, $foreignGallery->id],
                    'meta_gallery_ids' => [$inviteGallery->id, $foreignGallery->id],
                    'can_edit_metadata' => true,
                ],
            ],
            'transient_invite_ids' => [(string) $invite->id],
        ]);

        $this->assertSame([$inviteGallery->id], $sanitized['transient_galleries']);
        // The claim escalated metadata, but the live invite is read-only.
        $this->assertSame([], $sanitized['transient_meta_galleries']);
        $this->assertSame(
            [$inviteGallery->id],
            $sanitized['transient_invites'][(string) $invite->id]['gallery_ids']
        );
        $this->assertSame(
            [],
            $sanitized['transient_invites'][(string) $invite->id]['meta_gallery_ids']
        );
    }

    public function test_registered_user_cannot_gain_a_foreign_gallery_from_a_claimed_invite_record(): void
    {
        $inviteGallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $foreignGallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $invite = GalleryInvite::create([
            'gallery_id' => $inviteGallery->id,
            'token' => 'registered-foreign-'.uniqid(),
            'can_edit_metadata' => true,
        ]);

        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->transient_galleries = [$inviteGallery->id, $foreignGallery->id];
        $user->transient_meta_galleries = [$inviteGallery->id, $foreignGallery->id];
        $user->transient_invites = [
            (string) $invite->id => [
                'gallery_ids' => [$inviteGallery->id, $foreignGallery->id],
                'meta_gallery_ids' => [$inviteGallery->id, $foreignGallery->id],
            ],
        ];
        $user->transient_invite_ids = [(string) $invite->id];

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($inviteGallery->id, $ids);
        $this->assertNotContains($foreignGallery->id, $ids);
        $this->assertFalse($this->service->canAccessGallery($user, $foreignGallery->id));
        $this->assertTrue($this->service->canAccessGallery($user, $inviteGallery->id));
    }

    /**
     * Control: a registered-user invite record naming the invite's real gallery
     * still grants it, and metadata follows the live invite flag.
     */
    public function test_registered_invite_claim_for_the_real_gallery_survives_clamping(): void
    {
        $inviteGallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $invite = GalleryInvite::create([
            'gallery_id' => $inviteGallery->id,
            'token' => 'registered-valid-'.uniqid(),
            'can_edit_metadata' => true,
        ]);

        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->transient_galleries = [$inviteGallery->id];
        $user->transient_meta_galleries = [$inviteGallery->id];
        $user->transient_invites = [
            (string) $invite->id => [
                'gallery_ids' => [$inviteGallery->id],
                'meta_gallery_ids' => [$inviteGallery->id],
            ],
        ];
        $user->transient_invite_ids = [(string) $invite->id];

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($inviteGallery->id, $ids);
        $this->assertContains($inviteGallery->id, $this->service->getActiveTransientMetaGalleryIds($user));
    }

    /**
     * FINAL-6: a revocation and a host mismatch must not be conflated.
     *
     * Sanitizing for a re-issued token dropped any grant that was invisible
     * from the current host. Because the sanitized claims are then written into
     * a NEW token that outlives the request, redeeming an invite from the
     * wrong host destroyed the grant for the whole token lineage — a permanent
     * loss caused by a transient viewing-context mismatch. A revocation is a
     * real access loss and must still drop the grant.
     *
     * Coverage note: the revocation half is asserted here. The host-mismatch
     * half is currently LATENT and therefore not reproducible in a test — the
     * Brand enum holds only `B2B` (SRP was removed), so
     * `galleryTreeMatchesCurrent()` cannot disagree with the resource brand
     * yet. That branch is forward protection for when a second brand is added;
     * it must be re-verified at that point rather than assumed to work.
     */
    public function test_reissue_still_drops_a_revoked_invite_grant(): void
    {
        $activeGallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $activeInvite = GalleryInvite::create([
            'gallery_id' => $activeGallery->id,
            'token' => 'reissue-active-'.uniqid(),
        ]);
        $revokedGallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $revokedInvite = GalleryInvite::create([
            'gallery_id' => $revokedGallery->id,
            'token' => 'reissue-revoked-'.uniqid(),
        ]);
        // Revocation in this schema is the invite blacklist, not a column.
        Cache::put('blacklisted_invite_'.$revokedInvite->id, true, now()->addMinutes(60));

        $claims = [
            'transient_galleries' => [$activeGallery->id, $revokedGallery->id],
            'transient_meta_galleries' => [],
            'transient_invites' => [
                (string) $activeInvite->id => [
                    'gallery_ids' => [$activeGallery->id],
                    'meta_gallery_ids' => [],
                ],
                (string) $revokedInvite->id => [
                    'gallery_ids' => [$revokedGallery->id],
                    'meta_gallery_ids' => [],
                ],
            ],
            'transient_invite_ids' => [
                (string) $activeInvite->id,
                (string) $revokedInvite->id,
            ],
        ];

        $preserved = $this->service->sanitizeTransientClaims($claims, true);

        $this->assertContains(
            (string) $activeInvite->id,
            $preserved['transient_invite_ids'],
            'A live invite grant must survive the re-issue.',
        );
        $this->assertNotContains(
            (string) $revokedInvite->id,
            $preserved['transient_invite_ids'],
            'A revoked invite is a real access loss and must still be dropped.',
        );
        $this->assertArrayNotHasKey(
            (string) $revokedInvite->id,
            $preserved['transient_invites'],
        );
        $this->assertNotContains(
            $revokedGallery->id,
            $preserved['transient_galleries'],
        );
    }

    // ──────────────────────────────────────────────
    //  Direct Gallery Assignments
    // ──────────────────────────────────────────────

    public function test_direct_gallery_assignment(): void
    {
        $gallery = Gallery::factory()->create();
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->galleries()->attach($gallery);

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($gallery->id, $ids);
    }

    // ──────────────────────────────────────────────
    //  Gallery Group Assignments
    // ──────────────────────────────────────────────

    public function test_group_assignment_grants_access_to_group_galleries(): void
    {
        $group = GalleryGroup::factory()->create();
        $gallery = Gallery::factory()->create(['gallery_group_id' => $group->id]);
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->galleryGroups()->attach($group);

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($gallery->id, $ids);
    }

    public function test_recursive_sub_group_access(): void
    {
        $parent = GalleryGroup::factory()->create();
        $child = GalleryGroup::factory()->create(['parent_id' => $parent->id]);
        $gallery = Gallery::factory()->create(['gallery_group_id' => $child->id]);
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->galleryGroups()->attach($parent);

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($gallery->id, $ids);
    }

    // ──────────────────────────────────────────────
    //  Org Integration
    // ──────────────────────────────────────────────

    public function test_org_direct_gallery_access(): void
    {
        $org = Org::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $gallery->orgs()->attach($org->id);
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->org_id = $org->id;
        $user->save();

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($gallery->id, $ids);
    }

    public function test_org_group_gallery_access(): void
    {
        $org = Org::factory()->create();
        $group = GalleryGroup::factory()->create();
        $group->orgs()->attach($org->id);
        $gallery = Gallery::factory()->create(['gallery_group_id' => $group->id, 'type' => 'delivery']);
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->org_id = $org->id;
        $user->save();

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($gallery->id, $ids);
    }

    // ──────────────────────────────────────────────
    //  Photographer Access
    // ──────────────────────────────────────────────

    private function createPhotographer(): User
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $role = Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_photographer_accesses_unrestricted_galleries(): void
    {
        $gallery = Gallery::factory()->create();
        $user = $this->createPhotographer();

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($gallery->id, $ids);
    }

    public function test_photographer_direct_gallery_access(): void
    {
        $gallery = Gallery::factory()->create();
        $user = $this->createPhotographer();
        $user->photographerGalleries()->attach($gallery);

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($gallery->id, $ids);
    }

    public function test_photographer_group_gallery_access(): void
    {
        $group = GalleryGroup::factory()->create();
        $gallery = Gallery::factory()->create(['gallery_group_id' => $group->id]);
        $user = $this->createPhotographer();
        $user->photographerGalleryGroups()->attach($group);

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($gallery->id, $ids);
    }

    // ──────────────────────────────────────────────
    //  Brand Scoping
    // ──────────────────────────────────────────────

    public function test_brand_scoping_filters_other_brand_galleries(): void
    {
        $galleryA = Gallery::factory()->create(['brand' => 'test-brand']);
        $galleryB = Gallery::factory()->create(['brand' => 'rp']);

        $user = User::factory()->create(['brand' => 'test-brand']);
        $user->galleries()->attach([$galleryA->id, $galleryB->id]);

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($galleryA->id, $ids);
        $this->assertNotContains($galleryB->id, $ids);
    }

    public function test_brand_scoping_rejects_null_brand_galleries(): void
    {
        $gallery = Gallery::factory()->create(['brand' => null]);
        $user = User::factory()->create(['brand' => 'test-brand']);
        $user->galleries()->attach($gallery);

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertNotContains($gallery->id, $ids);
    }

    // ──────────────────────────────────────────────
    //  getSubGroupIds
    // ──────────────────────────────────────────────

    public function test_get_sub_group_ids_returns_children_recursively(): void
    {
        $parent = GalleryGroup::factory()->create();
        $child = GalleryGroup::factory()->create(['parent_id' => $parent->id]);
        $grandchild = GalleryGroup::factory()->create(['parent_id' => $child->id]);

        $ids = $this->service->getSubGroupIds([$parent->id]);

        $this->assertContains($parent->id, $ids);
        $this->assertContains($child->id, $ids);
        $this->assertContains($grandchild->id, $ids);
    }

    public function test_get_sub_group_ids_returns_empty_for_empty_input(): void
    {
        $ids = $this->service->getSubGroupIds([]);

        $this->assertSame([], $ids);
    }

    // ──────────────────────────────────────────────
    //  Cache Invalidation (S3 — Regression)
    // ──────────────────────────────────────────────

    public function test_cache_invalidates_when_restricted_photographers_changes(): void
    {
        $gallery = Gallery::factory()->create(['restricted_photographers' => false]);
        $user = $this->createPhotographer();

        // First call fills the cache
        $idsBefore = $this->service->getAllowedGalleryIds($user);
        $this->assertContains($gallery->id, $idsBefore);

        // Update the gallery to restricted
        $gallery->restricted_photographers = true;
        $gallery->save();

        // Second call should have cache invalidated
        $idsAfter = $this->service->getAllowedGalleryIds($user);
        $this->assertNotContains($gallery->id, $idsAfter);
    }

    public function test_cache_invalidates_on_gallery_create(): void
    {
        $user = $this->createPhotographer();

        // Fill cache with current state (no galleries)
        $idsBefore = $this->service->getAllowedGalleryIds($user);
        $this->assertEmpty($idsBefore);

        // Create a new unrestricted gallery
        $gallery = Gallery::factory()->create(['restricted_photographers' => false]);

        // Cache should be invalidated and include the new gallery
        $idsAfter = $this->service->getAllowedGalleryIds($user);
        $this->assertContains($gallery->id, $idsAfter);
    }

    public function test_cache_invalidates_on_gallery_delete(): void
    {
        $gallery = Gallery::factory()->create(['restricted_photographers' => false]);
        $user = $this->createPhotographer();

        // Fill cache
        $idsBefore = $this->service->getAllowedGalleryIds($user);
        $this->assertContains($gallery->id, $idsBefore);

        // Delete the gallery
        $gallery->delete();

        // Cache should be invalidated
        $idsAfter = $this->service->getAllowedGalleryIds($user);
        $this->assertNotContains($gallery->id, $idsAfter);
    }

    // ──────────────────────────────────────────────
    //  Role Predicates
    // ──────────────────────────────────────────────

    private function assignRole(User $user, UserRole $role): Role
    {
        $roleModel = Role::firstOrCreate(['name' => $role->value]);
        $user->roles()->syncWithoutDetaching([$roleModel->id]);
        if ($role === UserRole::SUPER_ADMIN) {
            $user->forceFill(['brand' => null])->save();
        }

        return $roleModel;
    }

    public function test_has_role_returns_true_when_user_holds_role(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::ADMIN);

        $this->assertTrue($this->service->hasRole($user, 'admin'));
    }

    public function test_has_role_returns_false_when_user_does_not_hold_role(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::ADMIN);

        $this->assertFalse($this->service->hasRole($user, 'photographer'));
    }

    public function test_has_role_matches_any_of_multiple_roles(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);

        $this->assertTrue($this->service->hasRole($user, 'admin', 'photographer'));
        $this->assertFalse($this->service->hasRole($user, 'admin', 'power_user'));
    }

    public function test_role_names_returns_assigned_role_names(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::ADMIN);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);

        $this->assertEqualsCanonicalizing(['admin', 'photographer'], $this->service->roleNames($user));
    }

    public function test_role_names_returns_empty_for_user_without_roles(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);

        $this->assertSame([], $this->service->roleNames($user));
    }

    public function test_is_super_admin_true_when_role_assigned(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::SUPER_ADMIN);

        $this->assertTrue($this->service->isSuperAdmin($user));
    }

    public function test_is_super_admin_false_without_role(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);

        $this->assertFalse($this->service->isSuperAdmin($user));
    }

    public function test_is_admin_true_for_admin_and_super_admin(): void
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($admin, UserRole::ADMIN);

        $superAdmin = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($superAdmin, UserRole::SUPER_ADMIN);

        $this->assertTrue($this->service->isAdmin($admin));
        $this->assertTrue($this->service->isAdmin($superAdmin));
    }

    public function test_is_admin_false_without_admin_role(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);

        $this->assertFalse($this->service->isAdmin($user));
    }

    public function test_is_photographer_true_when_role_assigned(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);

        $this->assertTrue($this->service->isPhotographer($user));
    }

    public function test_is_photographer_false_without_role(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::ADMIN);

        $this->assertFalse($this->service->isPhotographer($user));
    }

    public function test_is_power_user_true_when_role_assigned(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::POWER_USER);

        $this->assertTrue($this->service->isPowerUser($user));
    }

    public function test_is_power_user_false_without_role(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);

        $this->assertFalse($this->service->isPowerUser($user));
    }

    public function test_is_org_admin_true_with_role_and_org(): void
    {
        $org = Org::factory()->create();
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::ORG_ADMIN);
        $user->org_id = $org->id;
        $user->save();

        $this->assertTrue($this->service->isOrgAdmin($user));
    }

    public function test_is_org_admin_false_with_role_but_without_org(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::ORG_ADMIN);

        $this->assertFalse($this->service->isOrgAdmin($user));
    }

    public function test_is_org_admin_false_with_org_but_without_role(): void
    {
        $org = Org::factory()->create();
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->org_id = $org->id;
        $user->save();

        $this->assertFalse($this->service->isOrgAdmin($user));
    }

    public function test_is_pending_true_for_plain_user(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);

        $this->assertTrue($this->service->isPending($user));
    }

    public function test_is_pending_false_for_guest(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->guest_id = 'guest-pending';
        $user->transient_galleries = [];

        $this->assertFalse($this->service->isPending($user));
    }

    public function test_is_pending_false_when_role_assigned(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::CLIENT);

        $this->assertFalse($this->service->isPending($user));
    }

    public function test_is_pending_false_when_gallery_group_assigned(): void
    {
        $group = GalleryGroup::factory()->create();
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->galleryGroups()->attach($group);

        $this->assertFalse($this->service->isPending($user));
    }

    public function test_is_pending_false_when_gallery_assigned(): void
    {
        $gallery = Gallery::factory()->create();
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->galleries()->attach($gallery);

        $this->assertFalse($this->service->isPending($user));
    }

    // ──────────────────────────────────────────────
    //  canAccessGallery() Precedence
    // ──────────────────────────────────────────────

    public function test_can_access_gallery_super_admin_true_for_any_gallery(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::SUPER_ADMIN);
        $gallery = Gallery::factory()->create();

        $this->assertTrue($this->service->canAccessGallery($user, $gallery->id));
    }

    public function test_can_access_gallery_super_admin_true_for_nonexistent_gallery(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::SUPER_ADMIN);

        $this->assertTrue($this->service->canAccessGallery($user, 'nonexistent-gallery-id'));
    }

    public function test_can_access_gallery_photographer_with_unrestricted_gallery_true(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create();

        $this->assertTrue($this->service->canAccessGallery($user, $gallery->id));
    }

    public function test_can_access_gallery_photographer_restricted_without_right_false(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);

        $this->assertFalse($this->service->canAccessGallery($user, $gallery->id));
    }

    public function test_can_access_gallery_user_with_direct_right_true(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $gallery = Gallery::factory()->create();
        $user->galleries()->attach($gallery->id);

        $this->assertTrue($this->service->canAccessGallery($user, $gallery->id));
    }

    public function test_can_access_gallery_user_without_right_false(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $gallery = Gallery::factory()->create();

        $this->assertFalse($this->service->canAccessGallery($user, $gallery->id));
    }

    // ──────────────────────────────────────────────
    //  canPhotographerAccessGallery() with restricted_photographers
    // ──────────────────────────────────────────────

    public function test_can_photographer_access_gallery_super_admin_true(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::SUPER_ADMIN);
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);

        $this->assertTrue($this->service->canPhotographerAccessGallery($user, $gallery->id));
    }

    public function test_can_photographer_access_gallery_non_photographer_false(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::ADMIN);
        $gallery = Gallery::factory()->create();

        $this->assertFalse($this->service->canPhotographerAccessGallery($user, $gallery->id));
    }

    public function test_can_photographer_access_gallery_unrestricted_true(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create(['restricted_photographers' => false]);

        $this->assertTrue($this->service->canPhotographerAccessGallery($user, $gallery->id));
    }

    public function test_can_photographer_access_gallery_restricted_without_assignment_false(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);

        $this->assertFalse($this->service->canPhotographerAccessGallery($user, $gallery->id));
    }

    public function test_can_photographer_access_gallery_restricted_with_direct_assignment_true(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);
        $user->photographerGalleries()->attach($gallery->id);

        $this->assertTrue($this->service->canPhotographerAccessGallery($user, $gallery->id));
    }

    public function test_can_photographer_access_gallery_restricted_via_group_true(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $group = GalleryGroup::factory()->create();
        $gallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id,
            'restricted_photographers' => true,
        ]);
        $user->photographerGalleryGroups()->attach($group->id);

        $this->assertTrue($this->service->canPhotographerAccessGallery($user, $gallery->id));
    }

    // ──────────────────────────────────────────────
    //  canManageGallery() Composite (mirrors GalleryPolicy::manage)
    // ──────────────────────────────────────────────

    public function test_can_manage_gallery_super_admin_true(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::SUPER_ADMIN);
        $gallery = Gallery::factory()->create();

        $this->assertTrue($this->service->canManageGallery($user, $gallery->id));
    }

    public function test_can_manage_gallery_admin_true(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::ADMIN);
        $gallery = Gallery::factory()->create();

        $this->assertTrue($this->service->canManageGallery($user, $gallery->id));
    }

    public function test_can_manage_gallery_photographer_unrestricted_true(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create();

        $this->assertTrue($this->service->canManageGallery($user, $gallery->id));
    }

    public function test_can_manage_gallery_photographer_restricted_without_right_false(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);

        $this->assertFalse($this->service->canManageGallery($user, $gallery->id));
    }

    public function test_can_manage_gallery_plain_user_false(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $gallery = Gallery::factory()->create();

        $this->assertFalse($this->service->canManageGallery($user, $gallery->id));
    }
}
