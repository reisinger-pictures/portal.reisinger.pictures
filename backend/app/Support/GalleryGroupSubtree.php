<?php

namespace App\Support;

use App\Exceptions\GalleryGroupBudgetExceededException;
use App\Models\GalleryGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bounded, cycle-safe traversal of the gallery-group hierarchy.
 *
 * `gallery_groups.parent_id` is a self-referencing column without a
 * database-level cycle constraint, so the persisted hierarchy can contain
 * cycles (direct writes, imports, manual SQL) and arbitrarily deep/wide chains.
 * Every descendant traversal therefore obeys three explicit budgets:
 *
 * - **cycle safety** — a visited-id set, so corrupt data terminates instead of
 *   looping forever (a self-referential eager load recurses until the process
 *   dies);
 * - **depth budget** — at most {@see self::MAX_DEPTH} levels below the roots.
 *   Going deeper is *truncation*: the deeper result is empty (fail-closed) and
 *   the cut-off is audited;
 * - **node budget** — at most {@see self::MAX_NODES} visited groups, **roots
 *   included**, checked before any row is hydrated. Exceeding it is a
 *   {@see GalleryGroupBudgetExceededException} instead of a partial answer,
 *   because a silently shortened hierarchy would under-propagate brands and
 *   under-report authorizations.
 *
 * Queries are issued once per level and parent ids are sent in chunks of
 * {@see self::PARENT_ID_CHUNK}, so a wide tree costs a bounded number of
 * statements and never an oversized `WHERE IN (...)`.
 *
 * The traversal is structural only: it never widens nor narrows brand
 * authorization. Callers keep applying their own brand filters afterwards.
 */
final class GalleryGroupSubtree
{
    /** Maximum number of levels below the traversal roots. */
    public const MAX_DEPTH = 10;

    /**
     * Maximum number of node levels in a loaded subtree: the roots plus
     * {@see self::MAX_DEPTH} descendant levels. In-memory tree walkers bound
     * their recursion by this value, otherwise the deepest loaded level would
     * lose its hydrated relations and lazy-load them again.
     */
    public const MAX_LEVELS = self::MAX_DEPTH + 1;

    /** Maximum number of visited groups (roots included) per traversal. */
    public const MAX_NODES = 5000;

    /** Number of parent ids sent per `whereIn` chunk. */
    public const PARENT_ID_CHUNK = 500;

    /** Log channel key for an audited depth cut-off. */
    public const DEPTH_TRUNCATED_EVENT = 'gallery_groups.traversal_depth_truncated';

    /**
     * Load the bounded subtree of a single group and set the `children`
     * relation on every visited node. An empty `children` collection marks the
     * depth budget, so consumers never trigger a lazy load.
     *
     * @throws GalleryGroupBudgetExceededException when the subtree needs more
     *                                             than $maxNodes groups
     */
    public static function loadSubtree(
        GalleryGroup $group,
        int $maxDepth = self::MAX_DEPTH,
        int $maxNodes = self::MAX_NODES,
    ): GalleryGroup {
        self::loadForest(new Collection([$group]), $maxDepth, $maxNodes);

        return $group;
    }

    /**
     * Load the bounded subtree of a forest of already fetched roots.
     *
     * The root budget is enforced before the first descendant query, so an
     * over-sized forest never hydrates a single child row.
     *
     * @param  Collection<int, GalleryGroup>  $roots
     * @return Collection<int, GalleryGroup> the (re-indexed) $roots collection
     *
     * @throws GalleryGroupBudgetExceededException when roots + reachable
     *                                             descendants exceed $maxNodes
     */
    public static function loadForest(
        Collection $roots,
        int $maxDepth = self::MAX_DEPTH,
        int $maxNodes = self::MAX_NODES,
    ): Collection {
        $roots = $roots->values();
        $rootCount = $roots->count();

        if ($rootCount > $maxNodes) {
            throw GalleryGroupBudgetExceededException::forRoots($maxNodes, $rootCount);
        }

        $visited = [];
        $remaining = $maxNodes - $rootCount;

        foreach ($roots as $root) {
            $root->setRelation('children', new Collection);
            $key = self::key($root);
            if ($key === null) {
                continue;
            }
            $visited[$key] = true;
        }

        $level = $roots;
        $depth = 0;
        $truncated = false;

        while ($level->isNotEmpty()) {
            if ($depth >= $maxDepth) {
                $truncated = true;
                break;
            }

            if ($remaining <= 0) {
                // Reachable nodes exist but the node budget is spent: refuse to
                // answer with a silently shortened hierarchy.
                throw GalleryGroupBudgetExceededException::forNodes($maxNodes, $maxNodes);
            }

            $parentIds = self::normalizeIds(
                $level->map(fn (GalleryGroup $group): ?string => self::key($group))->all()
            );

            if ($parentIds === []) {
                break;
            }

            /** @var array<string, array<int, GalleryGroup>> $childrenByParent */
            $childrenByParent = [];
            $children = new Collection;

            foreach (array_chunk($parentIds, self::PARENT_ID_CHUNK) as $chunk) {
                $rows = GalleryGroup::query()
                    ->whereIn('parent_id', $chunk)
                    ->with(['galleries', 'orgs'])
                    ->get();

                foreach ($rows as $row) {
                    $key = self::key($row);

                    if ($key === null || isset($visited[$key])) {
                        // Not a new node, or a cycle back into a visited one.
                        continue;
                    }

                    if ($remaining <= 0) {
                        throw GalleryGroupBudgetExceededException::forNodes(
                            $maxNodes,
                            count($visited) + 1,
                        );
                    }

                    $visited[$key] = true;
                    $remaining--;
                    $row->setRelation('children', new Collection);
                    $children->push($row);
                    $childrenByParent[(string) $row->parent_id][] = $row;
                }
            }

            foreach ($level as $parent) {
                $parentKey = self::key($parent);
                $parent->setRelation(
                    'children',
                    new Collection($parentKey === null ? [] : ($childrenByParent[$parentKey] ?? []))
                );
            }

            if ($children->isEmpty()) {
                break;
            }

            $level = $children;
            $depth++;
        }

        if ($truncated) {
            self::auditDepthCutOff($maxDepth, $maxNodes, count($visited), 'load');
        }

        return $roots;
    }

