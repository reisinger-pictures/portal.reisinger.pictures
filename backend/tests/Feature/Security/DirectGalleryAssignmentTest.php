<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * D-43: a direct gallery assignment carries delivery galleries only.
 *
 * `user_galleries` is the *client* grant table: it is what
 * `AuthorizationService::getAllowedGalleryIds()` reads as the direct grants
 * of an account, and what D-39 re-validates at read time — a non-delivery
 * grant is fail-closed there and hands out nothing. The write path offered
 * everything: an actor without an organization (a studio admin) had no scope
 * check at all, so any gallery id could be written into `user_galleries` and
 * produced a grant that the reader silently voided.
 *
 * A selection gallery is a private rating surface and reaches a client through
 * an invite, not through a direct assignment. The photographer surface is a
 * *different* relation (`photographer_galleries` /
 * `photographer_gallery_groups`) and is not touched by this rule — the last
 * test pins that.
 *
 * The rule applies to the *newly requested* ids only: an already assigned
 * gallery neither turns an unrelated save into an error nor is silently
 * revoked. It stays assigned until it is removed deliberately.
 *
 * There are two writers of the client grant table, and both are covered here:
 * the user editor (`UserController::update()`, `gallery_ids`) and the gallery
 * access dialog (`GalleryController::syncAccess()`).
 */
class DirectGalleryAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const BRAND = 'rp';

    protected function setUp(): void
    {
        parent::setUp();

        // The unrestricted-photographer gallery id set is process global.
        Cache::flush();
    }

    private function role(UserRole $role): Role
    {
        return Role::firstOrCreate(['name' => $role->value]);
    }

    /**
     * A studio administrator: an admin *without* an organization. This is the
     * actor the org scope did not cover.
     */
    private function studioAdmin(): User
    {
        $user = User::factory()->create([
            'brand' => self::BRAND,
            'org_id' => null,
        ]);
        $user->roles()->attach($this->role(UserRole::ADMIN));

        return $user;
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

    private function client(?Org $org = null): User
    {
        $user = User::factory()->create([
            'brand' => self::BRAND,
            'org_id' => $org?->id,
        ]);
        $user->roles()->attach($this->role(UserRole::CLIENT));

        return $user;
    }

    private function as(User $user)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)]);
    }

    /**
     * Gallery fixtures must not be pushed to a live Meilisearch.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function gallery(array $attributes = []): Gallery
    {
        return Gallery::withoutSyncingToSearch(
            fn (): Gallery => Gallery::factory()->create($attributes + ['brand' => self::BRAND]),
        );
    }

    private function readerIds(User $user): array
    {
        return app(AuthorizationService::class)->getAllowedGalleryIds($user->fresh());
    }

    public function test_a_studio_admin_cannot_assign_a_selection_gallery_directly(): void
    {
        $admin = $this->studioAdmin();
        $target = $this->client();
        $selection = $this->gallery(['type' => 'selection']);

        $this->as($admin)
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [$selection->id],
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'Es dürfen nur Liefergalerien direkt zugewiesen werden.');

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $selection->id,
        ]);
        $this->assertNotContains($selection->id, $this->readerIds($target));
    }

    public function test_a_studio_admin_can_assign_a_delivery_gallery_directly(): void
    {
        $admin = $this->studioAdmin();
        $target = $this->client();
        $delivery = $this->gallery(['type' => 'delivery']);

        $this->as($admin)
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [$delivery->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $delivery->id,
        ]);
        $this->assertContains($delivery->id, $this->readerIds($target));
    }

    public function test_an_existing_selection_grant_does_not_fail_an_unrelated_save_and_survives_it(): void
    {
        $admin = $this->studioAdmin();
        $target = $this->client();
        $selection = $this->gallery(['type' => 'selection']);
        $target->galleries()->attach($selection);

        // The editor carries the existing id through a full permission save.
        // It is not a new grant, so the unrelated metadata edit succeeds.
        $this->as($admin)
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [$selection->id],
                'can_edit_metadata' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $selection->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'can_edit_metadata' => 1]);

        // The same grant can still be removed deliberately by omitting it.
        $this->as($admin)
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $selection->id,
        ]);
    }

    public function test_a_save_without_gallery_ids_leaves_an_existing_selection_grant_untouched(): void
    {
        $admin = $this->studioAdmin();
        $target = $this->client();
        $selection = $this->gallery(['type' => 'selection']);
        $target->galleries()->attach($selection);

        $this->as($admin)
            ->putJson("/api/management/users/{$target->id}", [
                'can_edit_metadata' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $selection->id,
        ]);
    }

    public function test_the_org_admin_path_still_answers_with_the_org_scope_error(): void
    {
        $org = Org::factory()->create(['brand' => self::BRAND, 'invoice_frequency' => 'immediate']);
        $orgAdmin = $this->orgAdmin($org);
        $target = $this->client($org);
        $selection = $this->gallery(['type' => 'selection']);

        // The org scope is delivery-only, so the org rejection is unchanged and
        // still comes first — the D-43 rule does not re-label this answer.
        $this->as($orgAdmin)
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [$selection->id],
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'Es dürfen nur Galerien der eigenen Organisation zugewiesen werden.');

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $selection->id,
        ]);
    }

    public function test_the_photographer_surface_uses_its_own_relation_and_is_unaffected(): void
    {
        $admin = $this->studioAdmin();
        $photographer = User::factory()->create(['brand' => self::BRAND]);
        $photographer->roles()->attach($this->role(UserRole::PHOTOGRAPHER));

        $restricted = $this->gallery([
            'type' => 'selection',
            'restricted_photographers' => true,
        ]);
        $photographer->photographerGalleries()->attach($restricted);

        // The photographer grant lives in `photographer_galleries`, not in the
        // client table `user_galleries`, so the D-43 write rule does not reach
        // the photographer workflow.
        $this->assertDatabaseHas('photographer_galleries', [
            'user_id' => $photographer->id,
            'gallery_id' => $restricted->id,
        ]);
        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $photographer->id,
            'gallery_id' => $restricted->id,
        ]);
        $this->assertTrue($photographer->fresh()->canAccessGallery($restricted->id));

        // The client table stays clean: the very same gallery is refused as a
        // direct assignment.
        $target = $this->client();
        $this->as($admin)
            ->putJson("/api/management/users/{$target->id}", [
                'gallery_ids' => [$restricted->id],
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'Es dürfen nur Liefergalerien direkt zugewiesen werden.');
    }

    // ── the second writer of the client grant table: the access dialog ───────

    public function test_an_admin_cannot_grant_a_selection_gallery_through_the_gallery_access_dialog(): void
    {
        $admin = $this->studioAdmin();
        $target = $this->client();
        $selection = $this->gallery(['type' => 'selection']);

        // The access dialog writes the same client grant table as the user
        // editor. Without the rule it answered 200 and created a grant the
        // read path (D-39) voids — a silent no-op for the admin.
        $this->as($admin)
            ->postJson("/api/management/galleries/{$selection->id}/sync-access", [
                'user_id' => $target->id,
                'action' => 'attach',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'Es dürfen nur Liefergalerien direkt zugewiesen werden.');

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $selection->id,
        ]);
        $this->assertNotContains($selection->id, $this->readerIds($target));
    }

    public function test_an_admin_can_grant_a_delivery_gallery_through_the_gallery_access_dialog(): void
    {
        $admin = $this->studioAdmin();
        $target = $this->client();
        $delivery = $this->gallery(['type' => 'delivery']);

        $this->as($admin)
            ->postJson("/api/management/galleries/{$delivery->id}/sync-access", [
                'user_id' => $target->id,
                'action' => 'attach',
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $delivery->id,
        ]);
        $this->assertContains($delivery->id, $this->readerIds($target));
    }

    public function test_an_existing_selection_grant_survives_a_repeated_attach_and_can_still_be_detached(): void
    {
        $admin = $this->studioAdmin();
        $target = $this->client();
        $selection = $this->gallery(['type' => 'selection']);
        $target->galleries()->attach($selection);

        // The attach is idempotent: the grant already exists, so a re-sync
        // neither errors nor changes anything.
        $this->as($admin)
            ->postJson("/api/management/galleries/{$selection->id}/sync-access", [
                'user_id' => $target->id,
                'action' => 'attach',
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $selection->id,
        ]);

        // Removing a grant is never blocked.
        $this->as($admin)
            ->postJson("/api/management/galleries/{$selection->id}/sync-access", [
                'user_id' => $target->id,
                'action' => 'detach',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $selection->id,
        ]);
    }

    public function test_the_photographer_access_dialog_still_grants_a_selection_gallery(): void
    {
        $admin = $this->studioAdmin();
        $photographer = User::factory()->create(['brand' => self::BRAND]);
        $photographer->roles()->attach($this->role(UserRole::PHOTOGRAPHER));
        $selection = $this->gallery(['type' => 'selection']);

        // The photographer surface has its own relation and its own dialog.
        // The D-43 rule guards the *client* grant table, so a selection
        // gallery stays assignable to a photographer.
        $this->as($admin)
            ->postJson("/api/management/galleries/{$selection->id}/sync-photographers", [
                'user_id' => $photographer->id,
                'action' => 'attach',
            ])
            ->assertOk();

        $this->assertDatabaseHas('photographer_galleries', [
            'user_id' => $photographer->id,
            'gallery_id' => $selection->id,
        ]);
        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $photographer->id,
            'gallery_id' => $selection->id,
        ]);
    }
}
