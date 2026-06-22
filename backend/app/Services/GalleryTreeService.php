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
    public function getAdminTree(User $user, ?string $filterType = null): array
    {
        $tree = Cache::rememberForever('gallery_tree_admin', function () {
            $groups = GalleryGroup::whereNull('parent_id')->with(['children', 'galleries'])->get();
            $rootGalleries = Gallery::whereNull('gallery_group_id')->get();
            return [
                'groups' => $groups->toArray(),
                'root_galleries' => $rootGalleries->toArray()
            ];
        });

        $treeArray = json_decode(json_encode($tree), true);

        // Apply permission filter for non-admin users
        if (!$user->is_admin) {
            $allowedGalleryIds = $user->getAllowedGalleryIds();
            $treeArray = $this->filterTreeByPermissions($treeArray, $allowedGalleryIds);
        }

        // Apply type filter if specified
        if ($filterType) {
            $treeArray = $this->filterTreeByType($treeArray, $filterType);
        }

        return $treeArray;
    }

    /**
     * Filter tree by user permissions
     */
    private function filterTreeByPermissions(array $treeArray, array $allowedGalleryIds): array
    {
        $filterNode = function($groups) use (&$filterNode, $allowedGalleryIds) {
            $result = [];
            foreach ($groups as $group) {
                if (isset($group['galleries'])) {
                    $group['galleries'] = array_values(array_filter($group['galleries'], fn($g) => in_array($g['id'], $allowedGalleryIds)));
                }
                if (isset($group['children'])) {
                    $group['children'] = $filterNode($group['children']);
                }
                $result[] = $group;
            }
            return $result;
        };

        $treeArray['groups'] = $filterNode($treeArray['groups']);
        $treeArray['root_galleries'] = array_values(array_filter($treeArray['root_galleries'], fn($g) => in_array($g['id'], $allowedGalleryIds)));

        return $treeArray;
    }

    /**
     * Filter tree by gallery type (selection/delivery)
     */
    private function filterTreeByType(array $treeArray, string $filterType): array
    {
        $filterByType = function($groups) use (&$filterByType, $filterType) {
            $result = [];
            foreach ($groups as $group) {
                if (isset($group['galleries'])) {
                    $group['galleries'] = array_values(array_filter($group['galleries'], function($g) use ($filterType) {
                        return $g['type'] === $filterType;
                    }));
                }
                if (isset($group['children'])) {
                    $group['children'] = $filterByType($group['children']);
                }
                $result[] = $group;
            }
            return $result;
        };

        $treeArray['groups'] = $filterByType($treeArray['groups']);
        $treeArray['root_galleries'] = array_values(array_filter($treeArray['root_galleries'], function($g) use ($filterType) {
            return $g['type'] === $filterType;
        }));

        return $treeArray;
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
     * Clear the cached gallery tree
     */
    public function clearCache(): void
    {
        Cache::forget('gallery_tree_admin');
    }
}
