<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The delegation boundary for an organization administrator
 * (SEC-20261018-01/02/03).
 *
 * An org admin is the manager of *one customer account*. Three things follow
 * from that and are pinned here:
 *
 * - membership: they may add an unassigned user or remove a member, but they
 *   may not pull somebody out of another organization and may not add or remove
 *   an account whose highest role outranks their own (otherwise reassignment
 *   would hand them `UserController::update()`/`destroy()` on an admin),
 * - resources: galleries and groups they attach to a user or to the org must
 *   already be inside the org's delegable scope, the same scope
 *   `AuthorizationService::getAllowedGalleryIds()` resolves for a member,
 * - roles: only the org-domain roles (client, power_user, org_admin) may be
 *   granted or revoked, and nobody but a Super Admin changes their own roles.
 *
 * Every rejection is asserted through database state, and the gallery findings
 * additionally through the reader, because "the write returned 403" alone does
 * not prove the boundary was applied.
 */
class OrgAdminDelegationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const BRAND = 'rp';

    private function role(UserRole $role): Role
    {
        return Role::firstOrCreate(['name' => $role->value]);
    }

    private function org(): Org
    {
        return Org::factory()->create(['brand' => self::BRAND, 'invoice_frequency' => 'immediate']);
    }

    private function orgAdmin(Org $org): User
    {
        $user = User::factory()->create([
            'brand' => self::BRAND,
            'org_id' => $org->id,
        ]);
        $user->roles()->attach($this->role(UserRole::ORG_ADMIN));

        return $user;
    }

    /**
     * @param  array<int, UserRole>  $roles
     */
    private function userIn(?Org $org, array $roles = []): User
    {
        $user = User::factory()->create([
            'brand' => self::BRAND,
            'org_id' => $org?->id,
        ]);

        foreach ($roles as $role) {
            $user->roles()->attach($this->role($role));
        }

        return $user;
    }

    private function as(User $user)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)]);
    }

    private function readerIds(User $user): array
    {
        return app(AuthorizationService::class)->getAllowedGalleryIds($user->fresh());
    }

    /**
     * Gallery fixtures must not be pushed to a live Meilisearch. The Searchable
     * trait would otherwise make every fixture write a network call, which is
     * not what these authorization tests are about.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function gallery(array $attributes = []): Gallery
    {
        return Gallery::withoutSyncingToSearch(
            fn (): Gallery => Gallery::factory()->create($attributes),
        );
    }

    // ── SEC-20261018-01: membership ──────────────────────────────────────────

    public function test_org_admin_cannot_import_a_user_from_another_org(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $adminA = $this->orgAdmin($orgA);
        $victim = $this->userIn($orgB, [UserRole::CLIENT]);

        $this->as($adminA)
            ->putJson("/api/management/orgs/{$orgA->id}/users", ['user_ids' => [$victim->id]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Benutzer aus einer anderen Organisation können nicht übernommen werden.');

        $this->assertDatabaseHas('users', ['id' => $victim->id, 'org_id' => $orgB->id]);

        // The second half of the attack path: even if the import had silently
        // happened, the victim must not become editable through org isolation.
        $this->as($adminA)
            ->putJson("/api/management/users/{$victim->id}", ['flatrate_level' => 'web'])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $victim->id, 'flatrate_level' => 'none']);
    }

    public function test_a_rejected_import_leaves_the_legitimate_part_of_the_request_unmoved(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $adminA = $this->orgAdmin($orgA);
        $valid = $this->userIn(null, [UserRole::CLIENT]);
        $foreign = $this->userIn($orgB, [UserRole::CLIENT]);

        $this->as($adminA)
            ->putJson("/api/management/orgs/{$orgA->id}/users", [
                'user_ids' => [$valid->id, $foreign->id],
            ])
            ->assertStatus(422);

        // Validation runs before the transaction, so not even the valid id of a
        // rejected sync may move. A partial write here would be the failure the
        // transaction and the pre-checks exist to prevent.
        $this->assertDatabaseHas('users', ['id' => $valid->id, 'org_id' => null]);
        $this->assertDatabaseHas('users', ['id' => $foreign->id, 'org_id' => $orgB->id]);
    }

    public function test_org_admin_cannot_import_an_admin(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $admin = $this->userIn(null, [UserRole::ADMIN]);

        $this->as($orgAdmin)
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$admin->id]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Benutzer mit einer höheren Berechtigungsstufe können nicht hinzugefügt werden.');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'org_id' => null]);
    }

    public function test_org_admin_cannot_remove_an_admin_from_their_org(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $admin = $this->userIn($org, [UserRole::ADMIN]);

        $this->as($orgAdmin)
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => []])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Benutzer mit einer höheren Berechtigungsstufe können nicht entfernt werden.');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'org_id' => $org->id]);
    }

    public function test_org_admin_may_keep_an_admin_member_they_cannot_remove(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $admin = $this->userIn($org, [UserRole::ADMIN]);
        $client = $this->userIn(null, [UserRole::CLIENT]);

        // The admin is neither imported nor detached by this request, so the
        // rank ceiling must not reject the entire sync. Only a change to their
        // membership is blocked (previous test).
        $this->as($orgAdmin)
            ->putJson("/api/management/orgs/{$org->id}/users", [
                'user_ids' => [$admin->id, $client->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'org_id' => $org->id]);
        $this->assertDatabaseHas('users', ['id' => $client->id, 'org_id' => $org->id]);
    }

    public function test_org_admin_can_attach_an_unassigned_client_and_detach_them(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $client = $this->userIn(null, [UserRole::CLIENT]);

        $this->as($orgAdmin)
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$client->id]])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $client->id, 'org_id' => $org->id]);

        $this->as($orgAdmin)
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => []])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $client->id, 'org_id' => null]);
    }

    public function test_admin_can_still_move_a_user_out_of_another_org(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $admin = $this->userIn(null, [UserRole::ADMIN]);
        $victim = $this->userIn($orgB, [UserRole::CLIENT]);

        $this->as($admin)
            ->putJson("/api/management/orgs/{$orgA->id}/users", ['user_ids' => [$victim->id]])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $victim->id, 'org_id' => $orgA->id]);
    }

    // ── SEC-20261018-02: galleries and groups ────────────────────────────────

    public function test_org_admin_cannot_assign_a_gallery_outside_the_org_scope(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $target = $this->userIn($org, [UserRole::CLIENT]);
        $foreignGallery = $this->gallery(['brand' => self::BRAND]);

        $this->as($orgAdmin)
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [$foreignGallery->id],
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'Es dürfen nur Galerien der eigenen Organisation zugewiesen werden.');

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $foreignGallery->id,
        ]);
        $this->assertNotContains($foreignGallery->id, $this->readerIds($target));
    }

    public function test_org_admin_cannot_assign_a_gallery_group_outside_the_org_scope(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $target = $this->userIn($org, [UserRole::CLIENT]);
        $group = GalleryGroup::factory()->create(['brand' => self::BRAND]);
        $gallery = $this->gallery([
            'brand' => self::BRAND,
            'gallery_group_id' => $group->id,
        ]);

        $this->as($orgAdmin)
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_group_ids' => [$group->id],
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'Es dürfen nur Galerie-Gruppen der eigenen Organisation zugewiesen werden.');

        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $target->id,
            'gallery_group_id' => $group->id,
        ]);
        $this->assertNotContains($gallery->id, $this->readerIds($target));
    }

    public function test_org_admin_can_assign_galleries_inside_the_org_scope(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $target = $this->userIn($org, [UserRole::CLIENT]);

        // Direct gallery attached to the org via `gallery_org`.
        $direct = $this->gallery(['brand' => self::BRAND]);
        $direct->orgs()->attach($org);

        // Group attached via `gallery_group_org`, with a descendant group.
        $group = GalleryGroup::factory()->create(['brand' => self::BRAND]);
        $org->galleryGroups()->attach($group);
        $child = GalleryGroup::factory()->create(['brand' => self::BRAND, 'parent_id' => $group->id]);
        $groupGallery = $this->gallery([
            'brand' => self::BRAND,
            'gallery_group_id' => $child->id,
        ]);

        $this->as($orgAdmin)
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [$direct->id],
                'gallery_group_ids' => [$child->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $direct->id,
        ]);
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $target->id,
            'gallery_group_id' => $child->id,
        ]);

        $reader = $this->readerIds($target);
        $this->assertContains($direct->id, $reader);
        $this->assertContains($groupGallery->id, $reader);
    }

    public function test_org_admin_cannot_attach_a_group_outside_the_org_scope(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $member = $this->userIn($org, [UserRole::CLIENT]);
        $group = GalleryGroup::factory()->create(['brand' => self::BRAND]);
        $gallery = $this->gallery([
            'brand' => self::BRAND,
            'gallery_group_id' => $group->id,
        ]);

        $this->as($orgAdmin)
            ->putJson("/api/management/orgs/{$org->id}/groups", ['group_ids' => [$group->id]])
            ->assertForbidden()
            ->assertJsonPath('error', 'Es dürfen nur Galerie-Gruppen zugewiesen werden, die bereits zur Organisation gehören.');

        $this->assertDatabaseMissing('gallery_group_org', [
            'org_id' => $org->id,
            'gallery_group_id' => $group->id,
        ]);
        $this->assertNotContains($gallery->id, $this->readerIds($member));
    }

    public function test_org_admin_may_detach_an_assigned_group(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $group = GalleryGroup::factory()->create(['brand' => self::BRAND]);
        $org->galleryGroups()->attach($group);

        $this->as($orgAdmin)
            ->putJson("/api/management/orgs/{$org->id}/groups", ['group_ids' => []])
            ->assertOk();

        $this->assertDatabaseMissing('gallery_group_org', [
            'org_id' => $org->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_admin_may_attach_a_group_outside_the_existing_scope(): void
    {
        $org = $this->org();
        $admin = $this->userIn(null, [UserRole::ADMIN]);
        $member = $this->userIn($org, [UserRole::CLIENT]);
        $group = GalleryGroup::factory()->create(['brand' => self::BRAND]);
        $gallery = $this->gallery([
            'brand' => self::BRAND,
            'gallery_group_id' => $group->id,
        ]);

        $this->as($admin)
            ->putJson("/api/management/orgs/{$org->id}/groups", ['group_ids' => [$group->id]])
            ->assertOk();

        $this->assertDatabaseHas('gallery_group_org', [
            'org_id' => $org->id,
            'gallery_group_id' => $group->id,
        ]);
        $this->assertContains($gallery->id, $this->readerIds($member));
    }

    public function test_org_scope_is_the_same_resolution_the_reader_uses(): void
    {
        $org = $this->org();
        $member = $this->userIn($org, [UserRole::CLIENT]);

        $direct = $this->gallery(['brand' => self::BRAND, 'type' => 'delivery']);
        $direct->orgs()->attach($org);

        // A selection gallery attached to the org is not part of the reader's
        // org integration — and therefore not delegable either.
        $selection = $this->gallery(['brand' => self::BRAND, 'type' => 'selection']);
        $selection->orgs()->attach($org);

        $group = GalleryGroup::factory()->create(['brand' => self::BRAND]);
        $org->galleryGroups()->attach($group);
        $child = GalleryGroup::factory()->create(['brand' => self::BRAND, 'parent_id' => $group->id]);
        $groupGallery = $this->gallery([
            'brand' => self::BRAND,
            'gallery_group_id' => $child->id,
        ]);

        $unrelatedGroup = GalleryGroup::factory()->create(['brand' => self::BRAND]);
        $unrelatedGallery = $this->gallery([
            'brand' => self::BRAND,
            'gallery_group_id' => $unrelatedGroup->id,
        ]);

        $scope = app(AuthorizationService::class)->getOrgGalleryScope($org);
        sort($scope);
        $reader = $this->readerIds($member);
        sort($reader);

        $this->assertSame($scope, $reader);
        $this->assertContains($direct->id, $scope);
        $this->assertContains($groupGallery->id, $scope);
        $this->assertNotContains($selection->id, $scope);
        $this->assertNotContains($unrelatedGallery->id, $scope);
    }

    // ── SEC-20261018-03: role assignment ─────────────────────────────────────

    public function test_org_admin_cannot_change_their_own_roles(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $photographer = $this->role(UserRole::PHOTOGRAPHER);

        $this->as($orgAdmin)
            ->putJson("/api/management/users/{$orgAdmin->id}", [
                'role_ids' => [$photographer->id],
                'brand' => self::BRAND,
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'Die eigenen Rollen können nicht über die Benutzerverwaltung geändert werden.');

        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $orgAdmin->id,
            'role_id' => $photographer->id,
        ]);
        $this->assertTrue(
            $orgAdmin->fresh()->roles->pluck('name')->contains(UserRole::ORG_ADMIN->value),
        );
    }

    public function test_org_admin_cannot_assign_the_photographer_role(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $target = $this->userIn($org, [UserRole::CLIENT]);
        $photographer = $this->role(UserRole::PHOTOGRAPHER);

        $this->as($orgAdmin)
            ->putJson("/api/management/users/{$target->id}", [
                'role_ids' => [$photographer->id],
                'brand' => self::BRAND,
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'Es dürfen nur die Rollen der eigenen Organisation (client, power_user, org_admin) vergeben werden.');

        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $target->id,
            'role_id' => $photographer->id,
        ]);
    }

    public function test_org_admin_can_assign_the_org_domain_roles(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $target = $this->userIn($org, [UserRole::CLIENT]);

        $client = $this->role(UserRole::CLIENT);
        $powerUser = $this->role(UserRole::POWER_USER);
        $orgAdminRole = $this->role(UserRole::ORG_ADMIN);

        $this->as($orgAdmin)
            ->putJson("/api/management/users/{$target->id}", [
                'role_ids' => [$client->id, $powerUser->id, $orgAdminRole->id],
                'brand' => self::BRAND,
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_roles', ['user_id' => $target->id, 'role_id' => $client->id]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $target->id, 'role_id' => $powerUser->id]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $target->id, 'role_id' => $orgAdminRole->id]);
    }

    public function test_org_admin_editing_a_photographer_keeps_the_unchanged_role(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $target = $this->userIn($org, [UserRole::PHOTOGRAPHER]);
        $photographer = $this->role(UserRole::PHOTOGRAPHER);

        // The editor re-submits the current selection. Carrying the unchanged
        // photographer role through is deliberate: otherwise a metadata edit by
        // an org admin would detach the role and revoke the camera account
        // (§7.16) as a side effect.
        $this->as($orgAdmin)
            ->putJson("/api/management/users/{$target->id}", [
                'role_ids' => [$photographer->id],
                'can_edit_metadata' => true,
                'brand' => self::BRAND,
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $target->id,
            'role_id' => $photographer->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'can_edit_metadata' => 1]);
    }

    public function test_org_admin_cannot_remove_the_photographer_role(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);
        $target = $this->userIn($org, [UserRole::PHOTOGRAPHER]);
        $client = $this->role(UserRole::CLIENT);

        $this->as($orgAdmin)
            ->putJson("/api/management/users/{$target->id}", [
                'role_ids' => [$client->id],
                'brand' => self::BRAND,
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'Es dürfen nur die Rollen der eigenen Organisation (client, power_user, org_admin) vergeben werden.');

        $this->assertTrue(
            $target->fresh()->roles->pluck('name')->contains(UserRole::PHOTOGRAPHER->value),
        );
    }

    public function test_roles_endpoint_only_lists_the_org_domain_for_an_org_admin(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);

        foreach (UserRole::cases() as $role) {
            $this->role($role);
        }

        $response = $this->as($orgAdmin)->getJson('/api/management/roles');

        $response->assertOk();
        $names = collect($response->json())->pluck('name')->sort()->values()->all();

        $this->assertSame(['client', 'org_admin', 'power_user'], $names);
    }

    public function test_roles_endpoint_still_hides_super_admin_from_an_admin(): void
    {
        $admin = $this->userIn(null, [UserRole::ADMIN]);

        foreach (UserRole::cases() as $role) {
            $this->role($role);
        }

        $response = $this->as($admin)->getJson('/api/management/roles');

        $response->assertOk();
        $names = collect($response->json())->pluck('name')->all();

        $this->assertNotContains(UserRole::SUPER_ADMIN->value, $names);
        $this->assertContains(UserRole::PHOTOGRAPHER->value, $names);
        $this->assertContains(UserRole::ADMIN->value, $names);
    }

    public function test_me_exposes_the_org_scope_for_an_org_admin(): void
    {
        $org = $this->org();
        $orgAdmin = $this->orgAdmin($org);

        $group = GalleryGroup::factory()->create(['brand' => self::BRAND]);
        $org->galleryGroups()->attach($group);
        $gallery = $this->gallery([
            'brand' => self::BRAND,
            'gallery_group_id' => $group->id,
        ]);

        $response = $this->as($orgAdmin)->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertSame([$gallery->id], $response->json('org_scope.gallery_ids'));
        $this->assertSame([$group->id], $response->json('org_scope.gallery_group_ids'));
    }

    public function test_me_has_no_org_scope_for_an_admin(): void
    {
        $admin = $this->userIn(null, [UserRole::ADMIN]);

        $response = $this->as($admin)->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertNull($response->json('org_scope'));
    }

    public function test_admin_can_still_assign_the_photographer_role(): void
    {
        $admin = $this->userIn(null, [UserRole::ADMIN]);
        $target = $this->userIn(null, [UserRole::CLIENT]);
        $photographer = $this->role(UserRole::PHOTOGRAPHER);

        $this->as($admin)
            ->putJson("/api/management/users/{$target->id}", [
                'role_ids' => [$photographer->id],
                'brand' => self::BRAND,
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $target->id,
            'role_id' => $photographer->id,
        ]);
    }
}
