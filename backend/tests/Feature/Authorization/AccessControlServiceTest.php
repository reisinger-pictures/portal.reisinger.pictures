<?php

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\Org;
use App\Models\User;
use App\Services\AccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AccessControlServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccessControlService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccessControlService();
    }

    // ──────────────────────────────────────────────
    //  Guest User
    // ──────────────────────────────────────────────

    public function test_guest_user_gets_transient_galleries(): void
    {
        $user = User::factory()->create();
        $user->guest_id = 'guest-1';
        $user->transient_galleries = ['g1', 'g2'];

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertSame(['g1', 'g2'], $ids);
    }

    public function test_guest_user_with_empty_transient_returns_empty(): void
    {
        $user = User::factory()->create();
        $user->guest_id = 'guest-2';
        $user->transient_galleries = [];

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertSame([], $ids);
    }

    // ──────────────────────────────────────────────
    //  Direct Gallery Assignments
    // ──────────────────────────────────────────────

    public function test_direct_gallery_assignment(): void
    {
        $gallery = Gallery::factory()->create();
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        $user->galleryGroups()->attach($group);

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($gallery->id, $ids);
    }

    public function test_recursive_sub_group_access(): void
    {
        $parent = GalleryGroup::factory()->create();
        $child = GalleryGroup::factory()->create(['parent_id' => $parent->id]);
        $gallery = Gallery::factory()->create(['gallery_group_id' => $child->id]);
        $user = User::factory()->create();
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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
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
        $galleryA = Gallery::factory()->create(['brand' => 'srp']);
        $galleryB = Gallery::factory()->create(['brand' => 'rp']);

        $user = User::factory()->create(['brand' => 'srp']);
        $user->galleries()->attach([$galleryA->id, $galleryB->id]);

        $ids = $this->service->getAllowedGalleryIds($user);

        $this->assertContains($galleryA->id, $ids);
        $this->assertNotContains($galleryB->id, $ids);
    }

    public function test_brand_scoping_rejects_null_brand_galleries(): void
    {
        $gallery = Gallery::factory()->create(['brand' => null]);
        $user = User::factory()->create(['brand' => 'srp']);
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
}
