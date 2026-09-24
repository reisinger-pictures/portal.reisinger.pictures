<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\GalleryGroup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('admin.email', 'seed-admin@example.test');
        Config::set('admin.password', 'seed-password-for-tests');
    }

    public function test_seeder_fails_closed_without_an_admin_password(): void
    {
        Config::set('admin.password');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_EMAIL und ADMIN_PASSWORD müssen für das Seeding gesetzt sein.');

        (new DatabaseSeeder)->run();
    }

    public function test_reseed_preserves_custom_settings_and_does_not_import_locations(): void
    {
        Http::fake();

        $this->seed(DatabaseSeeder::class);
        DB::table('settings')
            ->where('key', 'price_web')
            ->where('brand', Brand::B2B->value)
            ->update(['value' => '9999']);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('settings', [
            'key' => 'price_web',
            'brand' => Brand::B2B->value,
            'value' => '9999',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'price_print',
            'brand' => Brand::B2B->value,
            'value' => '14500',
        ]);
        $this->assertDatabaseCount('locations', 0);
        Http::assertNothingSent();
    }

    public function test_reseed_repairs_a_legacy_null_brand_without_overwriting_group_attributes(): void
    {
        $group = GalleryGroup::query()->create([
            'slug' => 'privat',
            'name' => 'Administrator-Name',
            'is_public' => true,
            'brand' => null,
        ]);

        $this->seed(DatabaseSeeder::class);

        $group->refresh();
        $this->assertSame(Brand::B2B, $group->brand);
        $this->assertSame('Administrator-Name', $group->name);
        $this->assertTrue($group->is_public);
    }

    public function test_fresh_seed_assigns_the_active_brand_to_the_complete_group_tree(): void
    {
        $this->seed(DatabaseSeeder::class);

        $groups = GalleryGroup::query()->get();
        $this->assertCount(8, $groups);
        $this->assertFalse($groups->contains(fn (GalleryGroup $group): bool => $group->brand !== Brand::B2B));

        $presse = $groups->firstWhere('slug', 'presse');
        $this->assertNotNull($presse);
        $this->assertNull($presse->parent_id);

        $regionSlugs = ['oberoesterreich', 'oesterreich', 'wien'];
        foreach ($regionSlugs as $slug) {
            $region = $groups->firstWhere('slug', $slug);
            $this->assertNotNull($region);
            $this->assertSame($presse->id, $region->parent_id);
        }

        $sportParents = [
            'sport-oberoesterreich' => 'oberoesterreich',
            'sport-oesterreich' => 'oesterreich',
            'sport-wien' => 'wien',
        ];
        foreach ($sportParents as $sportSlug => $regionSlug) {
            $sport = $groups->firstWhere('slug', $sportSlug);
            $region = $groups->firstWhere('slug', $regionSlug);
            $this->assertNotNull($sport);
            $this->assertNotNull($region);
            $this->assertSame($region->id, $sport->parent_id);
        }
    }
}
