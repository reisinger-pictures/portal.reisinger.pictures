<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use App\Services\MediaVisibilityService;
use App\Support\BrandRegistry;
use App\Support\GalleryGroupSubtree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AUTH-5 (robustness): the public gallery/photo breadcrumb walks and the
 * brand/visibility ancestor walkers must terminate on corrupt data.
 *
 * `gallery_groups.parent_id` has no database-level cycle constraint. The model
 * `saving` hook rejects cycles, but existing or directly written data can still
 * contain them, so the runtime guards must be self-terminating. These tests
 * bypass the model hook with direct SQL.
 */
class GalleryTreeDepthHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a linear (non-cyclic) same-brand group chain and return the groups
     * in root-to-leaf order.
     *
     * @return array<int, GalleryGroup>
     */
    private function makeChain(int $length): array
    {
        $groups = [];
        $parentId = null;

        for ($i = 0; $i < $length; $i++) {
            $group = GalleryGroup::factory()->create([
                'brand' => 'rp',
                'parent_id' => $parentId,
            ]);
            $groups[] = $group;
            $parentId = $group->id;
        }

        return $groups;
    }

    public function test_public_gallery_endpoint_terminates_and_404s_on_a_cyclic_group_chain(): void
    {
        $groupA = GalleryGroup::factory()->create(['brand' => 'rp', 'parent_id' => null]);
        $groupB = GalleryGroup::factory()->create(['brand' => 'rp', 'parent_id' => null]);

        // Bypass the model saving guard: a direct write can create corrupt data.
        DB::table('gallery_groups')->where('id', $groupA->id)->update(['parent_id' => $groupB->id]);
        DB::table('gallery_groups')->where('id', $groupB->id)->update(['parent_id' => $groupA->id]);

        $gallery = Gallery::factory()->create([
            'brand' => 'rp',
            'type' => 'delivery',
            'is_public' => true,
            'gallery_group_id' => $groupA->id,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        // A hung request would never reach these assertions.
        $this->getJson('/api/galleries/'.$gallery->slug)->assertStatus(404);
        $this->getJson('/api/photos/'.$photo->id.'/context')->assertStatus(404);
        $this->getJson('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')->assertStatus(404);
    }

    public function test_direct_sql_cycle_is_rejected_by_the_brand_and_visibility_guards(): void
    {
        $groupA = GalleryGroup::factory()->create(['brand' => 'rp', 'parent_id' => null]);
        $groupB = GalleryGroup::factory()->create(['brand' => 'rp', 'parent_id' => null]);

        DB::table('gallery_groups')->where('id', $groupA->id)->update(['parent_id' => $groupB->id]);
        DB::table('gallery_groups')->where('id', $groupB->id)->update(['parent_id' => $groupA->id]);

        $gallery = Gallery::factory()->create([
            'brand' => 'rp',
            'type' => 'delivery',
            'is_public' => true,
            'gallery_group_id' => $groupA->id,
        ]);

        $this->assertFalse(BrandRegistry::galleryTreeMatchesCurrent($gallery));
        $this->assertFalse(app(MediaVisibilityService::class)->galleryIsVisible($gallery->id));
    }

    public function test_brand_registry_rejects_a_chain_beyond_the_depth_budget(): void
    {
        $groups = $this->makeChain(GalleryGroupSubtree::MAX_DEPTH + 3);
        $boundary = $groups[GalleryGroupSubtree::MAX_DEPTH];
        $deepest = $groups[array_key_last($groups)];

        $this->assertTrue(BrandRegistry::galleryGroupTreeMatchesBrand($groups[0], 'rp'));
        $this->assertTrue(BrandRegistry::galleryGroupTreeMatchesBrand($boundary, 'rp'));
        $this->assertFalse(BrandRegistry::galleryGroupTreeMatchesBrand($deepest, 'rp'));
    }

    public function test_media_visibility_rejects_a_chain_beyond_the_depth_budget(): void
    {
        $groups = $this->makeChain(GalleryGroupSubtree::MAX_DEPTH + 3);

        $visibleGallery = Gallery::factory()->create([
            'brand' => 'rp',
            'gallery_group_id' => $groups[0]->id,
        ]);
        $overDeepGallery = Gallery::factory()->create([
            'brand' => 'rp',
            'gallery_group_id' => $groups[array_key_last($groups)]->id,
        ]);

        $service = app(MediaVisibilityService::class);

        $this->assertTrue($service->galleryIsVisible($visibleGallery->id));
        $this->assertFalse($service->galleryIsVisible($overDeepGallery->id));
    }
}
