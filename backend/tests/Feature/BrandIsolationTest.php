<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\GalleryTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cross-brand isolation regression suite.
 *
 * Decision: only cross-brand users (brand === null, e.g. Super-Admin) may act
 * across brands. Every brand-bound user (including `admin`) is isolated to their
 * own brand. The brand column is a plain string, so a second brand (`srp`) is
 * created explicitly in the fixtures.
 */
class BrandIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function roleUser(UserRole $role, ?string $brand = null): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    private function putFixturePhoto(Gallery $gallery, Photo $photo): void
    {
        Storage::fake('photos');
        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            file_get_contents(base_path('tests/Fixtures/sample.jpg'))
        );
    }

    // =====================================================================
    // Finding 1 — AuthorizationService brand scoping
    // =====================================================================

    public function test_brand_bound_admin_cannot_manage_foreign_brand_gallery(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $gallery = Gallery::factory()->create(['brand' => 'srp']);

        $svc = app(AuthorizationService::class);

        $this->assertFalse($svc->canManageGallery($admin, $gallery->id));
        $this->assertFalse($svc->canAccessGallery($admin, $gallery->id));
    }

    public function test_brand_bound_admin_can_manage_own_brand_gallery(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $gallery = Gallery::factory()->create(['brand' => 'rp']);

        $this->assertTrue(app(AuthorizationService::class)->canManageGallery($admin, $gallery->id));
    }

    public function test_cross_brand_admin_can_manage_any_brand_gallery(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, null);
        $gallery = Gallery::factory()->create(['brand' => 'srp']);

        $this->assertTrue(app(AuthorizationService::class)->canManageGallery($admin, $gallery->id));
    }

    public function test_brand_bound_photographer_cannot_access_or_manage_foreign_brand_gallery(): void
    {
        $photographer = $this->roleUser(UserRole::PHOTOGRAPHER, 'rp');
        $gallery = Gallery::factory()->create(['brand' => 'srp', 'restricted_photographers' => false]);

        $svc = app(AuthorizationService::class);

        $this->assertFalse($svc->canPhotographerAccessGallery($photographer, $gallery->id));
        $this->assertFalse($svc->canManageGallery($photographer, $gallery->id));
    }

    public function test_brand_bound_photographer_cannot_manage_null_brand_gallery(): void
    {
        $photographer = $this->roleUser(UserRole::PHOTOGRAPHER, 'rp');
        $gallery = Gallery::factory()->create(['brand' => null, 'restricted_photographers' => false]);

        $this->assertFalse(app(AuthorizationService::class)->canManageGallery($photographer, $gallery->id));
    }

    public function test_brand_bound_admin_cannot_update_or_delete_foreign_brand_gallery_via_api(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $gallery = Gallery::factory()->create(['brand' => 'srp', 'name' => 'Fremde Galerie']);

        $this->actingAs($admin, 'api')
            ->putJson("/api/management/galleries/{$gallery->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/management/galleries/{$gallery->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'name' => 'Fremde Galerie']);
    }

    public function test_brand_bound_admin_cannot_delete_foreign_brand_photo_via_api(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $gallery = Gallery::factory()->create(['brand' => 'srp']);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
    }

    // =====================================================================
    // Finding 2 — GalleryController::updateGroup()/deleteGroup() authorization
    // =====================================================================

    public function test_brand_bound_admin_cannot_update_or_delete_foreign_brand_group(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $group = GalleryGroup::factory()->create(['brand' => 'srp', 'name' => 'Fremde Gruppe']);

        $this->actingAs($admin, 'api')
            ->putJson("/api/management/gallery-groups/{$group->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/management/gallery-groups/{$group->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('gallery_groups', ['id' => $group->id, 'name' => 'Fremde Gruppe']);
    }

    public function test_brand_bound_admin_can_update_and_delete_own_brand_group(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $group = GalleryGroup::factory()->create(['brand' => 'rp']);

        $this->actingAs($admin, 'api')
            ->putJson("/api/management/gallery-groups/{$group->id}", ['name' => 'Renamed'])
            ->assertStatus(200);

        $this->assertDatabaseHas('gallery_groups', ['id' => $group->id, 'name' => 'Renamed']);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/management/gallery-groups/{$group->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('gallery_groups', ['id' => $group->id]);
    }

    public function test_group_parent_id_must_match_brand(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $foreignParent = GalleryGroup::factory()->create(['brand' => 'srp']);

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/gallery-groups', [
                'name' => 'Neue Untergruppe',
                'parent_id' => $foreignParent->id,
            ])
            ->assertStatus(422);
    }

    public function test_group_org_id_must_match_brand(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $foreignOrg = Org::factory()->create(['brand' => 'srp']);

        $this->actingAs($admin, 'api')
            ->postJson('/api/management/gallery-groups', [
                'name' => 'Neue Gruppe',
                'org_id' => $foreignOrg->id,
            ])
            ->assertStatus(422);
    }

    // =====================================================================
    // Finding 3 — GalleryController::showGroup() brand leak
    // =====================================================================

    public function test_brand_bound_admin_cannot_view_foreign_brand_group(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $group = GalleryGroup::factory()->create(['brand' => 'srp']);

        $this->actingAs($admin, 'api')
            ->getJson("/api/management/gallery-groups/{$group->id}")
            ->assertStatus(403);
    }

    public function test_show_group_filters_foreign_brand_galleries_and_photos(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $group = GalleryGroup::factory()->create(['brand' => 'rp']);

        $ownGallery = Gallery::factory()->create(['gallery_group_id' => $group->id, 'brand' => 'rp']);
        $foreignGallery = Gallery::factory()->create(['gallery_group_id' => $group->id, 'brand' => 'srp']);
        Photo::factory()->create(['gallery_id' => $ownGallery->id]);
        Photo::factory()->create(['gallery_id' => $foreignGallery->id]);

        $response = $this->actingAs($admin, 'api')
            ->getJson("/api/management/gallery-groups/{$group->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('total', 1);
        $this->assertCount(1, $response->json('photos'));
        $this->assertSame($ownGallery->id, $response->json('photos.0.gallery_id'));
    }

    // =====================================================================
    // Finding 4 — GalleryTreeService brand scoping + cache key
    // =====================================================================

    public function test_admin_tree_is_brand_scoped_for_brand_bound_admin(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $ownGroup = GalleryGroup::factory()->create(['brand' => 'rp']);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);
        $ownRoot = Gallery::factory()->create(['gallery_group_id' => null, 'brand' => 'rp']);
        $foreignRoot = Gallery::factory()->create(['gallery_group_id' => null, 'brand' => 'srp']);

        $tree = app(GalleryTreeService::class)->getAdminTree($admin);

        $groupIds = array_column($tree['groups'], 'id');
        $this->assertContains($ownGroup->id, $groupIds);
        $this->assertNotContains($foreignGroup->id, $groupIds);

        $rootIds = array_column($tree['root_galleries'], 'id');
        $this->assertContains($ownRoot->id, $rootIds);
        $this->assertNotContains($foreignRoot->id, $rootIds);
    }

    public function test_admin_tree_cache_key_is_brand_specific(): void
    {
        Cache::flush();
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        GalleryGroup::factory()->create(['brand' => 'rp']);

        app(GalleryTreeService::class)->getAdminTree($admin);

        $this->assertNotNull(Cache::get('gallery_tree_admin_rp'));
        $this->assertNull(Cache::get('gallery_tree_admin'));
    }

    public function test_cross_brand_admin_tree_sees_all_brands_and_uses_global_key(): void
    {
        Cache::flush();
        $admin = $this->roleUser(UserRole::ADMIN, null);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $tree = app(GalleryTreeService::class)->getAdminTree($admin);

        $this->assertContains($foreignGroup->id, array_column($tree['groups'], 'id'));
        $this->assertNotNull(Cache::get('gallery_tree_admin'));
    }

    public function test_clear_cache_forgets_brand_specific_keys(): void
    {
        Cache::flush();
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        GalleryGroup::factory()->create(['brand' => 'rp']);

        $service = app(GalleryTreeService::class);
        $service->getAdminTree($admin);
        $this->assertNotNull(Cache::get('gallery_tree_admin_rp'));

        $service->clearCache();

        $this->assertNull(Cache::get('gallery_tree_admin_rp'));
        $this->assertNull(Cache::get('gallery_tree_admin'));
    }

    // =====================================================================
    // Finding 5 — FileDeliveryController un-watermarked original leak
    // =====================================================================

    public function test_unassigned_photographer_gets_watermark_on_public_restricted_gallery(): void
    {
        $photographer = $this->roleUser(UserRole::PHOTOGRAPHER, 'rp');
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => true,
            'is_free_download' => false,
            'brand' => 'rp',
            'restricted_photographers' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $this->putFixturePhoto($gallery, $photo);

        $this->actingAs($photographer, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(403)
            ->assertJson(['error' => 'Zugriff auf Original-Ressource verweigert. Wasserzeichen erforderlich.']);
    }

    public function test_assigned_photographer_may_download_original_on_public_restricted_gallery(): void
    {
        $photographer = $this->roleUser(UserRole::PHOTOGRAPHER, 'rp');
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => true,
            'is_free_download' => false,
            'brand' => 'rp',
            'restricted_photographers' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $this->putFixturePhoto($gallery, $photo);
        $photographer->photographerGalleries()->attach($gallery->id);

        $this->actingAs($photographer, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    public function test_foreign_brand_photographer_cannot_get_unwatermarked_original(): void
    {
        $photographer = $this->roleUser(UserRole::PHOTOGRAPHER, 'srp');
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => true,
            'is_free_download' => false,
            'brand' => 'rp',
            'restricted_photographers' => false,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $this->putFixturePhoto($gallery, $photo);

        $this->actingAs($photographer, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(403);
    }

    // =====================================================================
    // Finding 6 — PhotoDownloadController tier fallback
    // =====================================================================

    public function test_unknown_download_tier_is_rejected_with_422(): void
    {
        $user = User::factory()->create(['flatrate_level' => 'original', 'brand' => 'rp']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false, 'brand' => 'rp']);
        $user->galleries()->attach($gallery->id);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $this->putFixturePhoto($gallery, $photo);

        $this->actingAs($user, 'api')
            ->get("/api/photos/{$photo->id}/download?tier=bogus")
            ->assertStatus(422);

        $this->actingAs($user, 'api')
            ->get("/api/galleries/{$gallery->id}/download-zip?tier=bogus")
            ->assertStatus(422);
    }

    public function test_none_tier_is_rejected_with_422(): void
    {
        $user = User::factory()->create(['flatrate_level' => 'original', 'brand' => 'rp']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false, 'brand' => 'rp']);
        $user->galleries()->attach($gallery->id);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $this->putFixturePhoto($gallery, $photo);

        $this->actingAs($user, 'api')
            ->get("/api/photos/{$photo->id}/download?tier=none")
            ->assertStatus(422);
    }

    // =====================================================================
    // Finding 7 — SyncGalleryAccessRequest target user brand (P0-A12)
    // =====================================================================

    public function test_sync_access_rejects_foreign_brand_target_user(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $gallery = Gallery::factory()->create(['brand' => 'rp']);
        $foreignUser = User::factory()->create(['brand' => 'srp']);

        $this->actingAs($admin, 'api')
            ->postJson("/api/management/galleries/{$gallery->id}/sync-access", [
                'user_id' => $foreignUser->id,
                'action' => 'attach',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $foreignUser->id,
            'gallery_id' => $gallery->id,
        ]);
    }

    public function test_sync_access_allows_same_brand_target_user(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $gallery = Gallery::factory()->create(['brand' => 'rp']);
        $target = User::factory()->create(['brand' => 'rp']);

        $this->actingAs($admin, 'api')
            ->postJson("/api/management/galleries/{$gallery->id}/sync-access", [
                'user_id' => $target->id,
                'action' => 'attach',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $target->id,
            'gallery_id' => $gallery->id,
        ]);
    }

    public function test_cross_brand_admin_can_sync_foreign_brand_target_user(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, null);
        $gallery = Gallery::factory()->create(['brand' => 'srp']);
        $target = User::factory()->create(['brand' => 'srp']);

        $this->actingAs($admin, 'api')
            ->postJson("/api/management/galleries/{$gallery->id}/sync-access", [
                'user_id' => $target->id,
                'action' => 'attach',
            ])
            ->assertStatus(200);
    }

    public function test_sync_photographers_rejects_foreign_brand_target_user(): void
    {
        $admin = $this->roleUser(UserRole::ADMIN, 'rp');
        $gallery = Gallery::factory()->create(['brand' => 'rp']);
        $foreignUser = User::factory()->create(['brand' => 'srp']);

        $this->actingAs($admin, 'api')
            ->postJson("/api/management/galleries/{$gallery->id}/sync-photographers", [
                'user_id' => $foreignUser->id,
                'action' => 'attach',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('photographer_galleries', [
            'user_id' => $foreignUser->id,
            'gallery_id' => $gallery->id,
        ]);
    }
}
