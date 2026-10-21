<?php

namespace Tests\Feature\Authorization;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use App\Support\GalleryGroupSubtree;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * BASELINE — the measured cost of the brand filter of the reader. NOT a
 * target.
 *
 * The brand filter at the end of `AuthorizationService::getAllowedGalleryIds()`
 * resolves the complete group chain of every gallery. It does so set-based:
 * the candidate groups and their ancestors are loaded with `where ... in (...)`
 * and the brand decision is taken in memory. For the fixture of this test that
 * is two statements — one for the groups of the candidates, one for the
 * ancestor level above them — no matter how many galleries are read.
 *
 * Before that change the filter resolved the chain of every single gallery row
 * by row: `BrandRegistry::galleryTreeMatchesBrand()` read the gallery's own
 * group with `GalleryGroup::find()` and walked the ancestors with one more
 * lookup per level. For this fixture that was two lookups per gallery, so six
 * lookups for three galleries and thirty for fifteen.
 *
 * The pinned numbers pin a KNOWN COST. They are deliberately not a budget:
 * a later change of the resolution will (and should) turn this test red —
 * that is exactly what makes such a change visible instead of silent.
 *
 * The count is deterministic: one fixed fixture per size, no timing, no
 * parallel workers. The statements are counted with `DB::listen()` — the
 * mechanism this repository already uses for query-count assertions (see
 * `AuthorizationServiceTest` and `OrgAdminDelegationBoundaryTest`).
 *
 * The probe also pins the depth bound of the ancestor closure. The closure
 * is loaded at most `GalleryGroupSubtree::MAX_DEPTH` levels above the
 * candidate groups — the very reach of the shared brand walk, which inspects
 * the group it starts at and at most `MAX_DEPTH` ancestors above it and fails
 * closed beyond that. The deep fixture of this test carries a chain at that
 * limit (it still passes) and a chain beyond it (it fails closed), and the
 * measured number of lookups for the deep chain pins the bound: without it
 * the closure walks to the root of the tree and needs one lookup per level.
 */
class BrandFilterChainQueryBaselineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The measured number of group-chain lookups of the brand filter for
     * three galleries.
     */
    private const LOOKUPS_FOR_THREE_GALLERIES = 2;

    /**
     * The measured number of group-chain lookups of the brand filter for
     * fifteen galleries.
     */
    private const LOOKUPS_FOR_FIFTEEN_GALLERIES = 2;

    /**
     * The measured number of database statements of the reader for three
     * galleries.
     */
    private const STATEMENTS_FOR_THREE_GALLERIES = 7;

    /**
     * The measured number of database statements of the reader for fifteen
     * galleries.
     */
    private const STATEMENTS_FOR_FIFTEEN_GALLERIES = 7;

    /**
     * The number of groups in a chain that sits exactly at the shared depth
     * limit. The brand walk inspects the group of the gallery and at most
     * `GalleryGroupSubtree::MAX_DEPTH` ancestors above it, so such a chain
     * still passes — and the closure must still carry every one of them.
     */
    private const AT_LIMIT_CHAIN_GROUPS = GalleryGroupSubtree::MAX_DEPTH + 1;

    /**
     * The number of groups in a chain that is deeper than the shared depth
     * limit. Such a chain fails closed in both paths — the brand walk refuses
     * it, and the closure is not allowed to walk past the limit to find out
     * otherwise.
     */
    private const DEEP_CHAIN_GROUPS = GalleryGroupSubtree::MAX_DEPTH + 3;

    /**
     * The measured number of group-chain lookups of the brand filter for a
     * gallery in the deep chain: one statement for the candidate group plus
     * one per ancestor level, bounded by `GalleryGroupSubtree::MAX_DEPTH`
     * levels.
     */
    private const LOOKUPS_FOR_DEEP_CHAIN = GalleryGroupSubtree::MAX_DEPTH + 1;

    /**
     * The brand filter resolves the group chain set-based.
     *
     * The pinned numbers are the measured baseline of the fixture below: the
     * candidate groups of the galleries and the ancestor level above them,
     * so two lookups — for three galleries as well as for fifteen.
     */
    public function test_the_brand_filter_resolves_the_group_chain_set_based(): void
    {
        $threeLookups = $this->countChainLookups($this->countStatements($this->readerWithGalleries(3)));
        $fifteenLookups = $this->countChainLookups($this->countStatements($this->readerWithGalleries(15)));

        $this->assertSame(
            self::LOOKUPS_FOR_THREE_GALLERIES,
            $threeLookups,
            'The measured group-chain lookups of the brand filter changed. This is a known cost, not a '
            .'target: collecting the chain in one query is expected to change this number and to update this '
            .'test in the same change.'
        );

        $this->assertSame(
            self::LOOKUPS_FOR_FIFTEEN_GALLERIES,
            $fifteenLookups,
            'The measured group-chain lookups of the brand filter changed. This is a known cost, not a '
            .'target: collecting the chain in one query is expected to change this number and to update this '
            .'test in the same change.'
        );

        $this->assertSame(
            0,
            $fifteenLookups - $threeLookups,
            'The group chain must be resolved set-based: the lookups must not grow with the number of '
            .'galleries. A growing difference means the filter walks the chain per gallery again.'
        );
    }

    /**
     * The whole reader costs the same for three and for fifteen galleries,
     * because the brand filter is the only part that used to scale with the
     * number of galleries.
     */
    public function test_the_reader_cost_does_not_grow_with_the_number_of_galleries(): void
    {
        $three = $this->countStatements($this->readerWithGalleries(3));
        $fifteen = $this->countStatements($this->readerWithGalleries(15));

        $this->assertSame(self::STATEMENTS_FOR_THREE_GALLERIES, count($three));
        $this->assertSame(self::STATEMENTS_FOR_FIFTEEN_GALLERIES, count($fifteen));
        $this->assertSame(
            0,
            count($fifteen) - count($three),
            'The measured reader cost must not grow with the number of galleries — the brand filter resolves '
            .'the group chain set-based. A growing difference means the filter walks the chain per gallery '
            .'again.'
        );
    }

    /**
     * The set-based resolution must not change a single access decision.
     *
     * The probe computes the filter result twice over the same fixture set:
     * once the old way — per gallery, through
     * `BrandRegistry::galleryTreeMatchesBrand()` — and once through the
     * service. Both lists must be equal.
     *
     * The probe is also the drift canary of the shared rules: the set-based
     * filter decides with `BrandRegistry`'s own functions (the own-brand
     * match, the "no group" rule, the "missing group" rule and the group
     * tree), so a change of a rule in `BrandRegistry` moves *both* paths and
     * the first assertion stays green. Only the second assertion — which
     * pins the resulting behaviour — reports the change.
     *
     * The fixture carries the edge cases of the old per-gallery walk:
     *
     * - a gallery without a group (its own brand decides),
     * - a gallery whose group row does not exist (fails closed),
     * - a gallery whose parent group has no brand (fails closed),
     * - a gallery under a foreign-brand ancestor (fails closed),
     * - a gallery in a three-level chain (passes),
     * - a gallery whose own brand differs (fails closed),
     * - a gallery in a chain at the shared depth limit (passes),
     * - a gallery in a chain beyond the shared depth limit (fails closed).
     */
    #[Group('smoke')]
    public function test_the_set_based_brand_filter_selects_the_same_galleries_as_the_per_gallery_walk(): void
    {
        $fixture = $this->brandFilterFixture();

        $perGallery = $this->perGalleryBrandFilter($fixture['gallery_ids'], Brand::B2B->value);
        $setBased = app(AuthorizationService::class)->getAllowedGalleryIds($fixture['user']);

        $this->assertSame(
            $this->sorted($perGallery),
            $this->sorted($setBased),
            'The set-based brand filter must select exactly the galleries the per-gallery walk selected.'
        );

        $this->assertSame(
            $this->sorted([
                $fixture['without_group'],
                $fixture['three_level_chain'],
                $fixture['at_limit_chain'],
            ]),
            $this->sorted($setBased),
            'Only the gallery without a group, the gallery in the three-level chain and the gallery in the '
            .'chain at the depth limit may pass the brand filter of the fixture: every other fixture row '
            .'carries a missing, brand-less or foreign brand in its tree, or sits in a chain beyond the '
            .'depth limit.'
        );
    }

    /**
     * The brand filter bounds the ancestor closure at the shared depth limit.
     *
     * The closure is loaded at most `GalleryGroupSubtree::MAX_DEPTH` levels
     * above the candidate groups — the very reach of the brand walk, which
     * inspects the group it starts at and at most `MAX_DEPTH` ancestors above
     * it and fails closed beyond that. A chain deeper than the limit must
     * therefore still fail closed, and the closure must not load the
     * ancestors above the limit: the pinned number of lookups turns red when
     * the bound is removed, because the closure then walks to the root of the
     * tree and needs one lookup per level.
     */
    public function test_the_brand_filter_bounds_the_ancestor_closure_at_the_shared_depth_limit(): void
    {
        $fixture = $this->deepChainFixture();

        $perGallery = $this->perGalleryBrandFilter($fixture['gallery_ids'], Brand::B2B->value);
        $setBased = app(AuthorizationService::class)->getAllowedGalleryIds($fixture['user']);

        $this->assertSame(
            $this->sorted($perGallery),
            $this->sorted($setBased),
            'A chain beyond the shared depth limit must fail closed in both paths.'
        );

        $this->assertSame(
            [],
            $this->sorted($setBased),
            'The gallery in the deep chain must not pass the brand filter: the brand walk fails closed above '
            .'the shared depth limit.'
        );

        $this->assertSame(
            self::LOOKUPS_FOR_DEEP_CHAIN,
            $this->countChainLookups($this->countStatements($fixture['user'])),
            'The ancestor closure must be bounded by the shared depth limit: the brand filter must not load '
            .'the ancestors above it. A growing number of lookups means the closure walks to the root of the '
            .'tree again.'
        );
    }

    /**
     * A reader with one gallery in a chain that is deeper than the shared
     * depth limit.
     *
     * @return array{user: User, gallery_ids: array<int, string>}
     */
    private function deepChainFixture(): array
    {
        $gallery = $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $this->groupChain(self::DEEP_CHAIN_GROUPS)->id,
        ]);

        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->galleries()->attach($gallery->id);

        return [
            'user' => $user,
            'gallery_ids' => [(string) $gallery->id],
        ];
    }

    /**
     * The statements of one reader call.
     *
     * @return array<int, string>
     */
    private function countStatements(User $user): array
    {
        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        app(AuthorizationService::class)->getAllowedGalleryIds($user->fresh());

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $statements;
    }

    /**
     * The group-chain lookups of the brand filter.
     *
     * The set-based resolution reads the candidate groups and their ancestors
     * with `where ... in (...)` — one statement per level, independent of the
     * number of galleries. The per-gallery walk that this replaced read the
     * gallery's own group and its ancestors with `GalleryGroup::find()`, one
     * statement per level and per gallery. Both shapes are counted, so that a
     * revert to the per-gallery walk shows up in this number.
     *
     * @param  array<int, string>  $statements
     */
    private function countChainLookups(array $statements): int
    {
        return count(array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, 'from "gallery_groups"')
                && (
                    str_contains($sql, 'where "gallery_groups"."id" = ?')
                    || str_contains($sql, 'select "id", "parent_id", "brand" from "gallery_groups"')
                )
        ));
    }

    /**
     * The brand filter the way it was resolved before the set-based change:
     * one `GalleryGroup::find()` walk per gallery.
     *
     * @param  array<int, string>  $galleryIds
     * @return array<int, string>
     */
    private function perGalleryBrandFilter(array $galleryIds, string $actorBrand): array
    {
        return Gallery::whereIn('id', $galleryIds)
            ->where('brand', $actorBrand)
            ->get()
            ->filter(fn (Gallery $gallery): bool => BrandRegistry::galleryTreeMatchesBrand($gallery, $actorBrand))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * A brand-bound reader with `$galleryCount` delivery galleries.
     *
     * Every gallery sits in the same child group, and that group has a
     * parent: the brand filter therefore resolves two group levels per
     * gallery (the gallery's own group and its parent).
     */
    private function readerWithGalleries(int $galleryCount): User
    {
        $root = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => null,
        ]);
        $child = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $root->id,
        ]);

        $user = User::factory()->create(['brand' => Brand::B2B]);

        foreach (range(1, $galleryCount) as $ignored) {
            $gallery = Gallery::factory()->create([
                'brand' => Brand::B2B,
                'gallery_group_id' => $child->id,
                'type' => 'delivery',
            ]);
            $user->galleries()->attach($gallery->id);
        }

        return $user;
    }

    /**
     * The fixture of the equivalence probe.
     *
     * @return array{
     *     user: User,
     *     gallery_ids: array<int, string>,
     *     without_group: string,
     *     missing_group: string,
     *     null_brand_parent: string,
     *     foreign_ancestor: string,
     *     three_level_chain: string,
     *     own_brand_differs: string,
     *     at_limit_chain: string,
     *     beyond_limit_chain: string
     * }
     */
    private function brandFilterFixture(): array
    {
        // 1. A gallery without a group: its own brand decides.
        $withoutGroup = $this->gallery(['brand' => Brand::B2B, 'gallery_group_id' => null]);

        // 2. A gallery whose group row does not exist. The foreign key
        //    forbids that state, so the fixture is written with a deferred
        //    foreign key check; the test transaction is rolled back, so the
        //    deferred check never runs.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $missingGroup = $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => (string) Str::uuid(),
        ]);
        DB::statement('PRAGMA defer_foreign_keys = OFF');

        // 3. A gallery whose parent group has no brand: the chain fails.
        $nullBrandParent = GalleryGroup::factory()->create(['brand' => null]);
        $nullBrandChild = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $nullBrandParent->id,
        ]);
        $underNullBrandParent = $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $nullBrandChild->id,
        ]);

        // 4. A gallery under a foreign-brand ancestor: the chain fails.
        $foreignRoot = GalleryGroup::factory()->create(['brand' => 'srp']);
        $foreignChild = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $foreignRoot->id,
        ]);
        $underForeignAncestor = $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $foreignChild->id,
        ]);

        // 5. A gallery in a three-level chain: every level carries the brand.
        $root = GalleryGroup::factory()->create(['brand' => Brand::B2B]);
        $middle = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => $root->id]);
        $leaf = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => $middle->id]);
        $threeLevelChain = $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $leaf->id,
        ]);

        // 6. A gallery whose own brand differs: it never passes.
        $ownBrandDiffers = $this->gallery([
            'brand' => 'srp',
            'gallery_group_id' => $root->id,
        ]);

        // 7. A gallery in a chain that sits exactly at the shared depth
        //    limit: the brand walk still reaches the root of that chain, so
        //    the gallery passes.
        $atLimit = $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $this->groupChain(self::AT_LIMIT_CHAIN_GROUPS)->id,
        ]);

        // 8. A gallery in a chain that is deeper than the shared depth
        //    limit: the brand walk fails closed above the limit, so the
        //    gallery never passes.
        $beyondLimit = $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $this->groupChain(self::DEEP_CHAIN_GROUPS)->id,
        ]);

        $galleries = [
            $withoutGroup,
            $missingGroup,
            $underNullBrandParent,
            $underForeignAncestor,
            $threeLevelChain,
            $ownBrandDiffers,
            $atLimit,
            $beyondLimit,
        ];

        $user = User::factory()->create(['brand' => Brand::B2B]);
        foreach ($galleries as $gallery) {
            $user->galleries()->attach($gallery->id);
        }

        return [
            'user' => $user,
            'gallery_ids' => array_map(fn (Gallery $gallery): string => (string) $gallery->id, $galleries),
            'without_group' => (string) $withoutGroup->id,
            'missing_group' => (string) $missingGroup->id,
            'null_brand_parent' => (string) $underNullBrandParent->id,
            'foreign_ancestor' => (string) $underForeignAncestor->id,
            'three_level_chain' => (string) $threeLevelChain->id,
            'own_brand_differs' => (string) $ownBrandDiffers->id,
            'at_limit_chain' => (string) $atLimit->id,
            'beyond_limit_chain' => (string) $beyondLimit->id,
        ];
    }

    /**
     * A chain of gallery groups that all carry the brand, the deepest group
     * last.
     *
     * The chain is built from the root downwards, so the returned group is
     * the deepest one and the chain holds `$groupCount` groups in total.
     */
    private function groupChain(int $groupCount): GalleryGroup
    {
        $deepest = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);

        for ($level = 1; $level < $groupCount; $level++) {
            $deepest = GalleryGroup::factory()->create([
                'brand' => Brand::B2B,
                'parent_id' => $deepest->id,
            ]);
        }

        return $deepest;
    }

    /**
     * A gallery fixture that does not talk to the search index.
     */
    private function gallery(array $attributes): Gallery
    {
        return Gallery::withoutSyncingToSearch(
            fn (): Gallery => Gallery::factory()->create($attributes)
        );
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
