<?php

namespace Tests\Feature;

use App\Models\GalleryGroup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tenant-isolation regression tests for the gallery-group seeding in
 * {@see DatabaseSeeder}. The eight root slugs (`privat`, `presse`, …) are
 * seeded once per brand; a slug owned by one brand must never be handed to
 * another brand.
 *
 * Since V046 `gallery_groups.slug` is unique per `(brand, slug)`, so a second
 * brand owns its own row for the same slug. The second brand is represented as
 * a raw brand id on the row. `AsBrand` round-trips ids it does not know as
 * plain strings, so these tests do not depend on the `Brand` enum containing
 * more than its one current case.
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
     * V046 makes `(brand, slug)` the unique key, so the tenant-safe outcome is
     * to create the active brand's own row — not to return the foreign group
     * (the pre-fix slug-only `firstOrCreate` did exactly that) and not to
     * refuse a state the schema now permits. The foreign row must stay
     * untouched.
     */
    public function test_seeder_creates_its_own_group_when_another_brand_owns_the_slug(): void
    {
        GalleryGroup::query()->create([
            'slug' => 'privat',
            'name' => 'Fremde Marke',
            'is_public' => true,
            'brand' => 'srp',
        ]);

        $this->seed(DatabaseSeeder::class);

        $groups = GalleryGroup::query()->where('slug', 'privat')->get();

        $this->assertCount(2, $groups);
        $this->assertNotNull($groups->firstWhere('brand', 'rp'));
        $this->assertNotNull($groups->firstWhere('brand', 'srp'));

        // No silent inheritance: the foreign row keeps its own attributes.
        $foreign = $groups->firstWhere('brand', 'srp');
        $this->assertSame('Fremde Marke', $foreign->name);
        $this->assertTrue($foreign->is_public);
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
     * eight root groups nor creates a second row for a given `(brand, slug)`.
     */
    public function test_seeder_is_idempotent_for_one_brand(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(8, GalleryGroup::query()->count());
        $this->assertSame(1, GalleryGroup::query()->where('slug', 'privat')->count());
    }

    /**
     * Documents the V046 schema constraint: two brands may share a slug, while
     * the same brand may not. The full invariant, including the index shape
     * and the application-level uniqueness, lives in
     * {@see GalleryGroupSlugPerBrandUniqueTest}.
     */
    public function test_schema_allows_a_second_brand_to_share_a_slug(): void
    {
        GalleryGroup::query()->create(['slug' => 'privat', 'name' => 'Brand A', 'brand' => 'rp']);
        GalleryGroup::query()->create(['slug' => 'privat', 'name' => 'Brand B', 'brand' => 'srp']);

        $this->assertSame(2, GalleryGroup::query()->where('slug', 'privat')->count());
    }
}
