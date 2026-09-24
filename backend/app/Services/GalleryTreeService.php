<?php

namespace App\Services;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class GalleryTreeService
{
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

        $brand = $authorization->isTrustedCrossBrandActor($user)
            ? null
            : ($user->brand instanceof Brand ? $user->brand->value : (string) $user->brand);

        $cacheKey = $brand === null ? 'gallery_tree_admin' : 'gallery_tree_admin_'.$brand;

        $buildTree = function () use ($brand) {
            $groupQuery = GalleryGroup::query()
                ->whereNull('parent_id')
                ->with(['children', 'children.orgs', 'children.galleries.galleryGroup.parent', 'galleries.galleryGroup.parent', 'orgs']);
            $galleryQuery = Gallery::query()
                ->whereNull('gallery_group_id')
                ->with('galleryGroup.parent');

            if ($brand !== null) {
                $groupQuery->where('brand', $brand);
                $galleryQuery->where('brand', $brand);
            }

            $groups = $groupQuery->get();
            $rootGalleries = $galleryQuery->get();

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
        $tree = Cache::rememberForever($cacheKey, $buildTree);

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
     * Recursively remove descendants whose complete group chain is not valid
     * for the brand-bound management tree.  This deliberately runs while the
     * cache is being built, before permission/type/org filters are applied.
     *
     * @param  Collection<int, GalleryGroup>  $groups
     * @return Collection<int, GalleryGroup>
     */
    private function filterGroupsByBrand(Collection $groups, string $brand): Collection
    {
        return $groups
            ->map(function (GalleryGroup $group) use ($brand): ?GalleryGroup {
                if (! BrandRegistry::galleryGroupTreeMatchesBrand($group, $brand)) {
                    return null;
                }

                $group->setRelation(
                    'children',
                    $this->filterGroupsByBrand($group->getRelation('children'), $brand),
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

    private function filterGroupsRecursive(array $groups, callable $galleryPredicate, ?callable $groupPredicate = null): array
    {
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
                $group['children'] = $this->filterGroupsRecursive($group['children'], $galleryPredicate, $groupPredicate);
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
     */
    private function pruneEmptyGroups(array $groups, array $explicitGroupIds = []): array
    {
        $result = [];
        foreach ($groups as $group) {
            $children = isset($group['children']) ? $this->pruneEmptyGroups($group['children'], $explicitGroupIds) : [];
            $galleries = $group['galleries'] ?? [];
            if (! empty($galleries) || ! empty($children) || in_array($group['id'], $explicitGroupIds)) {
                $group['children'] = $children;
                $result[] = $group;
            }
        }

        return $result;
    }

    /**
     * Get all subgroup IDs recursively for a given group
     */
    public function getAllSubgroupIds(GalleryGroup $group): array
    {
        $ids = [];
        foreach ($group->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $this->getAllSubgroupIds($child));
        }

        return $ids;
    }

    /**
     * Clear the cached gallery tree and related caches.
     *
     * The tree is cached once globally for cross-brand users and once per brand
     * for brand-bound users — all of those keys must be dropped.
     */
    public function clearCache(): void
    {
        Cache::forget('gallery_tree_admin');
        Cache::forget('unrestricted_photographer_gallery_ids');

        $brands = array_keys(config('brands', []));

        $dbBrands = Gallery::query()->distinct()->pluck('brand')
            ->merge(GalleryGroup::query()->distinct()->pluck('brand'))
            ->filter()
            ->map(fn ($brand) => $brand instanceof Brand ? $brand->value : (string) $brand);

        foreach ($brands as $brand) {
            Cache::forget('gallery_tree_admin_'.$brand);
        }

        foreach ($dbBrands->unique() as $brand) {
            Cache::forget('gallery_tree_admin_'.$brand);
        }
    }
}