    /**
     * Collect the ids of all bounded descendants of the given roots.
     *
     * The roots count against the node budget and are never part of the result,
     * so a corrupt cycle cannot re-introduce them.
     *
     * @param  array<int, mixed>  $rootIds
     * @return array<int, string>
     *
     * @throws GalleryGroupBudgetExceededException when seeds + reachable
     *                                             descendants exceed $maxNodes
     */
    public static function descendantIds(
        array $rootIds,
        int $maxDepth = self::MAX_DEPTH,
        int $maxNodes = self::MAX_NODES,
    ): array {
        $frontier = self::normalizeIds($rootIds);

        if ($frontier === []) {
            return [];
        }

        if (count($frontier) > $maxNodes) {
            throw GalleryGroupBudgetExceededException::forRoots($maxNodes, count($frontier));
        }

        $visited = array_fill_keys($frontier, true);
        $remaining = $maxNodes - count($frontier);
        $descendants = [];
        $depth = 0;
        $truncated = false;

        while ($frontier !== []) {
            if ($depth >= $maxDepth) {
                $truncated = true;
                break;
            }

            if ($remaining <= 0) {
                throw GalleryGroupBudgetExceededException::forNodes($maxNodes, $maxNodes);
            }

            $next = [];

            foreach (array_chunk($frontier, self::PARENT_ID_CHUNK) as $chunk) {
                $rows = GalleryGroup::query()
                    ->whereIn('parent_id', $chunk)
                    ->pluck('id')
                    ->all();

                foreach ($rows as $id) {
                    $id = (string) $id;

                    if (isset($visited[$id])) {
                        // Cycle back into an already visited node.
                        continue;
                    }

                    if ($remaining <= 0) {
                        throw GalleryGroupBudgetExceededException::forNodes(
                            $maxNodes,
                            count($visited) + 1,
                        );
                    }

                    $visited[$id] = true;
                    $remaining--;
                    $descendants[] = $id;
                    $next[] = $id;
                }
            }

            $frontier = array_values(array_unique($next));
            $depth++;
        }

        if ($truncated) {
            self::auditDepthCutOff($maxDepth, $maxNodes, count($visited), 'descendants');
        }

        return $descendants;
    }

    /**
     * Reduce a list of ids to the ones that exist as gallery groups.
     *
     * Authorization seeds are echoed back by {@see self::descendantIds()} based
     * callers, so unknown ids must be dropped first: a stale/foreign id may
     * never appear in an authorization answer. The lookup is chunked and bound
     * (no string concatenation), and the requested ids count against the node
     * budget exactly like traversal roots.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string> the existing ids in input order
     *
     * @throws GalleryGroupBudgetExceededException when more seeds are supplied
     *                                             than the node budget allows
     */
    public static function existingIds(array $ids, int $maxNodes = self::MAX_NODES): array
    {
        $requested = self::normalizeIds($ids);

        if ($requested === []) {
            return [];
        }

        if (count($requested) > $maxNodes) {
            throw GalleryGroupBudgetExceededException::forRoots($maxNodes, count($requested));
        }

        $existing = [];

        foreach (array_chunk($requested, self::PARENT_ID_CHUNK) as $chunk) {
            foreach (GalleryGroup::query()->whereIn('id', $chunk)->pluck('id') as $id) {
                $existing[] = (string) $id;
            }
        }

        return array_values(array_intersect($requested, $existing));
    }

    /**
     * Whether the given parent id is (transitively) the group's own id.
     *
     * A single statement replaces one lookup per ancestor level. `UNION`
     * (distinct) is the cycle guard inside the recursive CTE: with `UNION ALL`
     * a corrupt A→B→A chain never terminates.
     */
    public static function parentChainContains(string $parentId, ?string $groupId): bool
    {
        if ($parentId === '' || $groupId === null || $groupId === '') {
            return false;
        }

        $row = DB::selectOne(
            '
                WITH RECURSIVE ancestors (id) AS (
                    SELECT id FROM gallery_groups WHERE id = ?
                    UNION
                    SELECT g.parent_id FROM gallery_groups g
                    INNER JOIN ancestors a ON g.id = a.id
                    WHERE g.parent_id IS NOT NULL
                )
                SELECT id FROM ancestors WHERE id = ? LIMIT 1
            ',
            [$parentId, $groupId]
        );

        return $row !== null;
    }

    /**
     * Normalize a mixed list of keys into a unique list of non-empty strings.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    public static function normalizeIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if (! is_string($id) && ! is_int($id)) {
                continue;
            }

            $normalized[] = (string) $id;
        }

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (string $id): bool => $id !== ''
        )));
    }

    private static function key(GalleryGroup $group): ?string
    {
        $key = $group->getKey();

        return is_string($key) || is_int($key) ? (string) $key : null;
    }

    private static function auditDepthCutOff(
        int $maxDepth,
        int $maxNodes,
        int $visited,
        string $mode,
    ): void {
        Log::warning(self::DEPTH_TRUNCATED_EVENT, [
            'mode' => $mode,
            'max_depth' => $maxDepth,
            'max_nodes' => $maxNodes,
            'visited_nodes' => $visited,
        ]);
    }
}
