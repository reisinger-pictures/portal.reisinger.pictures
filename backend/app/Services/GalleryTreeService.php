<?php

namespace App\Services;

use App\Enums\Brand;
use App\Exceptions\GalleryGroupBudgetExceededException;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\User;
use App\Support\BrandRegistry;
use App\Support\GalleryGroupSubtree;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GalleryTreeService
{
    /**
     * Return the cache scope for the normalized actor brand.
     *
     * A null brand is reserved for the trusted cross-brand tree; every
     * brand-bound actor gets its own cache entry.
     */
    private function adminTreeCacheKey(?string $brand): string
    {
        return $brand === null
            ? 'gallery_tree_admin'
            : 'gallery_tree_admin_'.$brand;
    }

    /**
     * Get the complete gallery tree for admin view with optional filtering.
     *
     * Brand isolation: a brand-bound user (brand != null) only gets a tree
     * containing their own brand's groups and galleries, cached under a
     * brand-specific key. Only a persisted Super-Admin with `brand === null`
     * gets the full tree across all brands, cached under the global
     * `gallery_tree_admin` key (intended).
     */
    public function getAdminTree(User $user, ?string $filterType = null, ?string $orgId = null): array
    {
        $authorization = app(AuthorizationService::class);
        if ($authorization->isTransientGuest($user) || $authorization->isReservedNullBrandActor($user)) {
            return [];
        }

        $crossBrand = $authorization->isTrustedCrossBrandActor($user);
        $brand = $crossBrand ? null : BrandRegistry::normalizeId($user->brand);

        // A null brand is reserved for a trusted, persisted Super-Admin.  Keep
        // this fail-closed check next to the cache-scope decision so a malformed
        // or legacy actor can never fall back to the cross-brand cache.
        if (! $crossBrand && $brand === null) {
            return [];
        }

        $cacheKey = $this->adminTreeCacheKey($brand);

        $buildTree = function () use ($brand) {
            // The node budget is a hard limit, so the root query is capped at
            // one row beyond it: an over-sized forest is detected without
            // hydrating 100k root models.
            $groupQuery = GalleryGroup::query()
                ->whereNull('parent_id')
                ->with(['galleries', 'galleries.orgs', 'orgs'])
                ->limit(GalleryGroupSubtree::MAX_NODES + 1);
            $galleryQuery = Gallery::query()
                ->whereNull('gallery_group_id')
                ->with('orgs');

            if ($brand !== null) {
                $groupQuery->where('brand', $brand);
                $galleryQuery->where('brand', $brand);
            }

            // The descendant levels are loaded by the bounded, cycle-safe
            // traversal instead of a self-referential eager load (which was
            // unbounded in depth and looped forever on corrupt cycles).
            $groups = GalleryGroupSubtree::loadForest($groupQuery->get());
            $rootGalleries = $galleryQuery->get();
            $this->hydrateTreeGroupChains($groups);
            $rootGalleries->each(fn (Gallery $gallery): Gallery => $gallery->setRelation('galleryGroup', null));

            // A brand-bound management tree must not expose a child/group or
            // gallery whose parent chain is foreign or brand-less.  The SQL
            // brand predicates above only cover the row on the root query;
            // eager-loaded descendants still need the same invariant.
            if ($brand !== null) {
                $groups = $this->filterGroupsByBrand($groups, $brand);
                $rootGalleries = $rootGalleries
                    ->filter(fn (Gallery $gallery): bool => BrandRegistry::galleryTreeMatchesBrand($gallery, $brand))
                    ->values();
            }

            return [
                'groups' => $groups->toArray(),
                'root_galleries' => $rootGalleries->toArray(),
            ];
        };

        try {
            $tree = Cache::rememberForever($cacheKey, $buildTree);
        } catch (GalleryGroupBudgetExceededException $exception) {
            // Fail closed: an over-sized hierarchy is not rendered at all, and
            // nothing is cached so the next request re-evaluates the budget.
            Log::error('gallery_tree.budget_exceeded', [
                'cache_key' => $cacheKey,
                'limit' => $exception->limit(),
                'requested' => $exception->requested(),
                'kind' => $exception->kind(),
            ]);

            return ['groups' => [], 'root_galleries' => []];
        }

        $treeArray = json_decode(json_encode($tree), true);

        // Apply permission filter for non-admin users
        if (! $user->is_admin) {
            $allowedGalleryIds = $user->getAllowedGalleryIds();
            $treeArray = $this->filterTreeByPermissions($treeArray, $user, $allowedGalleryIds);
        }

        // Apply type filter if specified
        if ($filterType) {
            $treeArray = $this->filterTreeByType($treeArray, $filterType);
        }
        if ($orgId) {
            $treeArray = $this->filterTreeByOrg($treeArray, $orgId);
        }

        return $treeArray;
    }

    /**
     * Link every eager-loaded gallery to its in-memory group chain before the
     * tree is serialized. This keeps effective attributes and full paths from
     * issuing one parent query per node.
     *
     * The recursion is bounded by {@see GalleryGroupSubtree::MAX_LEVELS}, the
     * number of levels a bounded subtree can have; a deeper (or corrupt)
     * structure is cut off instead of recursing.
     *
     * @param  Collection<int, GalleryGroup>  $groups
     */
    private function hydrateTreeGroupChains(
        Collection $groups,
        ?GalleryGroup $parent = null,
        int $depth = 0,
    ): void {
        if ($depth >= GalleryGroupSubtree::MAX_LEVELS) {
            return;
        }

        $groups->each(function (GalleryGroup $group) use ($parent, $depth): void {
            $group->setRelation('parent', $parent);
            $group->getRelation('galleries')
                ->each(fn (Gallery $gallery): Gallery => $gallery->setRelation('galleryGroup', $group));

            $this->hydrateTreeGroupChains($group->getRelation('children'), $group, $depth + 1);
        });
    }

    /**
     * Recursively remove descendants whose complete group chain is not valid
     * for the brand-bound management tree.  This deliberately runs while the
     * cache is being built, before permission/type/org filters are applied.
     *
     * The traversal is depth-bounded (see {@see GalleryGroupSubtree::MAX_LEVELS})
     * and never re-visits a node id, so a malformed in-memory tree terminates.
     *
     * @param  Collection<int, GalleryGroup>  $groups
     * @param  array<string, bool>  $visited
     * @return Collection<int, GalleryGroup>
     */
    private function filterGroupsByBrand(
        Collection $groups,
        string $brand,
        int $depth = 0,
        array $visited = [],
    ): Collection {
        if ($depth >= GalleryGroupSubtree::MAX_LEVELS) {
            return new Collection;
        }

        return $groups
            ->map(function (GalleryGroup $group) use ($brand, $depth, $visited): ?GalleryGroup {
                $key = (string) $group->getKey();
                if (isset($visited[$key])) {
                    // Cycle or repeated node in a malformed tree.
                    return null;
                }

                if (! BrandRegistry::galleryGroupTreeMatchesBrand($group, $brand)) {
                    return null;
                }

                $branchVisited = $visited;
                $branchVisited[$key] = true;

                $group->setRelation(
                    'children',
                    $this->filterGroupsByBrand($group->getRelation('children'), $brand, $depth + 1, $branchVisited),
                );
                $group->setRelation(
                    'galleries',
                    $group->getRelation('galleries')
                        ->filter(fn (Gallery $gallery): bool => BrandRegistry::galleryTreeMatchesBrand($gallery, $brand))
                        ->values(),
                );

                return $group;
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function filterGroupsRecursive(
        array $groups,
        callable $galleryPredicate,
        ?callable $groupPredicate = null,
        int $depth = 0,
    ): array {
        if ($depth >= GalleryGroupSubtree::MAX_LEVELS) {
            return [];
        }

        $groupPredicate = $groupPredicate ?? fn (array $node): bool => true;
        $result = [];
        foreach ($groups as $group) {
            if (! $groupPredicate($group)) {
                continue;
            }
            if (isset($group['galleries'])) {
                $group['galleries'] = array_values(array_filter($group['galleries'], $galleryPredicate));
            }
            if (isset($group['children'])) {
                $group['children'] = $this->filterGroupsRecursive(
                    $group['children'],
                    $galleryPredicate,
                    $groupPredicate,
                    $depth + 1,
                );
            }
            $result[] = $group;
        }

        return $result;
    }

    /**
     * Filter tree by user permissions
     */
    private function filterTreeByPermissions(array $treeArray, User $user, array $allowedGalleryIds): array
    {
        $explicitGroupIds = [];
        if ($user->is_photographer) {
            $unrestrictedGroups = GalleryGroup::query()->where('restricted_photographers', false)->orWhereNull('restricted_photographers')->pluck('id')->toArray();
            $assignedGroups = $user->photographerGalleryGroups()->pluck('gallery_groups.id')->toArray();
            $explicitGroupIds = array_unique(array_merge($unrestrictedGroups, $assignedGroups));
        } else {
            $explicitGroupIds = $user->galleryGroups()->pluck('gallery_groups.id')->toArray();
        }

        if (! empty($explicitGroupIds)) {
            $explicitGroupIds = array_unique(array_merge($explicitGroupIds, app(AuthorizationService::class)->getSubGroupIds($explicitGroupIds)));
        }

        $galleryPredicate = fn (array $g): bool => in_array($g['id'], $allowedGalleryIds);
        $treeArray['groups'] = $this->pruneEmptyGroups(
            $this->filterGroupsRecursive($treeArray['groups'], $galleryPredicate),
            $explicitGroupIds
        );
        $treeArray['root_galleries'] = array_values(array_filter($treeArray['root_galleries'], $galleryPredicate));

        return $treeArray;
    }

    /**
     * Filter tree by gallery type (selection/delivery)
     */
    private function filterTreeByType(array $treeArray, string $filterType): array
    {
        $galleryPredicate = fn (array $g): bool => $g['type'] === $filterType;
        $treeArray['groups'] = $this->pruneEmptyGroups($this->filterGroupsRecursive($treeArray['groups'], $galleryPredicate));
        $treeArray['root_galleries'] = array_values(array_filter($treeArray['root_galleries'], $galleryPredicate));

        return $treeArray;
    }

    /**
     * Filter tree by org
     */
    private function filterTreeByOrg(array $treeArray, string $orgId): array
    {
        $orgGroupIds = GalleryGroup::whereHas('orgs', fn ($q) => $q->where('org_id', $orgId))->pluck('id')->toArray();
        $orgGalleryIds = Gallery::whereHas('orgs', fn ($q) => $q->where('org_id', $orgId))->pluck('id')->toArray();
        $groupPredicate = fn (array $node): bool => in_array($node['id'], $orgGroupIds);
        $galleryPredicate = fn (array $g): bool => in_array($g['id'], $orgGalleryIds);
        $treeArray['groups'] = $this->pruneEmptyGroups(
            $this->filterGroupsRecursive($treeArray['groups'], $galleryPredicate, $groupPredicate)
        );
        $treeArray['root_galleries'] = array_values(array_filter($treeArray['root_galleries'], $galleryPredicate));

        return $treeArray;
    }

    /**
     * Remove group husks that have neither galleries nor surviving children.
     * A structural parent (galleries empty but children non-empty) is preserved.
     *
     * Depth-bounded (see {@see GalleryGroupSubtree::MAX_LEVELS}); a stale cache
     * entry holding a deeper or malformed structure is cut off instead of
     * recursing.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<int, string>  $explicitGroupIds
     * @return array<int, array<string, mixed>>
     */
    private function pruneEmptyGroups(array $groups, array $explicitGroupIds = [], int $depth = 0): array
    {
        if ($depth >= GalleryGroupSubtree::MAX_LEVELS) {
            return [];
        }

        $result = [];
        foreach ($groups as $group) {
            $children = isset($group['children'])
                ? $this->pruneEmptyGroups($group['children'], $explicitGroupIds, $depth + 1)
                : [];
            $galleries = $group['galleries'] ?? [];
            if (! empty($galleries) || ! empty($children) || in_array($group['id'], $explicitGroupIds)) {
                $group['children'] = $children;
                $result[] = $group;
            }
        }

        return $result;
    }

    /**
     * Get all subgroup IDs recursively for a given group.
     *
     * Bounded and cycle-safe: at most {@see GalleryGroupSubtree::MAX_DEPTH}
     * levels and {@see GalleryGroupSubtree::MAX_NODES} nodes are visited, ids
     * are collected with a visited set, and the parent ids are queried in
     * chunks. The group's own id is never part of the result.
     *
     * Fail closed: if the node budget cannot be met, the descendants are not
     * reported at all (the caller therefore sees the group only) and the
     * cut-off is logged. A shorter answer would widen nothing but could hide
     * a structural problem, so the denial is audited instead.
     *
     * @return array<int, string>
     */
    public function getAllSubgroupIds(
        GalleryGroup $group,
        ?int $maxDepth = null,
        ?int $maxNodes = null,
    ): array {
        $key = $group->getKey();

        if (! is_string($key) && ! is_int($key)) {
            return [];
        }

        try {
            return GalleryGroupSubtree::descendantIds(
                [$key],
                $maxDepth ?? GalleryGroupSubtree::MAX_DEPTH,
                $maxNodes ?? GalleryGroupSubtree::MAX_NODES,
            );
        } catch (GalleryGroupBudgetExceededException $exception) {
            Log::error('gallery_groups.descendants_budget_exceeded', [
                'group_id' => (string) $key,
                'limit' => $exception->limit(),
                'requested' => $exception->requested(),
                'kind' => $exception->kind(),
            ]);

            return [];
        }
    }

    /**
     * Clear the cached gallery tree and related caches.
     *
     * The tree is cached once globally for cross-brand users and once per brand
     * for brand-bound users — all of those keys must be dropped.
     */
    public function clearCache(): void
    {
        Cache::forget($this->adminTreeCacheKey(null));
        Cache::forget('unrestricted_photographer_gallery_ids');

        $brands = array_keys(config('brands', []));

        $dbBrands = Gallery::query()->distinct()->pluck('brand')
            ->merge(GalleryGroup::query()->distinct()->pluck('brand'))
            ->filter()
            ->map(fn ($brand) => $brand instanceof Brand ? $brand->value : (string) $brand);

        foreach ($brands as $brand) {
            Cache::forget($this->adminTreeCacheKey((string) $brand));
        }

        foreach ($dbBrands->unique() as $brand) {
            Cache::forget($this->adminTreeCacheKey($brand));
        }
    }
}
