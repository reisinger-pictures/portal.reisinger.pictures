<?php

namespace App\Services;

use App\Models\GalleryGroup;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class GalleryTreeService
{
    /**
     * Get the complete gallery tree for admin view with optional filtering
     */
    public function getAdminTree(User $user, ?string $filterType = null, ?string $orgId = null): array
    {
        $buildTree = function () {
            $groups = GalleryGroup::query()->whereNull('parent_id')->with(['children', 'galleries', 'orgs'])->get();
            $rootGalleries = Gallery::query()->whereNull('gallery_group_id')->get();
            return [
                'groups' => $groups->toArray(),
                'root_galleries' => $rootGalleries->toArray()
            ];
        };
        $tree = Cache::rememberForever('gallery_tree_admin', $buildTree);

        $treeArray = json_decode(json_encode($tree), true);

        // Apply permission filter for non-admin users
        if (!$user->is_admin) {
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


    private function filterGroupsRecursive(array $groups, callable $galleryPredicate, ?callable $groupPredicate = null): array
    {
        $groupPredicate = $groupPredicate ?? fn(array $node): bool => true;
        $result = [];
        foreach ($groups as $group) {
            if (!$groupPredicate($group)) {
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
            $unrestrictedGroups = \App\Models\GalleryGroup::query()->where('restricted_photographers', false)->orWhereNull('restricted_photographers')->pluck('id')->toArray();
            $assignedGroups = $user->photographerGalleryGroups()->pluck('gallery_groups.id')->toArray();
            $explicitGroupIds = array_unique(array_merge($unrestrictedGroups, $assignedGroups));
        } else {
            $explicitGroupIds = $user->galleryGroups()->pluck('gallery_groups.id')->toArray();
        }
        
        if (!empty($explicitGroupIds)) {
            $explicitGroupIds = array_unique(array_merge($explicitGroupIds, app(\App\Services\AccessControlService::class)->getSubGroupIds($explicitGroupIds)));
        }

        $galleryPredicate = fn(array $g): bool => in_array($g['id'], $allowedGalleryIds);
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
        $galleryPredicate = fn(array $g): bool => $g['type'] === $filterType;
        $treeArray['groups'] = $this->pruneEmptyGroups($this->filterGroupsRecursive($treeArray['groups'], $galleryPredicate));
        $treeArray['root_galleries'] = array_values(array_filter($treeArray['root_galleries'], $galleryPredicate));

        return $treeArray;
    }

    /**
     * Filter tree by org
     */
    private function filterTreeByOrg(array $treeArray, string $orgId): array
    {
        $orgGroupIds = \App\Models\GalleryGroup::whereHas('orgs', fn($q) => $q->where('org_id', $orgId))->pluck('id')->toArray();
        $orgGalleryIds = \App\Models\Gallery::whereHas('orgs', fn($q) => $q->where('org_id', $orgId))->pluck('id')->toArray();
        $groupPredicate = fn(array $node): bool => in_array($node['id'], $orgGroupIds);
        $galleryPredicate = fn(array $g): bool => in_array($g['id'], $orgGalleryIds);
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
            if (!empty($galleries) || !empty($children) || in_array($group['id'], $explicitGroupIds)) {
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
     */
    public function clearCache(): void
    {
        Cache::forget('gallery_tree_admin');
        Cache::forget('unrestricted_photographer_gallery_ids');
    }
}
