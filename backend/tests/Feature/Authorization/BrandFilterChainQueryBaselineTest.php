<?php

namespace Tests\Feature\Authorization;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BASELINE — the measured cost of the brand filter of the reader. NOT a
 * target.
 *
 * The brand filter at the end of `AuthorizationService::getAllowedGalleryIds()`
 * resolves the complete group chain of every single gallery row by row:
 * `BrandRegistry::galleryTreeMatchesBrand()` reads the gallery's own group
 * with `GalleryGroup::find()` and walks the ancestors with one more lookup
 * per level. For the fixture of this test that is two lookups per gallery.
 *
 * This is a PRE-EXISTING N+1. D-39 did not change it and did not worsen it;
 * the read-time re-validation it introduced is set-based and is pinned
 * separately in `AuthorizationServiceTest`.
 *
 * The pinned numbers pin a KNOWN COST. They are deliberately not a target: a
 * later change that collects the group chain in one query will (and should)
 * turn this test red — that is exactly what makes such an optimisation
 * visible instead of silent.
 *
 * The count is deterministic: one fixed fixture per size, no timing, no
 * parallel workers. The statements are counted with `DB::listen()` — the
 * mechanism this repository already uses for query-count assertions (see
 * `AuthorizationServiceTest` and `OrgAdminDelegationBoundaryTest`).
 */
class BrandFilterChainQueryBaselineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The measured number of per-gallery group lookups for three galleries.
     */
    private const LOOKUPS_FOR_THREE_GALLERIES = 6;

    /**
     * The measured number of per-gallery group lookups for fifteen galleries.
     */
    private const LOOKUPS_FOR_FIFTEEN_GALLERIES = 30;

    /**
     * The measured number of database statements of the reader for three
     * galleries.
     */
    private const STATEMENTS_FOR_THREE_GALLERIES = 11;

    /**
     * The measured number of database statements of the reader for fifteen
     * galleries.
     */
    private const STATEMENTS_FOR_FIFTEEN_GALLERIES = 35;

    /**
     * The brand filter resolves the group chain of every gallery on its own.
     *
     * The pinned numbers are the measured baseline of the fixture below: two
     * `GalleryGroup::find()` lookups per gallery (the gallery's own group and
     * its parent), so six lookups for three galleries and thirty for fifteen.
     */
    public function test_the_brand_filter_resolves_the_group_chain_per_gallery(): void
    {
        $threeLookups = $this->countChainLookups($this->countStatements($this->readerWithGalleries(3)));
        $fifteenLookups = $this->countChainLookups($this->countStatements($this->readerWithGalleries(15)));

        $this->assertSame(
            self::LOOKUPS_FOR_THREE_GALLERIES,
            $threeLookups,
            'The measured per-gallery group lookups of the brand filter changed. This is a known cost, not a '
            .'target: collecting the chain in one query is expected to change this number and to update this '
            .'test in the same change.'
        );

        $this->assertSame(
            self::LOOKUPS_FOR_FIFTEEN_GALLERIES,
            $fifteenLookups,
            'The measured per-gallery group lookups of the brand filter changed. This is a known cost, not a '
            .'target: collecting the chain in one query is expected to change this number and to update this '
            .'test in the same change.'
        );

        $this->assertSame(
            2 * (15 - 3),
            $fifteenLookups - $threeLookups,
            'The brand filter must keep growing with the number of galleries — that growth is the N+1 this test '
            .'pins. A constant difference would mean the filter no longer walks the chain per gallery.'
        );
    }

    /**
     * The whole reader costs two statements more per gallery, because the
     * brand filter is the only part that scales with the number of galleries.
     */
    public function test_the_reader_cost_grows_with_the_number_of_galleries(): void
    {
        $three = $this->countStatements($this->readerWithGalleries(3));
        $fifteen = $this->countStatements($this->readerWithGalleries(15));

        $this->assertSame(self::STATEMENTS_FOR_THREE_GALLERIES, count($three));
        $this->assertSame(self::STATEMENTS_FOR_FIFTEEN_GALLERIES, count($fifteen));
        $this->assertSame(
            2 * (15 - 3),
            count($fifteen) - count($three),
            'The measured reader cost must grow by two statements per additional gallery — that growth is the '
            .'per-gallery chain resolution this baseline pins.'
        );
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
     * The per-gallery group lookups of the brand filter.
     *
     * `BrandRegistry::galleryTreeMatchesBrand()` reads the gallery's own group
     * and its ancestors with `GalleryGroup::find()`, one statement per level.
     * The set-based statements of the reader use `where ... in (...)` and are
     * therefore not counted here.
     *
     * @param  array<int, string>  $statements
     */
    private function countChainLookups(array $statements): int
    {
        return count(array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, 'from "gallery_groups" where "gallery_groups"."id" = ?')
        ));
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
}
