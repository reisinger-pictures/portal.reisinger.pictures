<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\GalleryGroup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pins the V046 schema change: `gallery_groups.slug` is unique per
 * `(brand, slug)` instead of globally unique.
 *
 * `RefreshDatabase` runs the real migration chain on SQLite (`:memory:`), so
 * these assertions exercise the migrated schema rather than a hand-built
 * fixture. The companion drop of `gallery_groups_slug_unique` is covered
 * implicitly: the cross-brand insert would fail while the old single-column
 * unique still existed.
 *
 * SQLite proves the index shape and the database-level rejection. Production
 * runs MySQL/MariaDB, which receives the same DDL through Laravel's schema
 * grammar (`unique(['brand', 'slug'], 'gallery_groups_brand_slug_unique')`);
 * the V046 migration checks index existence driver-neutrally. No MySQL run is
 * performed by this test — that is an admitted gap, not a proven claim.
 */
class GalleryGroupSlugPerBrandUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_brands_can_own_the_same_group_slug(): void
    {
        $rp = GalleryGroup::query()->create([
            'name' => 'Privat (rp)',
            'slug' => 'shared-slug',
            'brand' => Brand::B2B->value,
        ]);

        $srp = GalleryGroup::query()->create([
            'name' => 'Privat (srp)',
            'slug' => 'shared-slug',
            'brand' => 'srp',
        ]);

        $this->assertNotSame($rp->id, $srp->id);
        $this->assertSame(2, GalleryGroup::query()->where('slug', 'shared-slug')->count());
        $this->assertDatabaseHas('gallery_groups', ['id' => $rp->id, 'slug' => 'shared-slug', 'brand' => 'rp']);
        $this->assertDatabaseHas('gallery_groups', ['id' => $srp->id, 'slug' => 'shared-slug', 'brand' => 'srp']);
    }

    public function test_same_slug_twice_in_one_brand_is_rejected(): void
    {
        GalleryGroup::query()->create([
            'name' => 'First',
            'slug' => 'duplicate-slug',
            'brand' => Brand::B2B->value,
        ]);

        $this->expectException(QueryException::class);

        GalleryGroup::query()->create([
            'name' => 'Second',
            'slug' => 'duplicate-slug',
            'brand' => Brand::B2B->value,
        ]);
    }

    public function test_schema_carries_the_composite_unique_and_not_the_global_one(): void
    {
        $indexes = collect(Schema::getIndexes('gallery_groups'))->keyBy('name');

        $composite = $indexes->get('gallery_groups_brand_slug_unique');
        $this->assertNotNull($composite, 'V046 must add gallery_groups_brand_slug_unique.');
        $this->assertTrue((bool) $composite['unique']);
        $this->assertSame(['brand', 'slug'], $composite['columns']);

        $this->assertNull(
            $indexes->get('gallery_groups_slug_unique'),
            'V046 must drop the global gallery_groups_slug_unique.',
        );

        // `(brand, slug)` has `brand` leftmost, so it also serves brand-only
        // lookups on MySQL (leftmost prefix) and SQLite (B-tree). The separate
        // brand index is redundant and must be gone.
        $this->assertNull(
            $indexes->get('gallery_groups_brand_index'),
            'V046 drops the redundant gallery_groups_brand_index.',
        );
    }
}
