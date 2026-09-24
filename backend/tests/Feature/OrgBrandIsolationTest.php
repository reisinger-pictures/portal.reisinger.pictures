<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brand isolation for the org-management show and write endpoints (show, update,
 * destroy, syncUsers, syncGroups, generateCollectiveInvoice).
 */
class OrgBrandIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_BRAND = 'srp';

    private function tokenFor(UserRole $role, ?string $brand): string
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return auth('api')->login($user);
    }

    public function test_brand_bound_admin_cannot_view_brandless_org(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create([
            'brand' => null,
            'name' => 'Legacy Brandless Org',
            'invoice_frequency' => 'immediate',
        ]);
        $member = User::factory()->create([
            'brand' => 'rp',
            'org_id' => $org->id,
            'email' => 'legacy-org-member@example.test',
        ]);
        $group = GalleryGroup::factory()->create(['brand' => null, 'name' => 'Legacy Group']);
        $org->users()->save($member);
        $org->galleryGroups()->attach($group);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson("/api/management/orgs/{$org->id}")
            ->assertForbidden()
            ->assertJsonMissing(['email' => $member->email]);
    }

    public function test_brand_bound_admin_cannot_update_brandless_org(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create([
            'brand' => null,
            'name' => 'Legacy Brandless Org',
            'invoice_frequency' => 'immediate',
        ]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}", [
                'name' => 'Hijacked Brandless Org',
                'invoice_frequency' => 'monthly',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('orgs', [
            'id' => $org->id,
            'name' => 'Legacy Brandless Org',
            'invoice_frequency' => 'immediate',
        ]);
    }

    public function test_brand_bound_admin_cannot_delete_brandless_org(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => null]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/management/orgs/{$org->id}")
            ->assertForbidden();

        $this->assertModelExists($org);
    }

    public function test_brand_bound_admin_cannot_sync_users_on_brandless_org(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => null]);
        $user = User::factory()->create(['brand' => 'rp']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$user->id]])
            ->assertForbidden();

        $this->assertNull($user->fresh()->org_id);
    }

    public function test_brand_bound_admin_cannot_sync_groups_on_brandless_org(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => null]);
        $group = GalleryGroup::factory()->create(['brand' => 'rp']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}/groups", ['group_ids' => [$group->id]])
            ->assertForbidden();

        $this->assertDatabaseMissing('gallery_group_org', [
            'org_id' => $org->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_brand_bound_admin_cannot_update_foreign_brand_org(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => self::OTHER_BRAND, 'name' => 'Foreign Org', 'invoice_frequency' => 'immediate']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}", [
                'name' => 'Hijacked',
                'invoice_frequency' => 'monthly',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('orgs', ['id' => $org->id, 'name' => 'Foreign Org']);
    }

    public function test_brand_bound_admin_cannot_delete_foreign_brand_org(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => self::OTHER_BRAND]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/management/orgs/{$org->id}")
            ->assertStatus(403);

        $this->assertModelExists($org);
    }

    public function test_brand_bound_admin_cannot_sync_users_on_foreign_brand_org(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => self::OTHER_BRAND]);
        $user = User::factory()->create(['brand' => self::OTHER_BRAND]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$user->id]])
            ->assertStatus(403);

        $this->assertNull($user->fresh()->org_id);
    }

    public function test_brand_bound_admin_cannot_sync_groups_on_foreign_brand_org(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => self::OTHER_BRAND]);
        $group = GalleryGroup::factory()->create(['brand' => self::OTHER_BRAND]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}/groups", ['group_ids' => [$group->id]])
            ->assertStatus(403);

        $this->assertDatabaseMissing('gallery_group_org', [
            'org_id' => $org->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_brand_bound_admin_cannot_generate_collective_invoice_for_foreign_brand_org(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => self::OTHER_BRAND]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orgs/{$org->id}/collective-invoice")
            ->assertStatus(403);
    }

    public function test_cross_brand_super_admin_can_update_foreign_brand_org(): void
    {
        $token = $this->tokenFor(UserRole::SUPER_ADMIN, null);
        $org = Org::factory()->create(['brand' => self::OTHER_BRAND, 'name' => 'Foreign Org', 'invoice_frequency' => 'immediate']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}", [
                'name' => 'Updated by Cross-Brand',
                'invoice_frequency' => 'monthly',
            ])
            ->assertOk();

        $this->assertDatabaseHas('orgs', ['id' => $org->id, 'name' => 'Updated by Cross-Brand']);
    }

    public function test_sync_users_rejects_foreign_brand_user(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => 'rp']);
        $foreignUser = User::factory()->create(['brand' => self::OTHER_BRAND]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$foreignUser->id]])
            ->assertStatus(422);

        $this->assertNull($foreignUser->fresh()->org_id);
    }

    public function test_sync_users_rejects_brandless_org_before_assignment(): void
    {
        // A legacy brand-less org is rejected before any relationship write.
        $token = $this->tokenFor(UserRole::SUPER_ADMIN, null);
        $org = Org::create(['name' => 'Brandless Org', 'invoice_frequency' => 'immediate']);
        $brandBoundUser = User::factory()->create(['brand' => 'rp']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$brandBoundUser->id]])
            ->assertForbidden();

        $this->assertNull($brandBoundUser->fresh()->org_id);
    }

    public function test_sync_users_accepts_same_brand_user(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => 'rp']);
        $user = User::factory()->create(['brand' => 'rp']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$user->id]])
            ->assertOk();

        $this->assertEquals($org->id, $user->fresh()->org_id);
    }

    public function test_sync_groups_rejects_foreign_brand_group(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => 'rp']);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => self::OTHER_BRAND]);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}/groups", ['group_ids' => [$foreignGroup->id]])
            ->assertStatus(422);

        $this->assertDatabaseMissing('gallery_group_org', [
            'org_id' => $org->id,
            'gallery_group_id' => $foreignGroup->id,
        ]);
    }

    public function test_sync_groups_accepts_same_brand_group(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN, 'rp');
        $org = Org::factory()->create(['brand' => 'rp']);
        $group = GalleryGroup::factory()->create(['brand' => 'rp']);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}/groups", ['group_ids' => [$group->id]])
            ->assertOk();

        $this->assertDatabaseHas('gallery_group_org', [
            'org_id' => $org->id,
            'gallery_group_id' => $group->id,
        ]);
    }
}
