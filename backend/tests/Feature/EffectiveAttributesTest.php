<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EffectiveAttributesTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // effective_*-Accessoren (Dataprovider für Gallery + GalleryGroup)
    // -----------------------------------------------------------------------

    #[DataProvider('effectiveEntityProvider')]
    public function test_effective_attribute(string $entity, string $attribute, mixed $ownValue, array $ancestorValues, bool $expected): void
    {
        $parentId = null;
        foreach (array_reverse($ancestorValues) as $value) {
            $ancestor = GalleryGroup::factory()->create([
                $attribute => $value,
                'parent_id' => $parentId,
            ]);
            $parentId = $ancestor->id;
        }

        if ($entity === 'gallery') {
            $entityModel = Gallery::factory()->create([
                $attribute => $ownValue,
                'gallery_group_id' => $parentId,
            ]);
        } else {
            $entityModel = GalleryGroup::factory()->create([
                $attribute => $ownValue,
                'parent_id' => $parentId,
            ]);
        }

        $effectiveAttribute = 'effective_' . $attribute;
        $this->assertSame($expected, $entityModel->$effectiveAttribute);
    }

    public static function effectiveEntityProvider(): array
    {
        return [
            // Gallery :: effective_is_editorial_only (||-Kaskade)
            'is_editorial_only: Gallery own true' => [
                'gallery', 'is_editorial_only', true, [], true,
            ],
            'is_editorial_only: Gallery own false' => [
                'gallery', 'is_editorial_only', false, [], false,
            ],
            'is_editorial_only: Gallery inherit from group' => [
                'gallery', 'is_editorial_only', false, [true], true,
            ],
            'is_editorial_only: Gallery cascade from grandparent' => [
                'gallery', 'is_editorial_only', false, [false, true], true,
            ],

            // Gallery :: effective_is_free_download (||-Kaskade)
            'is_free_download: Gallery own true' => [
                'gallery', 'is_free_download', true, [], true,
            ],
            'is_free_download: Gallery inherit from group' => [
                'gallery', 'is_free_download', false, [true], true,
            ],
            'is_free_download: Gallery all false' => [
                'gallery', 'is_free_download', false, [false], false,
            ],

            // Gallery :: effective_is_hidden (||-Kaskade)
            'is_hidden: Gallery own true' => [
                'gallery', 'is_hidden', true, [], true,
            ],
            'is_hidden: Gallery cascade from grandparent' => [
                'gallery', 'is_hidden', false, [false, true], true,
            ],

            // Gallery :: effective_restricted_photographers (!== null — bricht bei explizitem Wert)
            'restricted_photographers: Gallery own true wins' => [
                'gallery', 'restricted_photographers', true, [false], true,
            ],
            'restricted_photographers: Gallery explicit false breaks cascade' => [
                'gallery', 'restricted_photographers', false, [true], false,
            ],
            'restricted_photographers: Gallery null inherits from group true' => [
                'gallery', 'restricted_photographers', null, [true], true,
            ],
            'restricted_photographers: Gallery null inherits from group null chain' => [
                'gallery', 'restricted_photographers', null, [null, null], false,
            ],
            'restricted_photographers: Gallery null inherits true from grandparent' => [
                'gallery', 'restricted_photographers', null, [null, true], true,
            ],

            // GalleryGroup :: effective_*
            'is_editorial_only: Group inherit from parent' => [
                'group', 'is_editorial_only', false, [true], true,
            ],
            'is_free_download: Group own true with false parent' => [
                'group', 'is_free_download', true, [false], true,
            ],
            'restricted_photographers: Group explicit false breaks cascade' => [
                'group', 'restricted_photographers', false, [true], false,
            ],
            'restricted_photographers: Group null inherits true' => [
                'group', 'restricted_photographers', null, [true], true,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Gallery::getFullPathAttribute (Dataprovider)
    // -----------------------------------------------------------------------

    #[DataProvider('fullPathProvider')]
    public function test_full_path(string $ownSlug, ?array $groupSlugs, string $expected): void
    {
        $parentId = null;
        foreach ((array) $groupSlugs as $slug) {
            $group = GalleryGroup::factory()->create([
                'slug' => $slug,
                'parent_id' => $parentId,
            ]);
            $parentId = $group->id;
        }

        $gallery = Gallery::factory()->create([
            'slug' => $ownSlug,
            'gallery_group_id' => $parentId,
        ]);

        $this->assertSame($expected, $gallery->full_path);
    }

    public static function fullPathProvider(): array
    {
        return [
            'without group' => ['my-gallery', null, 'galleries/my-gallery'],
            'with single group' => ['ceremony', ['weddings'], 'galleries/weddings/ceremony'],
            'cascade multiple levels' => ['paris', ['2024', 'europe'], 'galleries/2024/europe/paris'],
        ];
    }

    // -----------------------------------------------------------------------
    // Zyklus-Schutz-Terminierung (Dataprovider)
    // -----------------------------------------------------------------------

    #[DataProvider('fullPathCycleProvider')]
    public function test_full_path_terminates_on_cycle(string $scenario, string $slug): void
    {
        if ($scenario === 'circular') {
            $a = GalleryGroup::factory()->create(['slug' => 'a']);
            $b = GalleryGroup::factory()->create(['slug' => 'b', 'parent_id' => $a->id]);
            DB::table('gallery_groups')->where('id', $a->id)->update(['parent_id' => $b->id]);
            $root = GalleryGroup::find($a->id);
        } else {
            $root = GalleryGroup::factory()->create(['slug' => 'self']);
            DB::table('gallery_groups')->where('id', $root->id)->update(['parent_id' => $root->id]);
            $root = GalleryGroup::find($root->id);
        }

        $gallery = Gallery::factory()->create([
            'slug' => $slug,
            'gallery_group_id' => $root->id,
        ]);

        $path = $gallery->full_path;

        $this->assertStringStartsWith('galleries/', $path);
        $this->assertStringContainsString($slug, $path);
    }

    public static function fullPathCycleProvider(): array
    {
        return [
            'circular A→B→A' => ['circular', 'shoot'],
            'self-referencing' => ['self', 'g'],
        ];
    }

    public function test_effective_accessor_terminates_on_self_reference(): void
    {
        $group = GalleryGroup::factory()->create(['is_editorial_only' => false]);
        DB::table('gallery_groups')->where('id', $group->id)->update(['parent_id' => $group->id]);

        $group = GalleryGroup::find($group->id);

        $this->assertFalse($group->effective_is_editorial_only);
        $this->assertFalse($group->effective_is_hidden);
        $this->assertFalse($group->effective_is_free_download);
        $this->assertFalse($group->effective_restricted_photographers);
    }

    public function test_effective_accessor_finds_true_in_circular_chain(): void
    {
        $a = GalleryGroup::factory()->create(['slug' => 'a', 'is_editorial_only' => true]);
        $b = GalleryGroup::factory()->create(['slug' => 'b', 'is_editorial_only' => false, 'parent_id' => $a->id]);

        DB::table('gallery_groups')->where('id', $a->id)->update(['parent_id' => $b->id]);

        $b = GalleryGroup::find($b->id);

        $this->assertTrue($b->effective_is_editorial_only);
    }

    // -----------------------------------------------------------------------
    // DB-Schicht: saving-Hook (Dataprovider)
    // -----------------------------------------------------------------------

    #[DataProvider('cyclicParentChainProvider')]
    public function test_saving_rejects_cyclic_parent_chain(string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);

        if ($type === 'self-reference') {
            $group = GalleryGroup::factory()->create();
            $group->parent_id = $group->id;
            $group->save();
        } else {
            $a = GalleryGroup::factory()->create();
            $b = GalleryGroup::factory()->create(['parent_id' => $a->id]);
            $a->parent_id = $b->id;
            $a->save();
        }
    }

    public static function cyclicParentChainProvider(): array
    {
        return [
            'self-reference' => ['self-reference'],
            'circular A→B→A' => ['circular'],
        ];
    }

    public function test_saving_allows_deep_acyclic_chain(): void
    {
        $root = GalleryGroup::factory()->create(['parent_id' => null]);
        $current = $root;

        for ($i = 0; $i < 5; $i++) {
            $current = GalleryGroup::factory()->create(['parent_id' => $current->id]);
        }

        $this->assertNotNull($current->parent_id);
        $this->assertIsBool($current->effective_is_editorial_only);
    }
}
