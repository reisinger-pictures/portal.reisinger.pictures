<?php

namespace Tests\Feature;

use App\Models\GalleryGroup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Tenant-isolation regression tests for the gallery-group seeding in
 * {@see DatabaseSeeder}. The eight root slugs (`privat`, `presse`, …) are
 * seeded once per brand; a slug owned by one brand must never be handed to
 * another brand.
 *
 * The second brand is represented as a raw brand id on the row. `AsBrand`
 * round-trips ids it does not know as plain strings, so these tests do not
 * depend on the `Brand` enum containing more than its one current case.
 */
class DatabaseSeederGalleryGroupBrandIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('admin.email', 'seed-admin@example.test');
        Config::set('admin.password', 'seed-password-for-tests');
        Http::fake();
    }

    /**
     * A root slug that already belongs to another brand must not be reused.
     *
     * `gallery_groups.slug` is globally unique (V001:
     * `$table->string('slug')->unique();`, live index
     * `gallery_groups_slug_unique`), so the seeder cannot create a second row
     * for the same slug. The tenant-safe outcome is therefore to refuse
     * loudly, not to return the foreign group. The pre-fix slug-only
     * `firstOrCreate` silently returned the `srp` group and the seed finished;
     * this test catches exactly that silent inheritance.
     */
    public function test_seeder_refuses_to_reuse_a_group_owned_by_another_brand(): void
    {
        GalleryGroup::query()->create([
            'slug' => 'privat',
            'name' => 'Fremde Marke',
            'is_public' => true,
            'brand' => 'srp',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("GalleryGroup slug 'privat' already belongs to brand 'srp'");

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * A legacy NULL-brand row for one of the seeder's own slugs is still
     * repaired (claimed) without overwriting administrator-managed
     * attributes. This mirrors the documented behaviour in
     * DatabaseSeederHardeningTest and guards it against the brand-scoped
     * rewrite.
     */
    public function test_seeder_still_repairs_a_legacy_null_brand_group(): void
    {
        $group = GalleryGroup::query()->create([
            'slug' => 'privat',
            'name' => 'Administrator-Name',
            'is_public' => true,
            'brand' => null,
        ]);

        $this->seed(DatabaseSeeder::class);

        $group->refresh();
        $this->assertSame('rp', $group->brand?->value);
        $this->assertSame('Administrator-Name', $group->name);
        $this->assertTrue($group->is_public);
    }

    /**
     * The seeder remains idempotent: a second run neither duplicates the
     * eight root groups nor creates a second row for a given slug.
     */
    public function test_seeder_is_idempotent_for_one_brand(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(8, GalleryGroup::query()->count());
        $this->assertSame(1, GalleryGroup::query()->where('slug', 'privat')->count());
    }

    /**
     * Documents the schema constraint that makes the intended
     * "two rows, one per brand, same slug" property impossible today: slugs
     * are globally unique, not unique per `(brand, slug)`. Until that index is
     * migrated, the seeder can only refuse a cross-brand slug collision.
     */
    public function test_schema_rejects_a_second_brand_sharing_a_slug(): void
    {
        GalleryGroup::query()->create(['slug' => 'privat', 'name' => 'Brand A', 'brand' => 'rp']);

        $this->expectException(QueryException::class);

        GalleryGroup::query()->create(['slug' => 'privat', 'name' => 'Brand B', 'brand' => 'srp']);
    }
}
