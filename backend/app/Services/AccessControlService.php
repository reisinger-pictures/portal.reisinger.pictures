<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AccessControlService
{
    /**
     * Get all gallery IDs a user is allowed to access.
     * Includes direct assignments, group assignments (recursive), tenant integration,
     * photographer-specific access, transient galleries, and brand scoping.
     *
     * @return array<string>
     */
    public function getAllowedGalleryIds(User $user): array
    {
        if ($user->guest_id) {
            return $user->transient_galleries ?? [];
        }

        // 1. Direct assignments
        $galleryIds = $user->galleries()->pluck('galleries.id')->toArray();

        // 2. Group assignments (recursive)
        $groupIds = $user->galleryGroups()->pluck('gallery_groups.id')->toArray();
        $allGroupIds = $this->getSubGroupIds($groupIds);

        if (!empty($allGroupIds)) {
            $groupGalleryIds = Gallery::whereIn('gallery_group_id', $allGroupIds)->pluck('id')->toArray();
            $galleryIds = array_unique(array_merge($galleryIds, $groupGalleryIds));
        }

        // 3. Tenant Integration (Direct column + pivot group assignments)
        if ($user->tenant_id) {
            $tenantGalleryIds = Gallery::where('tenant_id', $user->tenant_id)->where('type', 'delivery')->pluck('id')->toArray();
            $directGroupIds = GalleryGroup::where('tenant_id', $user->tenant_id)->pluck('id')->toArray();
            $pivotGroupIds = DB::table('gallery_group_tenant')->where('tenant_id', $user->tenant_id)->pluck('gallery_group_id')->toArray();

            $combinedGroupIds = array_unique(array_merge($directGroupIds, $pivotGroupIds));
            $allTenantGroupIds = $this->getSubGroupIds($combinedGroupIds);

            if (!empty($allTenantGroupIds)) {
                $groupGalleryIds = Gallery::whereIn('gallery_group_id', $allTenantGroupIds)->where('type', 'delivery')->pluck('id')->toArray();
                $tenantGalleryIds = array_unique(array_merge($tenantGalleryIds, $groupGalleryIds));
            }
            $galleryIds = array_unique(array_merge($galleryIds, $tenantGalleryIds));
        }

        if (!empty($user->transient_galleries)) {
            $galleryIds = array_unique(array_merge($galleryIds, $user->transient_galleries));
        }

        if ($user->is_photographer) {
            $buildUnrestricted = function () {
                $allGalleries = Gallery::with('galleryGroup')->get();
                return $allGalleries->filter(fn($g) => !$g->effective_restricted_photographers)->pluck('id')->toArray();
            };
            $unrestrictedIds = Cache::rememberForever('unrestricted_photographer_gallery_ids', $buildUnrestricted);
            $galleryIds = array_merge($galleryIds, $unrestrictedIds);

            $photogGalleryIds = $user->photographerGalleries()->pluck('galleries.id')->toArray();
            $galleryIds = array_merge($galleryIds, $photogGalleryIds);

            $photogGroupIds = $user->photographerGalleryGroups()->pluck('gallery_groups.id')->toArray();
            $allPhotogGroupIds = $this->getSubGroupIds($photogGroupIds);

            $groupGalleryIds = Gallery::whereIn('gallery_group_id', $allPhotogGroupIds)->pluck('id')->toArray();
            $galleryIds = array_merge($galleryIds, $groupGalleryIds);
        }

        $galleryIds = array_values(array_unique($galleryIds));

        // Brand scoping: brand-bound users (brand != null) see only galleries of their own brand.
        // Cross-brand users (brand = null, e.g. Super-Admin) see all brands. Guest/tenant gallery
        // assignments are also filtered so an SRP user can never reach a B2B gallery via stale links.
        if ($user->brand !== null) {
            $galleryIds = Gallery::whereIn('id', $galleryIds)
                ->where(function ($q) use ($user) {
                    $q->where('brand', $user->brand)->orWhereNull('brand');
                })
                ->pluck('id')
                ->toArray();
        }

        return $galleryIds;
    }

    /**
     * Recursively get all subgroup IDs (including the parents themselves) using a CTE.
     *
     * @param  array<string>  $parentIds
     * @return array<string>
     */
    public function getSubGroupIds(array $parentIds): array
    {
        if (empty($parentIds)) {
            return [];
        }

        $parentIds = array_values($parentIds);
        $placeholders = implode(', ', array_fill(0, count($parentIds), '?'));

        $query = "
            WITH RECURSIVE child_groups AS (
                SELECT id, parent_id FROM gallery_groups WHERE id IN ($placeholders)
                UNION ALL
                SELECT g.id, g.parent_id FROM gallery_groups g
                INNER JOIN child_groups cg ON g.parent_id = cg.id
            )
            SELECT id FROM child_groups
        ";

        $result = DB::select($query, $parentIds);
        return array_values(array_unique(array_column($result, 'id')));
    }
}
