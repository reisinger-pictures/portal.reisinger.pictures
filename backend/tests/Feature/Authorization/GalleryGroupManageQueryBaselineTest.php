<?php

namespace Tests\Feature\Authorization;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use App\Support\GalleryGroupSubtree;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BASELINE — the measured cost of the group management path. NOT a target.
 *
 * `AuthorizationService::canManageGalleryGroup()` decides whether an actor
 * may manage a gallery group (rename/reparent/delete, sync access). The
 * decision walks the chain of the group and — for a photographer without a
 * personal assignment to that group — the galleries of the group's subtree.
 *
 * The chain of a gallery was resolved per gallery: `canManageGallery()` reads
 * the gallery row and `BrandRegistry::galleryTreeMatchesBrand()` reads the
 * gallery's own group with `GalleryGroup::find()`, one lookup per level of the
 * chain. A subtree with N galleries therefore paid the chain N times.
 *
 * The group chain is resolved set-based now: the subtree's groups and their
 * ancestor closure are loaded once and the brand walk reads the parents from
 * that map. The subtree's gallery list is loaded once as well, and the role
 * decisions are taken before the loop over the galleries.
 *
 * What no longer scales with the number of galleries of the subtree are the
 * *group-chain lookups*: they depend on the depth of the group tree only. The
 * number of statements as a whole still grows with the number of galleries,
 * by about one statement per restricted gallery (the personal assignment of
 * the gallery) and by one statement plus the depth of the group's chain per
 * gallery whose own restriction flag is null (the group load of
 * `Gallery::effective_restricted_photographers`). Measured on the subtree
 * fixture below: 1 gallery 18 → 10 statements, 3 galleries 40 → 12, 10
 * galleries 117 → 19 — a slope of about 1 statement per gallery instead of
 * about 11.7. The gain is a range, not a constant: the fixture cases gain
 * between 0 and 10 statements (8–10 for the typical restricted-gallery case),
 * and the subtree fixture gains 8 statements with one gallery, 28 with three
 * and 98 with ten. The loop over the galleries is skipped only for a
 * photographer who holds the *target* group itself; an assignment to an
 * ancestor or to a descendant of the target group does not skip it.
 *
 * The pinned numbers pin a KNOWN COST. They are deliberately not a budget:
 * a later change of the resolution will (and should) turn this test red —
 * that is exactly what makes such a change visible instead of silent.
 *
 * The count is deterministic: one fixed fixture per case, no timing, no
 * parallel workers. The statements are counted with `DB::listen()` — the
 * mechanism this repository already uses for query-count assertions (see
 * `BrandFilterChainQueryBaselineTest` and `OrgScopeQueryBaselineTest`).
 *
 * The probe also pins the *decision* of every fixture case and proves it
 * against the per-gallery walk this change replaced: the pre-optimisation
 * implementation is reproduced in the probe (`perGalleryCanManageGalleryGroup()`)
 * and must decide exactly the same. A change that lets the two paths diverge
 * turns this test red.
 *
 * The fixture carries the edge cases the method handles:
 *
 * - a group at the root (its own brand decides),
 * - a nested group (the chain of the group decides),
 * - a foreign-brand ancestor (the chain fails closed),
 * - a null brand (a brand-bound actor may not manage it),
 * - an empty subtree (a photographer may manage an empty group of their brand),
 * - a group the caller has no relation to (the subtree's galleries decide),
 * - the same group with a gallery the caller owns (the loop finds one),
 * - the same group with the caller holding the group itself (the personal
 *   assignment decides before the loop),
 * - a chain deeper than the shared depth limit (it fails closed),
 * - a null-brand actor with a null-brand group (the gallery's own brand decides),
 * - a gallery of a foreign brand in the subtree (the gallery's own brand decides),
 * - a gallery in a group below the target group (the chain of the gallery runs
 *   through the target group and its ancestors),
 * - an *unrestricted* gallery of a foreign brand in a same-brand group (the
 *   gallery's own brand decides — the rule the restricted fixture of a
 *   foreign brand does not pin),
 * - an *unrestricted* gallery of the actor's own brand (the gallery's own
 *   restriction rule decides),
 * - a gallery in a group the photographer holds through a child group and one
 *   through a parent group (the subtree of the photographer's own groups
 *   decides),
 * - a gallery in a group whose *intermediate* ancestor has a foreign brand
 *   (the chain of the gallery's own group decides and fails closed).
 */
class GalleryGroupManageQueryBaselineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The measured number of statements of one `canManageGalleryGroup()` call
     * per fixture case.
     *
     * Before the set-based resolution the same fixture cost:
     *
     * | case                                        | before | after |
     * |---------------------------------------------|--------|-------|
     * | root_group_admin                            |      2 |     2 |
     * | nested_group_admin                          |      3 |     3 |
     * | foreign_ancestor_photographer               |      1 |     1 |
     * | null_brand_group_photographer               |      0 |     0 |
     * | empty_subtree_photographer                  |      7 |     7 |
     * | unrelated_group_photographer                |     18 |    10 |
     * | own_restricted_gallery_photographer         |     17 |     9 |
     * | own_group_photographer                      |      4 |     4 |
     * | deep_chain_photographer                     |     11 |    10 |
     * | null_brand_photographer                     |      1 |     1 |
     * | foreign_brand_gallery_photographer          |      8 |     8 |
     * | nested_subtree_gallery_photographer         |     20 |    10 |
     * | unrestricted_foreign_brand_gallery_...      |      8 |     8 |
     * | unrestricted_gallery_photographer           |     16 |     8 |
     * | group_scope_child_group_photographer        |     23 |    13 |
     * | group_scope_parent_group_photographer       |     24 |    14 |
     * | foreign_intermediate_ancestor_photographer  |     12 |    10 |
     *
     * The cases that do not reach the galleries of the subtree keep their cost:
     * they are decided by the chain of the group, by the brand of the group
     * or by a role, and none of them walks a gallery. The cases that do walk
     * the subtree are the ones that get cheaper — and the more galleries the
     * subtree holds, the more the *group-chain lookups* drop out of the
     * count. The total still grows by about one statement per restricted
     * gallery (the personal-assignment check). The gain is a range, not a
     * constant: the cases above gain between 0 and 10 statements, and the
     * subtree fixture of {@see SUBTREE_PAIRS} gains 8 statements with one
     * gallery, 28 with three and 98 with ten.
     */
    private const STATEMENTS = [
        'root_group_admin' => 2,
        'nested_group_admin' => 3,
        'foreign_ancestor_photographer' => 1,
        'null_brand_group_photographer' => 0,
        'empty_subtree_photographer' => 7,
        'unrelated_group_photographer' => 10,
        'own_restricted_gallery_photographer' => 9,
        'own_group_photographer' => 4,
        'deep_chain_photographer' => 10,
        'null_brand_photographer' => 1,
        'foreign_brand_gallery_photographer' => 8,
        'nested_subtree_gallery_photographer' => 10,
        'unrestricted_foreign_brand_gallery_photographer' => 8,
        'unrestricted_gallery_photographer' => 8,
        'group_scope_child_group_photographer' => 13,
        'group_scope_parent_group_photographer' => 14,
        'foreign_intermediate_ancestor_photographer' => 10,
    ];

    /**
     * The group chain of the galleries of a subtree is resolved set-based: no
     * gallery of the subtree is resolved with a `GalleryGroup::find()`.
     */
    private const PER_GALLERY_CHAIN_FINDS = 0;

    /**
     * The statements of the per-gallery walk and of the set-based resolution
     * for the subtree fixture, per number of galleries.
     *
     * These are the measured pairs the scaling claim rests on: the
     * per-gallery walk resolved the chain of every gallery on its own, the
     * set-based resolution reads the chain from the loaded map. The total
     * still grows with the number of galleries — by about one statement per
     * restricted gallery — but the slope drops from about 11.7 to about 1
     * statement per gallery.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    private const SUBTREE_PAIRS = [
        1 => [18, 10],
        3 => [40, 12],
        10 => [117, 19],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'null']);
        BrandRegistry::set(Brand::B2B);
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(Brand::B2B);
        BrandRegistry::clearCache();

        parent::tearDown();
    }

    /**
     * The decision of every fixture case.
     *
     * The decision is pinned twice: against the expected answer of the case
     * and against the pre-optimisation implementation, which resolves the
     * group chain per gallery. Both must agree with the service.
     */
    public function test_the_group_management_decides_every_fixture_case(): void
    {
        foreach ($this->fixture() as $name => $case) {
            $decision = app(AuthorizationService::class)->canManageGalleryGroup($case['user'], $case['group']);

            $this->assertSame(
                $case['allowed'],
                $decision,
                "The decision of the fixture case [{$name}] changed."
            );

            $this->assertSame(
                $this->perGalleryCanManageGalleryGroup($case['user'], $case['group']),
                $decision,
                "The set-based resolution of the fixture case [{$name}] must decide exactly what the "
                .'per-gallery walk decided.'
            );
        }
    }

    /**
     * The measured cost of one `canManageGalleryGroup()` call per fixture case.
     *
     * The pinned numbers are the measured baseline of the fixture below. They
     * are a known cost, not a target: collecting the chain in one query is
     * expected to change these numbers and to update this test in the same
     * change.
     */
    public function test_the_group_management_costs_a_pinned_number_of_statements(): void
    {
        $measured = [];

        foreach ($this->fixture() as $name => $case) {
            $statements = $this->collectStatements(
                fn (): bool => app(AuthorizationService::class)->canManageGalleryGroup($case['user'], $case['group'])
            );

            $measured[$name] = count($statements);
        }

        foreach ($measured as $name => $count) {
            $this->assertSame(
                self::STATEMENTS[$name],
                $count,
                "The measured number of statements of the fixture case [{$name}] changed. This is a known "
                .'cost, not a target: a deliberate change of the cost is expected to turn this test red and '
                .'to update this test in the same change.'
            );
        }
    }

    /**
     * The group chain of the galleries of a subtree is resolved set-based.
     *
     * The subtree's groups and their ancestor closure are loaded once; no
     * gallery of the subtree is resolved with a `GalleryGroup::find()`. The
     * number of group-chain lookups therefore does not grow with the number
     * of galleries of the subtree.
     *
     * The gallery's own restriction flag is `true` in the first variant and
     * `null` in the second: a null flag makes the model load the gallery's
     * group to inherit the restriction. That load has the same SQL shape as a
     * chain lookup — it resolves the gallery's *own* group, not the chain
     * above it — and must therefore not be counted as a chain lookup.
     */
    public function test_the_group_chain_of_the_subtree_is_resolved_set_based(): void
    {
        foreach ([true, null] as $ownRestriction) {
            $one = $this->nestedSubtreeWithGalleries(1, $ownRestriction);
            $three = $this->nestedSubtreeWithGalleries(3, $ownRestriction);

            $oneStatements = $this->collectStatements(
                fn (): bool => app(AuthorizationService::class)->canManageGalleryGroup($one['user'], $one['group'])
            );
            $threeStatements = $this->collectStatements(
                fn (): bool => app(AuthorizationService::class)->canManageGalleryGroup($three['user'], $three['group'])
            );

            $this->assertSame(
                self::PER_GALLERY_CHAIN_FINDS,
                $this->countPerGalleryChainFinds($oneStatements, $one['chainIds']),
                'The group chain of a gallery of the subtree must not be resolved with a `GalleryGroup::find()` '
                ." (own restriction: {$this->describeRestriction($ownRestriction)}). "
                .'A growing number of these lookups means the chain is walked per gallery again.'
            );

            $this->assertSame(
                self::PER_GALLERY_CHAIN_FINDS,
                $this->countPerGalleryChainFinds($threeStatements, $three['chainIds']),
                'The group chain of a gallery of the subtree must not be resolved with a `GalleryGroup::find()` — '
                .'not even when the subtree holds more than one gallery (own restriction: '
                ."{$this->describeRestriction($ownRestriction)})."
            );

            $this->assertSame(
                $this->countChainStatements($oneStatements, $one['ownGroupIds']),
                $this->countChainStatements($threeStatements, $three['ownGroupIds']),
                'The group-chain lookups must not grow with the number of galleries of the subtree. A growing '
                .'difference means the chain is walked per gallery again.'
            );
        }
    }

    /**
     * The set-based resolution is cheaper than the per-gallery walk it
     * replaced.
     *
     * The per-gallery walk is reproduced in the probe, so both paths run over
     * the same fixture and the same decision. The measured pairs are pinned:
     * they are the numbers the scaling claim of the service rests on.
     */
    public function test_the_set_based_resolution_is_cheaper_than_the_per_gallery_walk(): void
    {
        foreach (self::SUBTREE_PAIRS as $galleryCount => [$perGalleryStatements, $setBasedStatements]) {
            $fixture = $this->subtreeWithGalleries($galleryCount);

            $perGallery = $this->collectStatements(
                fn (): bool => $this->perGalleryCanManageGalleryGroup($fixture['user'], $fixture['group'])
            );
            $setBased = $this->collectStatements(
                fn (): bool => app(AuthorizationService::class)->canManageGalleryGroup($fixture['user'], $fixture['group'])
            );

            $this->assertSame(
                $perGalleryStatements,
                count($perGallery),
                "The measured cost of the per-gallery walk for a subtree with {$galleryCount} galleries changed. "
                .'This is a known cost, not a target: a deliberate change of the cost is expected to turn this '
                .'test red and to update this test in the same change.'
            );

            $this->assertSame(
                $setBasedStatements,
                count($setBased),
                "The measured cost of the set-based resolution for a subtree with {$galleryCount} galleries "
                .'changed. This is a known cost, not a target: a deliberate change of the cost is expected to '
                .'turn this test red and to update this test in the same change.'
            );

            $this->assertGreaterThan(
                count($setBased),
                count($perGallery),
                'The per-gallery walk must cost more statements than the set-based resolution. If it does not, '
                .'the set-based resolution is not worth its complexity.'
            );
        }
    }

    /**
     * The fixture of the decision and cost probes.
     *
     * @return array<string, array{group: GalleryGroup, user: User, allowed: bool}>
     */
    private function fixture(): array
    {
        $cases = [];

        // 1. A group at the root: its own brand decides.
        $rootGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $cases['root_group_admin'] = [
            'group' => $rootGroup,
            'user' => $this->user(UserRole::ADMIN, Brand::B2B->value),
            'allowed' => true,
        ];

        // 2. A nested group: the chain of the group decides.
        $chainRoot = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $nestedGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => $chainRoot->id]);
        $cases['nested_group_admin'] = [
            'group' => $nestedGroup,
            'user' => $this->user(UserRole::ADMIN, Brand::B2B->value),
            'allowed' => true,
        ];

        // 3. A foreign-brand ancestor: the chain fails closed.
        $foreignRoot = GalleryGroup::factory()->create(['brand' => 'srp', 'parent_id' => null]);
        $foreignChild = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => $foreignRoot->id]);
        $cases['foreign_ancestor_photographer'] = [
            'group' => $foreignChild,
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
            'allowed' => false,
        ];

        // 4. A null brand: a brand-bound actor may not manage it.
        $nullBrandGroup = GalleryGroup::factory()->create(['brand' => null, 'parent_id' => null]);
        $cases['null_brand_group_photographer'] = [
            'group' => $nullBrandGroup,
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
            'allowed' => false,
        ];

        // 5. An empty subtree: a photographer may manage an empty group of
        //    their own brand (a group has no separate ownership marker).
        $emptyGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $cases['empty_subtree_photographer'] = [
            'group' => $emptyGroup,
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
            'allowed' => true,
        ];

        // 6. A group the caller has no relation to: the galleries of the
        //    subtree decide. The gallery is restricted, so the caller may not
        //    manage it.
        $unrelatedGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $unrelatedGroup->id,
            'restricted_photographers' => true,
        ]);
        $cases['unrelated_group_photographer'] = [
            'group' => $unrelatedGroup,
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
            'allowed' => false,
        ];

        // 7. The same case, but the caller owns the restricted gallery.
        $ownedGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $ownedGallery = $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $ownedGroup->id,
            'restricted_photographers' => true,
        ]);
        $owner = $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value);
        $owner->photographerGalleries()->attach($ownedGallery->id);
        $cases['own_restricted_gallery_photographer'] = [
            'group' => $ownedGroup,
            'user' => $owner,
            'allowed' => true,
        ];

        // 8. The caller holds the group itself: the personal assignment
        //    decides before the loop over the subtree.
        $assignedGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $assignedGroup->id,
            'restricted_photographers' => true,
        ]);
        $assignee = $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value);
        $assignee->photographerGalleryGroups()->attach($assignedGroup->id);
        $cases['own_group_photographer'] = [
            'group' => $assignedGroup,
            'user' => $assignee,
            'allowed' => true,
        ];

        // 9. A chain deeper than the shared depth limit: it fails closed.
        $deepGroup = $this->groupChain(GalleryGroupSubtree::MAX_DEPTH + 3);
        $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $deepGroup->id,
            'restricted_photographers' => true,
        ]);
        $cases['deep_chain_photographer'] = [
            'group' => $deepGroup,
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
            'allowed' => false,
        ];

        // 10. A null-brand actor with a null-brand group: the gallery's own
        //     brand decides, and a null brand never matches a null actor.
        $nullBrandGroupWithGallery = GalleryGroup::factory()->create(['brand' => null, 'parent_id' => null]);
        $this->gallery([
            'brand' => null,
            'gallery_group_id' => $nullBrandGroupWithGallery->id,
            'restricted_photographers' => true,
        ]);
        $cases['null_brand_photographer'] = [
            'group' => $nullBrandGroupWithGallery,
            'user' => $this->user(UserRole::PHOTOGRAPHER, null),
            'allowed' => false,
        ];

        // 11. A gallery of a foreign brand in the subtree: the gallery's own
        //     brand decides, and it never matches a brand-bound actor.
        $foreignBrandGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $this->gallery([
            'brand' => 'srp',
            'gallery_group_id' => $foreignBrandGroup->id,
            'restricted_photographers' => true,
        ]);
        $cases['foreign_brand_gallery_photographer'] = [
            'group' => $foreignBrandGroup,
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
            'allowed' => false,
        ];

        // 12. A gallery in a group below the target group: the chain of the
        //     gallery runs through the target group and its ancestors.
        $nestedSubtreeRoot = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $nestedSubtreeChild = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $nestedSubtreeRoot->id,
        ]);
        $nestedSubtreeGallery = $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $nestedSubtreeChild->id,
            'restricted_photographers' => true,
        ]);
        $nestedOwner = $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value);
        $nestedOwner->photographerGalleries()->attach($nestedSubtreeGallery->id);
        $cases['nested_subtree_gallery_photographer'] = [
            'group' => $nestedSubtreeRoot,
            'user' => $nestedOwner,
            'allowed' => true,
        ];

        // 13. An *unrestricted* gallery of a foreign brand in a same-brand
        //     group: the gallery's own brand decides, and a foreign brand
        //     never matches a brand-bound actor. The restricted gallery of
        //     case 11 does not pin this rule — it is denied by the
        //     restriction as well.
        $unrestrictedForeignGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $this->gallery([
            'brand' => 'srp',
            'gallery_group_id' => $unrestrictedForeignGroup->id,
            'restricted_photographers' => false,
        ]);
        $cases['unrestricted_foreign_brand_gallery_photographer'] = [
            'group' => $unrestrictedForeignGroup,
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
            'allowed' => false,
        ];

        // 14. An *unrestricted* gallery of the actor's own brand in the
        //     subtree: the gallery's own restriction rule decides, and an
        //     unrestricted gallery is manageable for every photographer of
        //     the brand.
        $unrestrictedGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $unrestrictedGroup->id,
            'restricted_photographers' => false,
        ]);
        $cases['unrestricted_gallery_photographer'] = [
            'group' => $unrestrictedGroup,
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
            'allowed' => true,
        ];

        // 15. The photographer holds the group of the gallery itself — a
        //     child group of the target group. The target group itself is
        //     not assigned, so the loop over the subtree decides, and the
        //     subtree of the photographer's own groups grants the gallery.
        $childScopeRoot = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $childScopeGroup = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $childScopeRoot->id,
        ]);
        $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $childScopeGroup->id,
            'restricted_photographers' => true,
        ]);
        $childScopeHolder = $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value);
        $childScopeHolder->photographerGalleryGroups()->attach($childScopeGroup->id);
        $cases['group_scope_child_group_photographer'] = [
            'group' => $childScopeRoot,
            'user' => $childScopeHolder,
            'allowed' => true,
        ];

        // 16. The photographer holds the *parent* group of the gallery's
        //     group: the subtree of the photographer's own groups reaches
        //     the gallery's group as well. The target group itself is not
        //     assigned, so the loop over the subtree decides.
        $parentScopeRoot = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $parentScopeGroup = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $parentScopeRoot->id,
        ]);
        $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $parentScopeGroup->id,
            'restricted_photographers' => true,
        ]);
        $parentScopeHolder = $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value);
        $parentScopeHolder->photographerGalleryGroups()->attach($parentScopeRoot->id);
        $cases['group_scope_parent_group_photographer'] = [
            'group' => $parentScopeGroup,
            'user' => $parentScopeHolder,
            'allowed' => true,
        ];

        // 17. A gallery in a group whose *intermediate* ancestor has a
        //     foreign brand: the chain of the gallery's own group decides,
        //     and it fails closed. The gallery is unrestricted, so nothing
        //     but the chain can deny it.
        $intermediateRoot = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $intermediateForeign = GalleryGroup::factory()->create([
            'brand' => 'srp',
            'parent_id' => $intermediateRoot->id,
        ]);
        $intermediateGroup = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $intermediateForeign->id,
        ]);
        $this->gallery([
            'brand' => Brand::B2B,
            'gallery_group_id' => $intermediateGroup->id,
            'restricted_photographers' => false,
        ]);
        $cases['foreign_intermediate_ancestor_photographer'] = [
            'group' => $intermediateRoot,
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
            'allowed' => false,
        ];

        return $cases;
    }

    /**
     * A group whose subtree holds `$galleryCount` restricted galleries that
     * the actor does not own.
     *
     * @return array{group: GalleryGroup, user: User}
     */
    private function subtreeWithGalleries(int $galleryCount): array
    {
        $group = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);

        foreach (range(1, $galleryCount) as $ignored) {
            $this->gallery([
                'brand' => Brand::B2B,
                'gallery_group_id' => $group->id,
                'restricted_photographers' => true,
            ]);
        }

        return [
            'group' => $group,
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
        ];
    }

    /**
     * A group whose subtree holds `$galleryCount` galleries in a child group.
     *
     * The gallery's own group has a parent, so the brand walk of a gallery has
     * a *chain* to resolve: the lookups of that chain are what the set-based
     * resolution has to avoid. The child group carries an explicit
     * restriction, so the inheritance of the model stops there and the model
     * never loads the chain itself.
     *
     * @return array{group: GalleryGroup, ownGroupIds: array<int, string>, chainIds: array<int, string>, user: User}
     */
    private function nestedSubtreeWithGalleries(int $galleryCount, ?bool $ownRestriction = true): array
    {
        $group = GalleryGroup::factory()->create(['brand' => Brand::B2B, 'parent_id' => null]);
        $galleryGroup = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $group->id,
            'restricted_photographers' => true,
        ]);

        foreach (range(1, $galleryCount) as $ignored) {
            $this->gallery([
                'brand' => Brand::B2B,
                'gallery_group_id' => $galleryGroup->id,
                'restricted_photographers' => $ownRestriction,
            ]);
        }

        return [
            'group' => $group,
            'ownGroupIds' => [(string) $galleryGroup->id],
            'chainIds' => [(string) $group->id],
            'user' => $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value),
        ];
    }

    /**
     * `canManageGalleryGroup()` the way it was resolved before the set-based
     * change: the chain of the group per level through `GalleryGroup::find()`
     * and the galleries of the subtree one by one through
     * `canManageGallery()`.
     *
     * The probe is the equivalence canary of the change: it uses the public
     * surface of the service and of `BrandRegistry` only, so it stays valid
     * when the service is optimised.
     */
    private function perGalleryCanManageGalleryGroup(User $user, GalleryGroup $group): bool
    {
        $service = app(AuthorizationService::class);

        if ($service->isReservedNullBrandActor($user) || $service->isTransientGuest($user)) {
            return false;
        }

        $actorBrand = BrandRegistry::normalizeId($user->brand);
        if ($actorBrand !== null && ! BrandRegistry::galleryGroupTreeMatchesBrand($group, $actorBrand)) {
            return false;
        }

        if (! $service->sharesBrand($user, $group->brand)) {
            return false;
        }

        if ($service->isSuperAdmin($user) || $service->isAdmin($user)) {
            return true;
        }

        if (! $service->isPhotographer($user)) {
            return false;
        }

        if ($user->photographerGalleryGroups()->where('gallery_groups.id', $group->id)->exists()) {
            return true;
        }

        $groupIds = $service->getSubGroupIds([$group->id]);
        $galleryIds = Gallery::whereIn('gallery_group_id', $groupIds)->pluck('id')->all();

        if (empty($galleryIds)) {
            return true;
        }

        foreach ($galleryIds as $galleryId) {
            if ($service->canManageGallery($user, $galleryId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The statements of one call, counted with `DB::listen()`.
     *
     * @return array<int, array{sql: string, bindings: array}>
     */
    private function collectStatements(callable $callback): array
    {
        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $callback();

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $statements;
    }

    /**
     * The statements that read the group tree.
     *
     * The load of the gallery's *own* group by the model
     * (`Gallery::effective_restricted_photographers`) is not a chain lookup —
     * it resolves the group the gallery lives in, not the chain above it. It
     * is therefore not counted here; it is the model's rule and it is part of
     * the per-gallery cost of the cost baseline.
     *
     * @param  array<int, array{sql: string, bindings: array}>  $statements
     * @param  array<int, string>  $ownGroupIds
     */
    private function countChainStatements(array $statements, array $ownGroupIds): int
    {
        return count(array_filter(
            $statements,
            fn (array $statement): bool => $this->isGroupStatement($statement)
                && ! $this->isGroupFindOf($statement, $ownGroupIds)
        ));
    }

    /**
     * The per-gallery group-chain lookups.
     *
     * The set-based resolution reads the group rows with `where ... in (...)`.
     * The per-gallery walk that this replaced reads the parents of the
     * gallery's own group with `GalleryGroup::find()` — one statement per
     * gallery and per level. Only the lookups of the chain are counted: the
     * model loads the gallery's own group with the same SQL shape when the
     * gallery inherits its restriction, and that load is the model's rule,
     * not the brand walk.
     *
     * @param  array<int, array{sql: string, bindings: array}>  $statements
     * @param  array<int, string>  $chainIds
     */
    private function countPerGalleryChainFinds(array $statements, array $chainIds): int
    {
        return count(array_filter(
            $statements,
            fn (array $statement): bool => $this->isGroupFindOf($statement, $chainIds)
        ));
    }

    /**
     * Whether the statement reads the group tree.
     *
     * @param  array{sql: string, bindings: array}  $statement
     */
    private function isGroupStatement(array $statement): bool
    {
        return str_contains($statement['sql'], 'from "gallery_groups"');
    }

    /**
     * Whether the statement reads a single group row by its id.
     *
     * @param  array{sql: string, bindings: array}  $statement
     */
    private function isGroupFind(array $statement): bool
    {
        return $this->isGroupStatement($statement)
            && str_contains($statement['sql'], 'where "gallery_groups"."id" = ?');
    }

    /**
     * Whether the statement reads one of the given groups by its id.
     *
     * @param  array{sql: string, bindings: array}  $statement
     * @param  array<int, string>  $groupIds
     */
    private function isGroupFindOf(array $statement, array $groupIds): bool
    {
        if (! $this->isGroupFind($statement)) {
            return false;
        }

        return in_array((string) ($statement['bindings'][0] ?? ''), $groupIds, true);
    }

    /**
     * The restriction of a gallery in a readable form.
     */
    private function describeRestriction(?bool $ownRestriction): string
    {
        return $ownRestriction === null ? 'null (inherited)' : 'true (own)';
    }

    /**
     * A chain of gallery groups that all carry the brand, the deepest group
     * last.
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

    private function user(UserRole $role, ?string $brand): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }
}
