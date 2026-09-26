<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\GalleryGroup;
use App\Services\GalleryService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P1-M15 / F2 — the group hierarchy itself must stay a tree of one brand.
 *
 * The gallery-to-group brand invariant is covered by
 * GalleryGroupBrandInvariantTest. This class covers the axis above it: a meta
 * gallery re-parented under another meta gallery.
 *
 * Two distinct defects are pinned here.
 *
 * The brand axis was guarded only in GroupRequest, via
 * Rule::exists('gallery_groups', 'id')->where('brand', $brand). That rule is
 * skipped whenever brandForValidation() cannot normalize the target group's
 * brand, and a FormRequest is the wrong layer for a structural invariant
 * anyway — anything reaching the service can bypass it. GalleryService now
 * asserts it as well.
 *
 * The cycle axis had no guard at all. A group could be moved under its own
 * descendant. The subtree traversal is cycle-safe (GalleryGroupSubtree tracks
 * visited ids, so it terminates rather than hanging), so the consequence is not
 * a denial of service: it is a corrupted hierarchy that the recursive frontend
 * group tree has no meaningful rendering for.
 *
 * These tests drive the service directly, so the guards are proven to live in
 * the authoritative boundary.
 */
class GalleryGroupParentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private GalleryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        BrandRegistry::set(Brand::B2B);
        $this->service = app(GalleryService::class);
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(Brand::B2B);
        BrandRegistry::clearCache();
        parent::tearDown();
    }

    // ─── The normal path must keep working ─────────────────────────────

    public function test_same_brand_reparenting_is_accepted(): void
    {
        $parent = GalleryGroup::factory()->create(['brand' => 'rp']);
        $child = GalleryGroup::factory()->create(['brand' => 'rp', 'parent_id' => null]);

        $updated = $this->service->updateGroup($child, [
            'name' => $child->name,
            'parent_id' => $parent->id,
        ]);

        $this->assertSame($parent->id, $updated->parent_id);
        $this->assertDatabaseHas('gallery_groups', [
            'id' => $child->id,
            'parent_id' => $parent->id,
        ]);
    }

    public function test_detaching_with_a_null_parent_is_accepted(): void
    {
        $parent = GalleryGroup::factory()->create(['brand' => 'rp']);
        $child = GalleryGroup::factory()->create(['brand' => 'rp', 'parent_id' => $parent->id]);

        $updated = $this->service->updateGroup($child, [
            'name' => $child->name,
            'parent_id' => null,
        ]);

        $this->assertNull($updated->parent_id);
    }

    public function test_store_group_accepts_a_same_brand_parent(): void
    {
        $parent = GalleryGroup::factory()->create(['brand' => Brand::B2B->value]);

        $group = $this->service->storeGroup([
            'name' => 'Child of same brand',
            'parent_id' => $parent->id,
        ]);

        $this->assertSame($parent->id, $group->parent_id);
        // The model casts the column to the Brand enum, so compare the
        // normalized id rather than the raw attribute.
        $this->assertSame(Brand::B2B->value, BrandRegistry::normalizeId($group->brand));
    }

    // ─── Brand axis ────────────────────────────────────────────────────

    public function test_reparenting_under_a_foreign_brand_parent_is_rejected(): void
    {
        $parent = GalleryGroup::factory()->create(['brand' => 'srp']);
        $child = GalleryGroup::factory()->create(['brand' => 'rp']);

        $this->expectException(ValidationException::class);

        try {
            $this->service->updateGroup($child, [
                'name' => $child->name,
                'parent_id' => $parent->id,
            ]);
        } finally {
            $this->assertDatabaseHas('gallery_groups', [
                'id' => $child->id,
                'parent_id' => null,
            ]);
        }
    }

    public function test_store_group_under_a_foreign_brand_parent_is_rejected(): void
    {
        $parent = GalleryGroup::factory()->create(['brand' => 'srp']);

        try {
            $this->service->storeGroup([
                'name' => 'Cross brand child',
                'parent_id' => $parent->id,
            ]);
            $this->fail('Expected a cross-brand parent to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent_id', $exception->errors());
        }

        $this->assertDatabaseMissing('gallery_groups', ['name' => 'Cross brand child']);
    }

    public function test_reparenting_a_group_without_a_brand_is_rejected(): void
    {
        // requireGroupBrand() refuses a group it cannot place on the brand map,
        // which is what closes the hole the FormRequest rule leaves open when
        // brandForValidation() returns null.
        $parent = GalleryGroup::factory()->create(['brand' => 'rp']);
        $child = GalleryGroup::factory()->create(['brand' => null]);

        $this->expectException(ValidationException::class);

        $this->service->updateGroup($child, [
            'name' => $child->name,
            'parent_id' => $parent->id,
        ]);
    }

    // ─── Cycle axis ────────────────────────────────────────────────────

    public function test_a_group_cannot_be_moved_under_its_own_descendant(): void
    {
        $root = GalleryGroup::factory()->create(['brand' => 'rp']);
        $middle = GalleryGroup::factory()->create(['brand' => 'rp', 'parent_id' => $root->id]);
        $leaf = GalleryGroup::factory()->create(['brand' => 'rp', 'parent_id' => $middle->id]);

        // Moving the root under its own grandchild closes a loop.
        $this->expectException(ValidationException::class);

        try {
            $this->service->updateGroup($root, [
                'name' => $root->name,
                'parent_id' => $leaf->id,
            ]);
        } finally {
            $this->assertDatabaseHas('gallery_groups', [
                'id' => $root->id,
                'parent_id' => null,
            ]);
        }
    }

    public function test_a_group_cannot_be_its_own_parent(): void
    {
        $group = GalleryGroup::factory()->create(['brand' => 'rp']);

        $this->expectException(ValidationException::class);

        $this->service->updateGroup($group, [
            'name' => $group->name,
            'parent_id' => $group->id,
        ]);
    }

    public function test_moving_a_sibling_under_another_branch_is_accepted(): void
    {
        // Guard against an over-eager cycle check: a shared ancestor must not
        // be mistaken for a cycle.
        $root = GalleryGroup::factory()->create(['brand' => 'rp']);
        $left = GalleryGroup::factory()->create(['brand' => 'rp', 'parent_id' => $root->id]);
        $right = GalleryGroup::factory()->create(['brand' => 'rp', 'parent_id' => $root->id]);

        $updated = $this->service->updateGroup($right, [
            'name' => $right->name,
            'parent_id' => $left->id,
        ]);

        $this->assertSame($left->id, $updated->parent_id);
    }
}
