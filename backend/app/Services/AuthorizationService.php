<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuthorizationService
{
    /**
     * Get all gallery IDs a user is allowed to access.
     * Includes direct assignments, group assignments (recursive), org integration,
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

        // 3. Org Integration (pivot group assignments)
        if ($user->org_id) {
            $orgGalleryIds = Gallery::whereHas('orgs', fn($q) => $q->where('orgs.id', $user->org_id))->where('type', 'delivery')->pluck('id')->toArray();
            $pivotGroupIds = DB::table('gallery_group_org')->where('org_id', $user->org_id)->pluck('gallery_group_id')->toArray();

            $combinedGroupIds = $pivotGroupIds;
            $allOrgGroupIds = $this->getSubGroupIds($combinedGroupIds);

            if (!empty($allOrgGroupIds)) {
                $groupGalleryIds = Gallery::whereIn('gallery_group_id', $allOrgGroupIds)->where('type', 'delivery')->pluck('id')->toArray();
                $orgGalleryIds = array_unique(array_merge($orgGalleryIds, $groupGalleryIds));
            }
            $galleryIds = array_unique(array_merge($galleryIds, $orgGalleryIds));
        }

        if (!empty($user->transient_galleries)) {
            $galleryIds = array_unique(array_merge($galleryIds, $user->transient_galleries));
        }

        if ($this->isPhotographer($user)) {
            $buildUnrestricted = function () {
                $allGalleries = Gallery::with('galleryGroup')->get();
                return $allGalleries->filter(fn($g) => !$g->effective_restricted_photographers)->pluck('id')->toArray();
            };
            $unrestrictedIds = Cache::remember('unrestricted_photographer_gallery_ids', now()->addMinutes(5), $buildUnrestricted);
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
        // Cross-brand users (brand = null, e.g. Super-Admin) see all brands. Guest/org gallery
        // assignments are also filtered so an SRP user can never reach a B2B gallery via stale links.
        if ($user->brand !== null) {
            $galleryIds = Gallery::whereIn('id', $galleryIds)
                ->where('brand', $user->brand)
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

    /**
     * Check whether a user holds any of the given role names.
     */
    public function hasRole(User $user, string ...$roles): bool
    {
        return $user->roles()->whereIn('name', $roles)->exists();
    }

    /**
     * Get the role names of a user.
     *
     * @return array<string>
     */
    public function roleNames(User $user): array
    {
        return $user->roles->pluck('name')->all();
    }

    public function isSuperAdmin(User $user): bool
    {
        return $user->roles()->where('name', UserRole::SUPER_ADMIN->value)->exists();
    }

    public function isAdmin(User $user): bool
    {
        return $user->roles()->whereIn('name', [UserRole::ADMIN->value, UserRole::SUPER_ADMIN->value])->exists();
    }

    public function isPhotographer(User $user): bool
    {
        return $user->roles()->where('name', UserRole::PHOTOGRAPHER->value)->exists();
    }

    public function isPowerUser(User $user): bool
    {
        return $user->roles()->where('name', UserRole::POWER_USER->value)->exists();
    }

    public function isOrgAdmin(User $user): bool
    {
        return $user->roles()->where('name', UserRole::ORG_ADMIN->value)->exists() && $user->org_id !== null;
    }

    /**
     * Mirrors User::getIsPendingAttribute(): a user without guest context and
     * without any role, gallery-group, or gallery assignment is pending.
     */
    public function isPending(User $user): bool
    {
        if ($user->guest_id) {
            return false;
        }
        return $user->roles()->count() === 0
            && $user->galleryGroups()->count() === 0
            && $user->galleries()->count() === 0;
    }

    /**
     * Mirrors User::canPhotographerAccessGallery().
     */
    public function canPhotographerAccessGallery(User $user, string $galleryId): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        if (!$this->isPhotographer($user)) {
            return false;
        }

        $gallery = Gallery::find($galleryId);
        if (!$gallery) {
            return false;
        }

        if (!$gallery->effective_restricted_photographers) {
            return true;
        }

        if ($user->photographerGalleries()->where('galleries.id', $galleryId)->exists()) {
            return true;
        }

        $groupIds = $user->photographerGalleryGroups()->pluck('gallery_groups.id')->toArray();
        if (!empty($groupIds)) {
            $allGroupIds = $this->getSubGroupIds($groupIds);
            if (in_array($gallery->gallery_group_id, $allGroupIds)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors User::canAccessGallery().
     */
    public function canAccessGallery(User $user, string $galleryId): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if ($this->isPhotographer($user) && $this->canPhotographerAccessGallery($user, $galleryId)) {
            return true;
        }

        return in_array($galleryId, $this->getAllowedGalleryIds($user));
    }

    /**
     * Mirrors GalleryPolicy::manage().
     */
    public function canManageGallery(User $user, string $galleryId): bool
    {
        return $this->isSuperAdmin($user)
            || $this->isAdmin($user)
            || ($this->isPhotographer($user) && $this->canPhotographerAccessGallery($user, $galleryId));
    }
}
