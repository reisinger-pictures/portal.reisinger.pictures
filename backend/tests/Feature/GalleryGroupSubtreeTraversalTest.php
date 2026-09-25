<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Exceptions\GalleryGroupBudgetExceededException;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\GalleryTreeService;
use App\Support\GalleryGroupSubtree;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P1-M14 (non-contract slice): gallery/group descendant traversal must be
 * cycle-safe and explicitly bounded/chunked.
 *
 * `gallery_groups.parent_id` is self-referencing and unconstrained, so the
 * persisted hierarchy can be cyclic and arbitrarily deep/wide. These
 * regressions pin the three budgets every traversal obeys (cycle safety, depth,
 * node count) plus the per-level query budget.
 */
class GalleryGroupSubtreeTraversalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Brand-specific tree keys are process-global in the file cache.
        Cache::flush();
    }

    // =================================================================
    // 1. Acyclic traversal
    // =================================================================

    public function test_acyclic_three_level_chain_returns_every_descendant(): void
    {
        $root = GalleryGroup::factory()->create();
        $child = GalleryGroup::factory()->create(['parent_id' => $root->id]);
        $grandchild = GalleryGroup::factory()->create(['parent_id' => $child->id]);
        $greatGrandchild = GalleryGroup::factory()->create(['parent_id' => $grandchild->id]);

        $ids = app(GalleryTreeService::class)->getAllSubgroupIds($root);

        $this->assertEqualsCanonicalizing(
            [$child->id, $grandchild->id, $greatGrandchild->id],
            $ids,
        );
        $this->assertNotContains($root->id, $ids, 'The group itself is never its own descendant.');
    }

    public function test_loaded_subtree_sets_children_on_every_level(): void
    {
        $root = GalleryGroup::factory()->create();
        $child = GalleryGroup::factory()->create(['parent_id' => $root->id]);
        $grandchild = GalleryGroup::factory()->create(['parent_id' => $child->id]);

        $loaded = GalleryGroupSubtree::loadSubtree($root);

        $this->assertSame(1, $loaded->children->count());
        $this->assertSame($child->id, $loaded->children->first()->id);
        $this->assertSame(
            $grandchild->id,
            $loaded->children->first()->children->first()->id,
        );
        // Deepest loaded level is marked explicitly so no lazy load happens.
        $this->assertTrue(
            $loaded->children->first()->children->first()->relationLoaded('children'),
        );
    }

    // =================================================================
    // 2. Depth budget
    // =================================================================

    public function test_deep_acyclic_chain_is_cut_off_at_the_depth_budget(): void
    {
        $levels = GalleryGroupSubtree::MAX_DEPTH + 4;
        $ids = $this->insertChain($levels);

        $collected = $this->countQueries(
            fn () => GalleryGroupSubtree::descendantIds([$ids[0]]),
        );

        $this->assertCount(GalleryGroupSubtree::MAX_DEPTH, $collected['value']);
        $this->assertSame(
            array_slice($ids, 1, GalleryGroupSubtree::MAX_DEPTH),
            $collected['value'],
        );
        $this->assertLessThanOrEqual(
            GalleryGroupSubtree::MAX_DEPTH,
            $collected['groupQueries'],
            'Descendant collection must cost at most one statement per level.',
        );
    }

    public function test_admin_tree_truncates_below_the_depth_budget(): void
    {
        $admin = $this->admin();
        $ids = $this->insertChain(GalleryGroupSubtree::MAX_DEPTH + 3);

        $tree = app(GalleryTreeService::class)->getAdminTree($admin);

        $depth = 0;
        $node = $tree['groups'][0] ?? null;
        while ($node !== null) {
            $depth++;
            $node = $node['children'][0] ?? null;
        }

        $this->assertSame(GalleryGroupSubtree::MAX_LEVELS, $depth);
        $this->assertSame(
            $ids[GalleryGroupSubtree::MAX_DEPTH],
            $this->lastIdInChain($tree['groups'][0]),
        );
    }

    public function test_admin_tree_at_the_depth_budget_does_not_lazy_load_chains(): void
    {
        // Cross-brand super admin: no brand chain validation runs, so any
        // single-row lookup here is a missing hydration, not the known
        // brand-chain N+1 of BrandRegistry.
        $admin = $this->superAdmin();
        $ids = $this->insertChain(GalleryGroupSubtree::MAX_LEVELS);
        $galleryId = Gallery::factory()->create([
            'gallery_group_id' => $ids[GalleryGroupSubtree::MAX_DEPTH],
        ])->id;

        $tree = $this->countQueries(
            fn () => app(GalleryTreeService::class)->getAdminTree($admin),
        );

        $this->assertStringContainsString(
            $galleryId,
            json_encode($tree['value'], JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            [],
            $this->singleRowLookups($tree),
            'Serializing a tree at the depth budget must not fall back to per-node lookups.',
        );
    }

    // =================================================================
    // 3. Node budget (hard, audited failure — never a partial answer)
    // =================================================================

    public function test_node_budget_fails_instead_of_returning_a_partial_subtree(): void
    {
        $ids = $this->insertChain(6);

        $rejected = null;
        $collected = $this->countQueries(function () use ($ids, &$rejected): void {
            try {
                GalleryGroupSubtree::loadForest(
                    GalleryGroup::query()->whereIn('id', [$ids[0]])->get(),
                    GalleryGroupSubtree::MAX_DEPTH,
                    3,
                );
            } catch (GalleryGroupBudgetExceededException $exception) {
                $rejected = $exception;
            }
        });

        $this->assertInstanceOf(GalleryGroupBudgetExceededException::class, $rejected);
        $this->assertSame(GalleryGroupBudgetExceededException::KIND_NODES, $rejected->kind());
        $this->assertSame(3, $rejected->limit());
        $this->assertLessThanOrEqual(
            3,
            $collected['groupQueries'],
            'The budget is detected on the first descendant statements, not by walking the whole subtree.',
        );
    }

    public function test_descendant_collection_fails_instead_of_returning_partial_ids(): void
    {
        $ids = $this->insertChain(6);

        $this->expectException(GalleryGroupBudgetExceededException::class);

        GalleryGroupSubtree::descendantIds([$ids[0]], GalleryGroupSubtree::MAX_DEPTH, 3);
    }

    public function test_node_budget_counts_the_supplied_roots(): void
    {
        $ids = $this->insertChain(3);

        $rejected = null;
        try {
            GalleryGroupSubtree::descendantIds($ids, GalleryGroupSubtree::MAX_DEPTH, 2);
        } catch (GalleryGroupBudgetExceededException $exception) {
            $rejected = $exception;
        }

        $this->assertInstanceOf(GalleryGroupBudgetExceededException::class, $rejected);
        $this->assertSame(3, $rejected->requested(), 'The three seeds exhaust a budget of two.');
    }

    public function test_wide_root_forest_fails_before_hydrating_a_single_child_row(): void
    {
        $rootCount = GalleryGroupSubtree::PARENT_ID_CHUNK + 5;
        $this->insertRoots($rootCount);

        $roots = GalleryGroup::query()
            ->whereNull('parent_id')
            ->orderBy('id')
            ->limit($rootCount)
            ->get();
        $this->assertCount($rootCount, $roots);

        $rejected = null;
        $collected = $this->countQueries(function () use ($roots, &$rejected): void {
            try {
                GalleryGroupSubtree::loadForest(
                    $roots,
                    GalleryGroupSubtree::MAX_DEPTH,
                    GalleryGroupSubtree::PARENT_ID_CHUNK,
                );
            } catch (GalleryGroupBudgetExceededException $exception) {
                $rejected = $exception;
            }
        });

        $this->assertInstanceOf(GalleryGroupBudgetExceededException::class, $rejected);
        $this->assertSame($rootCount, $rejected->requested());
        $this->assertSame(
            0,
            $collected['groupQueries'],
            'The root budget is enforced before any descendant statement runs.',
        );
    }

    public function test_oversized_level_fails_instead_of_dropping_rows(): void
    {
        $root = GalleryGroup::factory()->create();
        $this->insertChildren($root->id, 6);

        $rejected = null;
        try {
            GalleryGroupSubtree::loadForest(
                GalleryGroup::query()->whereNull('parent_id')->get(),
                GalleryGroupSubtree::MAX_DEPTH,
                4,
            );
        } catch (GalleryGroupBudgetExceededException $exception) {
            $rejected = $exception;
        }

        $this->assertInstanceOf(GalleryGroupBudgetExceededException::class, $rejected);
        $this->assertSame(4, $rejected->limit());
    }

    public function test_admin_tree_root_query_is_capped_at_the_node_budget(): void
    {
        $admin = $this->admin();
        GalleryGroup::factory()->create();

        $tree = $this->countQueries(
            fn () => app(GalleryTreeService::class)->getAdminTree($admin),
        );

        $rootQueries = array_values(array_filter(
            $tree['sql'],
            fn (string $sql): bool => str_contains($sql, '"gallery_groups"')
                && str_contains($sql, 'is null')
                && str_contains($sql, 'parent_id'),
        ));

        $this->assertCount(1, $rootQueries, implode("\n", $tree['sql']));
        $this->assertStringContainsString(
            'limit '.(GalleryGroupSubtree::MAX_NODES + 1),
            strtolower($rootQueries[0]),
        );
    }

    public function test_admin_tree_returns_an_empty_tree_and_caches_nothing_when_over_budget(): void
    {
        $admin = $this->admin();
        // MAX_NODES + 1 root groups: the capped root query sees one row too
        // many, so the tree is denied instead of being partially rendered.
        $this->insertRoots(GalleryGroupSubtree::MAX_NODES + 1);
        $cacheKey = 'gallery_tree_admin_'.Brand::B2B->value;

        $tree = app(GalleryTreeService::class)->getAdminTree($admin);

        $this->assertSame(['groups' => [], 'root_galleries' => []], $tree);
        $this->assertNull(
            Cache::get($cacheKey),
            'A denied tree must not be cached, otherwise the budget failure becomes permanent.',
        );
    }

    public function test_explicit_depth_budget_of_zero_keeps_only_the_roots(): void
    {
        $ids = $this->insertChain(3);

        $loaded = GalleryGroupSubtree::loadForest(
            GalleryGroup::query()->whereIn('id', [$ids[0]])->get(),
            0,
        );

        $this->assertTrue($loaded->first()->children->isEmpty());
    }

    public function test_depth_budget_keeps_the_levels_inside_it_and_drops_the_rest(): void
    {
        $ids = $this->insertChain(6);

        $loaded = GalleryGroupSubtree::loadForest(
            GalleryGroup::query()->whereIn('id', [$ids[0]])->get(),
            2,
        );

        $collected = [];
        $this->walkLoaded($loaded->first(), $collected);

        $this->assertSame(
            [$ids[1], $ids[2]],
            $collected,
            'Two levels inside the budget stay; everything below is not exposed.',
        );
    }

    // =================================================================
    // 4. Width / chunking budget
    // =================================================================

    public function test_wide_tree_is_collected_in_chunks_within_the_query_budget(): void
    {
        $root = GalleryGroup::factory()->create();
        $childCount = GalleryGroupSubtree::PARENT_ID_CHUNK + 7;
        $childIds = $this->insertChildren($root->id, $childCount);

        $collected = $this->countQueries(
            fn () => GalleryGroupSubtree::descendantIds([$root->id]),
        );

        $this->assertEqualsCanonicalizing($childIds, $collected['value']);
        $this->assertSame(
            1 + (int) ceil($childCount / GalleryGroupSubtree::PARENT_ID_CHUNK),
            $collected['groupQueries'],
            'Wide children are fetched in PARENT_ID_CHUNK sized statements, not one per node.',
        );
        $this->assertLessThan(10, $collected['groupQueries']);
    }

    public function test_wide_tree_is_fully_loaded_in_chunked_statements(): void
    {
        $root = GalleryGroup::factory()->create();
        $childCount = GalleryGroupSubtree::PARENT_ID_CHUNK + 3;
        $this->insertChildren($root->id, $childCount);

        $collected = $this->countQueries(
            fn () => GalleryGroupSubtree::loadSubtree($root),
        );

        $this->assertSame(
            $root->id,
            $collected['value'] instanceof GalleryGroup ? $collected['value']->id : null,
        );
        $this->assertCount($childCount, $root->children);
        $this->assertSame(
            1 + (int) ceil($childCount / GalleryGroupSubtree::PARENT_ID_CHUNK),
            $collected['groupQueries'],
            'Two chunk statements fetch >500 children — no per-node query storm.',
        );
    }

    // =================================================================
    // 4. Corrupt cycles
    // =================================================================

    public function test_descendant_collection_terminates_on_a_corrupt_two_node_cycle(): void
    {
        [$a, $b] = $this->insertChain(2);
        $this->forceCycle($a, $b);

        $collected = $this->countQueries(
            fn () => GalleryGroupSubtree::descendantIds([$a]),
        );

        $this->assertSame([$b], $collected['value'], 'A→B→A must not re-collect A.');
    }

    public function test_descendant_collection_terminates_on_a_self_reference(): void
    {
        [$a] = $this->insertChain(1);
        $this->forceCycle($a, $a);

        $this->assertSame([], GalleryGroupSubtree::descendantIds([$a]));
    }

    public function test_subtree_load_terminates_on_a_corrupt_cycle(): void
    {
        [$a, $b] = $this->insertChain(2);
        $this->forceCycle($a, $b);

        $loaded = GalleryGroupSubtree::loadSubtree(GalleryGroup::query()->findOrFail($a));

        $this->assertSame(1, $loaded->children->count());
        $this->assertSame($b, $loaded->children->first()->id);
        $this->assertTrue(
            $loaded->children->first()->children->isEmpty(),
            'The cycle edge back to the root must be dropped.',
        );
    }

    public function test_authorization_sub_group_ids_terminate_on_a_corrupt_cycle(): void
    {
        // Regression: the recursive CTE used `UNION ALL`, which never
        // terminates on a corrupt A→B→A hierarchy (memory exhaustion).
        [$a, $b] = $this->insertChain(2);
        $this->forceCycle($a, $b);

        // A `UNION ALL` recursive CTE never terminates on this data.
        $ids = $this->countQueries(
            fn () => app(AuthorizationService::class)->getSubGroupIds([$a]),
        );

        $this->assertEqualsCanonicalizing([$a, $b], $ids['value']);
    }

    // =================================================================
    // 5. Authorization chain: seeds always, descendants only when budgeted
    // =================================================================

    public function test_authorization_chain_returns_seeds_plus_bounded_descendants(): void
    {
        $ids = $this->insertChain(4);

        $collected = $this->countQueries(
            fn () => app(AuthorizationService::class)->getSubGroupIds([$ids[0]]),
        );

        $this->assertEqualsCanonicalizing(array_slice($ids, 0, 4), $collected['value']);
        $this->assertLessThanOrEqual(
            GalleryGroupSubtree::MAX_DEPTH,
            $collected['groupQueries'],
            'The chain walk is one statement per level, not one per node.',
        );
    }

    public function test_authorization_chain_fails_closed_to_the_granted_seeds(): void
    {
        $ids = $this->insertChain(6);

        $collected = $this->countQueries(
            fn () => app(AuthorizationService::class)->getSubGroupIds([$ids[0]], null, 3),
        );

        $this->assertSame(
            [$ids[0]],
            $collected['value'],
            'An over-budget chain must never widen an authorization beyond the granted seeds.',
        );
    }

    public function test_authorization_chain_depth_cut_off_hides_deeper_levels_only(): void
    {
        $ids = $this->insertChain(GalleryGroupSubtree::MAX_DEPTH + 4);

        $collected = $this->countQueries(
            fn () => app(AuthorizationService::class)->getSubGroupIds([$ids[0]], 2),
        );

        $this->assertEqualsCanonicalizing(
            [$ids[0], $ids[1], $ids[2]],
            $collected['value'],
            'A depth cut-off narrows the result; the seed and the levels inside the budget stay.',
        );
    }

    public function test_authorization_chain_keeps_group_assignment_permissions(): void
    {
        // End-to-end: a group assignment must still grant the descendant
        // gallery through the bounded chain walk.
        $photographer = User::factory()->create(['brand' => Brand::B2B]);
        $photographer->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])->id
        );
        $ids = $this->insertChain(3);
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'gallery_group_id' => $ids[2],
        ]);
        $photographer->photographerGalleryGroups()->attach($ids[0]);

        $this->assertContains(
            $gallery->id,
            app(AuthorizationService::class)->getAllowedGalleryIds($photographer),
        );
    }

    public function test_get_all_subgroup_ids_fails_closed_when_the_node_budget_is_exceeded(): void
    {
        $ids = $this->insertChain(6);

        $collected = $this->countQueries(
            fn () => app(GalleryTreeService::class)->getAllSubgroupIds(
                GalleryGroup::query()->findOrFail($ids[0]),
                null,
                3,
            ),
        );

        $this->assertSame(
            [],
            $collected['value'],
            'An over-budget subtree must not report partial descendants.',
        );
    }

    public function test_depth_cut_off_is_audited(): void
    {
        Log::spy();
        $ids = $this->insertChain(GalleryGroupSubtree::MAX_DEPTH + 3);

        GalleryGroupSubtree::descendantIds([$ids[0]]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $context): bool => $event === GalleryGroupSubtree::DEPTH_TRUNCATED_EVENT
                && $context['max_depth'] === GalleryGroupSubtree::MAX_DEPTH
                && $context['max_nodes'] === GalleryGroupSubtree::MAX_NODES
                && $context['visited_nodes'] === GalleryGroupSubtree::MAX_LEVELS)
            ->once();
    }

    public function test_no_depth_audit_is_logged_for_a_tree_inside_the_budget(): void
    {
        Log::spy();
        $ids = $this->insertChain(3);

        GalleryGroupSubtree::descendantIds([$ids[0]]);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_get_all_subgroup_ids_terminates_on_a_corrupt_cycle(): void
    {
        // Regression: the service recursed over the self-eager-loading
        // `children` relation, so corrupt data recursed until the process died.
        [$a, $b] = $this->insertChain(2);
        $this->forceCycle($a, $b);

        $ids = $this->countQueries(
            fn () => app(GalleryTreeService::class)
                ->getAllSubgroupIds(GalleryGroup::query()->findOrFail($a)),
        );

        $this->assertSame([$b], $ids['value']);
    }

    public function test_eager_loading_children_does_not_walk_the_whole_hierarchy(): void
    {
        $ids = $this->insertChain(GalleryGroupSubtree::MAX_DEPTH + 5);

        $collected = $this->countQueries(
            fn () => GalleryGroup::query()->with('children')->findOrFail($ids[0]),
        );

        $this->assertLessThanOrEqual(
            2,
            $collected['groupQueries'],
            'The children relation must not re-apply its own eager load on every level.',
        );
    }

    public function test_eager_loading_children_terminates_on_a_corrupt_cycle(): void
    {
        [$a, $b] = $this->insertChain(2);
        $this->forceCycle($a, $b);

        $collected = $this->countQueries(
            fn () => GalleryGroup::query()->with('children.children')->findOrFail($a),
        );

        $this->assertInstanceOf(GalleryGroup::class, $collected['value']);
        $this->assertLessThanOrEqual(3, $collected['groupQueries']);
    }

    public function test_cycle_guard_answers_with_a_single_statement_on_deep_corrupt_data(): void
    {
        // A→B→…→F plus a corrupt F→A edge: a six level cycle in the data.
        $ids = $this->insertChain(6);
        $this->forceCycle($ids[5], $ids[0]);

        $rejected = false;
        $collected = $this->countQueries(function () use ($ids, &$rejected): void {
            try {
                // The ancestor chain of $ids[3] loops back to $ids[0].
                GalleryGroup::query()->findOrFail($ids[0])->update(['parent_id' => $ids[3]]);
            } catch (InvalidArgumentException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'A group inside corrupt cyclic data must be rejected.');
        $this->assertSame(
            1,
            $collected['groupQueries'],
            'The ancestor walk is one statement, independent of the chain length.',
        );
    }

    public function test_brand_propagation_reaches_every_bounded_descendant(): void
    {
        $root = GalleryGroup::factory()->create(['brand' => Brand::B2B]);
        $descendants = [];
        $parentId = $root->id;

        for ($i = 0; $i < 5; $i++) {
            $parentId = $this->insertGroup($parentId);
            $descendants[] = $parentId;
        }

        // Brand-less subtree (legacy/import) below a brand-less root, so the
        // propagation has to walk every bounded descendant.
        DB::table('gallery_groups')
            ->whereIn('id', array_merge([$root->id], $descendants))
            ->update(['brand' => null]);
        $galleryId = Gallery::factory()->create([
            'gallery_group_id' => $descendants[2],
            'brand' => null,
        ])->id;

        $this->countQueries(function () use ($root): void {
            GalleryGroup::query()->findOrFail($root->id)->update(['brand' => Brand::B2B]);
        });

        $this->assertSame(
            0,
            GalleryGroup::query()->whereIn('id', $descendants)->whereNull('brand')->count(),
        );
        $this->assertSame(
            Brand::B2B,
            Gallery::query()->findOrFail($galleryId)->brand,
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function admin(): User
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value])->id);

        return $admin;
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['brand' => null]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value])->id);

        return $admin;
    }

    /**
     * @return array<int, string>
     */
    private function insertChain(int $levels): array
    {
        $ids = [];
        $parentId = null;

        for ($i = 0; $i < $levels; $i++) {
            $ids[] = $this->insertGroup($parentId);
            $parentId = $ids[$i];
        }

        return $ids;
    }

    /**
     * @return array<int, string>
     */
    private function insertRoots(int $count): array
    {
        $rows = [];
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            $rows[] = $this->groupRow($id, null);
        }

        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('gallery_groups')->insert($batch);
        }

        return $ids;
    }

    /**
     * @return array<int, string>
     */
    private function insertChildren(string $parentId, int $count): array
    {
        $rows = [];
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            $rows[] = $this->groupRow($id, $parentId);
        }

        DB::table('gallery_groups')->insert($rows);

        return $ids;
    }

    private function insertGroup(?string $parentId): string
    {
        $id = (string) Str::uuid();
        DB::table('gallery_groups')->insert([$this->groupRow($id, $parentId)]);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function groupRow(string $id, ?string $parentId): array
    {
        return [
            'id' => $id,
            'parent_id' => $parentId,
            'name' => 'Gruppe '.$id,
            'slug' => 'gruppe-'.$id,
            'is_public' => false,
            'brand' => Brand::B2B->value,
        ];
    }

    /**
     * Corrupt the hierarchy without model events (import/legacy write).
     */
    private function forceCycle(string $childId, string $parentId): void
    {
        DB::table('gallery_groups')->where('id', $childId)->update(['parent_id' => $parentId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastIdInChain(array $node): ?string
    {
        $id = $node['id'] ?? null;
        while (isset($node['children'][0])) {
            $node = $node['children'][0];
            $id = $node['id'] ?? $id;
        }

        return is_string($id) ? $id : null;
    }

    /**
     * @param  Collection<int, GalleryGroup>  $loaded
     * @param  array<int, string>  $collected
     */
    private function walkLoaded(GalleryGroup $loaded, array &$collected, int $depth = 0): void
    {
        if ($depth > GalleryGroupSubtree::MAX_DEPTH) {
            return;
        }

        foreach ($loaded->children as $child) {
            $collected[] = $child->id;
            $this->walkLoaded($child, $collected, $depth + 1);
        }
    }

    /**
     * Run a callback and report its return value plus the number of
     * `gallery_groups` statements it issued.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return array{value: TValue, groupQueries: int, totalQueries: int, sql: array<int, string>}
     */
    private function countQueries(callable $callback): array
    {
        $groupQueries = 0;
        $totalQueries = 0;
        $sql = [];

        DB::listen(function (QueryExecuted $query) use (&$groupQueries, &$totalQueries, &$sql): void {
            $totalQueries++;
            $sql[] = $query->sql;
            if (str_contains($query->sql, '"gallery_groups"') || str_contains($query->sql, '`gallery_groups`')) {
                $groupQueries++;
            }
        });

        $value = $callback();

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return [
            'value' => $value,
            'groupQueries' => $groupQueries,
            'totalQueries' => $totalQueries,
            'sql' => $sql,
        ];
    }

    /**
     * The per-row parent lookups (`Model::find()`) a tree serialization must
     * never fall back to.
     *
     * @param  array{value: mixed, groupQueries: int, totalQueries: int, sql: array<int, string>}  $collected
     * @return array<int, string>
     */
    private function singleRowLookups(array $collected): array
    {
        return array_values(array_filter(
            $collected['sql'],
            fn (string $sql): bool => str_contains(strtolower($sql), 'limit 1')
                && (str_contains($sql, '"gallery_groups"') || str_contains($sql, '"galleries"')),
        ));
    }
}
