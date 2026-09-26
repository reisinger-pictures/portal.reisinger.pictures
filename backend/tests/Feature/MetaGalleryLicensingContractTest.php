<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Models\VolumePreset;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MetaGalleryLicensingContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        BrandRegistry::clearCache();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_meta_gallery_photos_and_pricing_sources_expose_child_gallery_licensing_context(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, Brand::B2B);
        $group = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'name' => 'Direct child parent',
        ]);
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'gallery_group_id' => $group->id,
            'name' => 'Volume child',
            'is_public' => true,
            'licensing_mode' => 'volume_licensing',
        ]);
        Photo::factory()->count(2)->create([
            'gallery_id' => $gallery->id,
            'user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson("/api/management/gallery-groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('photos.0.gallery.id', $gallery->id)
            ->assertJsonPath('photos.0.gallery.gallery_group_id', $group->id)
            ->assertJsonPath('photos.0.gallery.effective_licensing_mode', 'volume_licensing')
            ->assertJsonPath('gallery_pricing_sources.0.gallery_id', $gallery->id)
            ->assertJsonPath('gallery_pricing_sources.0.gallery_group_id', $group->id)
            ->assertJsonPath('gallery_pricing_sources.0.gallery_name', 'Volume child')
            ->assertJsonPath('gallery_pricing_sources.0.photo_count', 2)
            ->assertJsonPath('total', 2);

        $this->assertSame($gallery->id, $response->json('photos.0.gallery.id'));
    }

    public function test_meta_gallery_summary_covers_mixed_direct_and_nested_child_groups(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, Brand::B2B);
        $parent = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'name' => 'Mixed pricing parent',
        ]);
        $child = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $parent->id,
            'name' => 'Mixed pricing child',
        ]);

        $presetService = app(VolumePresetService::class);
        $nestedPreset = $presetService->create('Nested volume preset', [
            ['min_quantity' => 0, 'price_cents' => 5000],
            ['min_quantity' => 2, 'price_cents' => 4000],
        ]);
        $emptyPreset = $presetService->create('Empty volume preset', [
            ['min_quantity' => 0, 'price_cents' => 6200],
        ]);

        $scopeGallery = $this->gallery($parent, 'scope_licensing', 'Direct scope child', 1, $admin);
        $volumeGallery = $this->gallery(
            $child,
            'volume_licensing',
            'Nested volume child',
            2,
            $admin,
            $nestedPreset,
        );
        $emptyVolumeGallery = $this->gallery(
            $parent,
            'volume_licensing',
            'Empty volume child',
            0,
            $admin,
            $emptyPreset,
        );

        $response = $this->actingAs($admin, 'api')
            ->getJson("/api/management/gallery-groups/{$parent->id}")
            ->assertOk()
            ->assertJsonCount(3, 'gallery_pricing_sources')
            ->assertJsonPath('total', 3);

        $sources = $this->pricingSourcesByGallery($response->json('gallery_pricing_sources'));
        $this->assertSame('Direct scope child', $sources->get($scopeGallery->id)['gallery_name']);
        $this->assertSame('Nested volume child', $sources->get($volumeGallery->id)['gallery_name']);
        $this->assertSame('Empty volume child', $sources->get($emptyVolumeGallery->id)['gallery_name']);
        $this->assertSame(1, $sources->get($scopeGallery->id)['photo_count']);
        $this->assertSame(2, $sources->get($volumeGallery->id)['photo_count']);
        $this->assertSame(0, $sources->get($emptyVolumeGallery->id)['photo_count']);
        $this->assertSame($parent->id, $sources->get($scopeGallery->id)['gallery_group_id']);
        $this->assertSame($child->id, $sources->get($volumeGallery->id)['gallery_group_id']);

        $this->getJson("/api/settings/license-terms?gallery_id={$scopeGallery->id}")
            ->assertOk()
            ->assertJsonPath('pricing_strategy', 'scope_licensing')
            ->assertJsonPath('volume_pricing', null);
        $this->getJson("/api/settings/license-terms?gallery_id={$volumeGallery->id}")
            ->assertOk()
            ->assertJsonPath('pricing_strategy', 'volume_licensing')
            // Strict: the numeric `volume_presets.id` primary key, not a string.
            ->assertJsonPath('volume_pricing.preset_id', $nestedPreset->id, true)
            ->assertJsonPath('volume_pricing.preset_name', 'Nested volume preset')
            ->assertJsonPath('volume_pricing.tiers.0.min_quantity', 0)
            ->assertJsonPath('volume_pricing.tiers.0.price_cents', 5000)
            ->assertJsonPath('volume_pricing.tiers.1.min_quantity', 2)
            ->assertJsonPath('volume_pricing.tiers.1.price_cents', 4000);
        $this->getJson("/api/settings/license-terms?gallery_id={$emptyVolumeGallery->id}")
            ->assertOk()
            ->assertJsonPath('pricing_strategy', 'volume_licensing')
            ->assertJsonPath('volume_pricing.preset_id', $emptyPreset->id, true)
            ->assertJsonPath('volume_pricing.preset_name', 'Empty volume preset');
    }

    public function test_meta_gallery_summary_excludes_children_outside_the_photographer_assignment(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER, Brand::B2B);
        $parent = GalleryGroup::factory()->create(['brand' => Brand::B2B]);
        $child = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $parent->id,
        ]);
        $unassignedGallery = $this->gallery($parent, 'volume_licensing', 'Unassigned direct', 1, $photographer);
        $assignedGallery = $this->gallery($child, 'volume_licensing', 'Assigned nested', 1, $photographer);
        // Photographers may see unrestricted galleries globally; restriction is
        // therefore required to exercise the negative assignment boundary.
        $unassignedGallery->update(['restricted_photographers' => true]);
        $photographer->photographerGalleryGroups()->attach($child->id);

        $response = $this->actingAs($photographer, 'api')
            ->getJson("/api/management/gallery-groups/{$parent->id}")
            ->assertOk()
            ->assertJsonCount(1, 'gallery_pricing_sources')
            ->assertJsonPath('gallery_pricing_sources.0.gallery_id', $assignedGallery->id)
            ->assertJsonPath('gallery_pricing_sources.0.gallery_group_id', $child->id)
            ->assertJsonPath('total', 1);

        $this->assertSame(
            [$assignedGallery->id],
            collect($response->json('photos'))->pluck('gallery_id')->all(),
        );
    }

    public function test_empty_meta_gallery_returns_an_empty_pricing_summary(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, Brand::B2B);
        $group = GalleryGroup::factory()->create(['brand' => Brand::B2B]);

        $this->actingAs($admin, 'api')
            ->getJson("/api/management/gallery-groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('gallery_pricing_sources', [])
            ->assertJsonPath('photos', [])
            ->assertJsonPath('total', 0);
    }

    private function userWithRole(UserRole $role, Brand $brand): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    private function gallery(
        GalleryGroup $group,
        string $licensingMode,
        string $name,
        int $photoCount,
        User $owner,
        ?VolumePreset $volumePreset = null,
    ): Gallery {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'gallery_group_id' => $group->id,
            'name' => $name,
            'type' => 'delivery',
            'is_public' => true,
            'licensing_mode' => $licensingMode,
            'volume_preset_id' => $volumePreset?->id,
        ]);
        if ($photoCount > 0) {
            Photo::factory()->count($photoCount)->create([
                'gallery_id' => $gallery->id,
                'user_id' => $owner->id,
            ]);
        }

        return $gallery;
    }

    /**
     * @param  array<int, array{gallery_id: string}>|null  $sources
     * @return Collection<string, array<string, mixed>>
     */
    private function pricingSourcesByGallery(?array $sources): Collection
    {
        return collect($sources ?? [])->keyBy('gallery_id');
    }
}
