<?php

namespace App\Services;

use App\Enums\Brand;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StatsCalculationService
{
    /**
     * Normalize a brand (enum or raw string) to its stored string value.
     * `null` is unscoped only for a trusted persisted Super-Admin; the actor
     * guard in getStatsForUser() rejects legacy null-brand users.
     */
    private function normalizeBrand(mixed $brand): ?string
    {
        if ($brand === null) {
            return null;
        }

        return $brand instanceof Brand ? $brand->value : (string) $brand;
    }

    /**
     * Gallery IDs belonging to a brand as a query, not a materialized array.
     * A null brand is intentionally unscoped for a trusted cross-brand
     * Super-Admin; callers must only use that path after the actor guard.
     *
     * @return Builder<Gallery>
     */
    private function galleryIdsForBrand(?string $brand): Builder
    {
        return Gallery::query()
            ->select('id')
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where('brand', $brand),
            );
    }

    /**
     * Calculate domain statistics from raw query results
     */
    public function processDomainStats(object $rawDomainStats): array
    {
        $mapped = [];
        foreach ($rawDomainStats as $stat) {
            $domain = $stat->domain === 'invite.local' ? 'Benannte Invite Links' : $stat->domain;
            if (! isset($mapped[$domain])) {
                $mapped[$domain] = ['domain' => $domain, 'count' => 0];
            }
            $mapped[$domain]['count'] += $stat->count;
        }

        $domainStats = array_values($mapped);
        usort($domainStats, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array_slice($domainStats, 0, 10);
    }

    /**
     * Get statistics for admin users.
     *
     * A brand-bound admin only sees galleries/downloads of their own brand;
     * a trusted null-brand Super-Admin sees all brands.
     */
    public function getAdminStats(?string $tier = null, ?string $brand = null): array
    {
        $brand = $this->normalizeBrand($brand);
        $brandGalleryIds = $this->galleryIdsForBrand($brand);

        $tierFilterDb = function ($query) use ($tier) {
            if ($tier) {
                $query->where('download_logs.resolution_tier', $tier);
            }
        };

        $galleriesCount = $this->galleryIdsForBrand($brand)->count();

        $totalDownloads = DownloadLog::where('item_type', 'single_image')
            ->when($brand !== null, fn ($q) => $q->whereIn('gallery_id', $brandGalleryIds))
            ->where($tierFilterDb)
            ->count();
        $totalDownloads += DownloadLog::where('item_type', 'full_zip')
            ->when($brand !== null, fn ($q) => $q->whereIn('gallery_id', $brandGalleryIds))
            ->where($tierFilterDb)
            ->sum('photo_count');

        $guestDownloads = DownloadLog::whereNull('user_id')
            ->when($brand !== null, fn ($q) => $q->whereIn('gallery_id', $brandGalleryIds))
            ->where($tier ? function ($q) use ($tier) {
                $q->where('resolution_tier', $tier);
            } : function () {})
            ->count();

        $rawDomainStats = DB::table('download_logs')
            ->join('users', 'download_logs.user_id', '=', 'users.id')
            ->when($brand !== null, fn ($q) => $q->whereIn('download_logs.gallery_id', $brandGalleryIds))
            ->where($tierFilterDb)
            ->selectRaw("substr(users.email, instr(users.email, '@') + 1) as domain, COUNT(*) as count")
            ->groupBy('domain')
            ->get();

        $domainStats = $this->processDomainStats($rawDomainStats);

        $topGalleries = DB::table('download_logs')
            ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
            ->whereNotNull('gallery_name_snapshot')
            ->when($brand !== null, fn ($q) => $q->whereIn('gallery_id', $brandGalleryIds))
            ->where($tierFilterDb)
            ->groupBy('gallery_name_snapshot')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        return [
            'galleries_count' => $galleriesCount,
            'total_downloads' => $totalDownloads,
            'domain_stats' => $domainStats,
            'guest_downloads' => $guestDownloads,
            'top_galleries' => $topGalleries,
        ];
    }

    /**
     * Get statistics for org admin users.
     *
     * A brand-bound org admin only counts downloads of their own brand's
     * galleries; a trusted null-brand Super-Admin sees all brands.
     */
    public function getOrgAdminStats(User $user, ?string $tier = null, ?string $brand = null): array
    {
        $brand = $this->normalizeBrand($brand ?? $user->brand);
        $brandGalleryIds = $this->galleryIdsForBrand($brand);

        $orgUserIds = User::where('org_id', $user->org_id)->pluck('id')->toArray();

        $tierFilterDb = function ($query) use ($tier) {
            if ($tier) {
                $query->where('download_logs.resolution_tier', $tier);
            }
        };

        $totalDownloads = DownloadLog::whereIn('user_id', $orgUserIds)
            ->where('item_type', 'single_image')
            ->when($brand !== null, fn ($q) => $q->whereIn('gallery_id', $brandGalleryIds))
            ->where($tierFilterDb)
            ->count();

        $totalDownloads += DownloadLog::whereIn('user_id', $orgUserIds)
            ->where('item_type', 'full_zip')
            ->when($brand !== null, fn ($q) => $q->whereIn('gallery_id', $brandGalleryIds))
            ->where($tierFilterDb)
            ->sum('photo_count');

        $guestDownloads = 0;
        $galleriesCount = 0;

        $rawDomainStats = DB::table('download_logs')
            ->join('users', 'download_logs.user_id', '=', 'users.id')
            ->whereIn('download_logs.user_id', $orgUserIds)
            ->when($brand !== null, fn ($q) => $q->whereIn('download_logs.gallery_id', $brandGalleryIds))
            ->where($tierFilterDb)
            ->selectRaw("substr(users.email, instr(users.email, '@') + 1) as domain, COUNT(*) as count")
            ->groupBy('domain')
            ->get();

        $domainStats = $this->processDomainStats($rawDomainStats);

        $topGalleries = DB::table('download_logs')
            ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
            ->whereIn('user_id', $orgUserIds)
            ->whereNotNull('gallery_name_snapshot')
            ->when($brand !== null, fn ($q) => $q->whereIn('gallery_id', $brandGalleryIds))
            ->where($tierFilterDb)
            ->groupBy('gallery_name_snapshot')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        return [
            'galleries_count' => $galleriesCount,
            'total_downloads' => $totalDownloads,
            'domain_stats' => $domainStats,
            'guest_downloads' => $guestDownloads,
            'top_galleries' => $topGalleries,
        ];
    }

    /**
     * Get statistics for regular users (photographers).
     *
     * A brand-bound user only counts galleries of their own brand; a trusted
     * null-brand Super-Admin keeps the full gallery set.
     */
    public function getUserStats(User $user, ?string $tier = null, ?string $brand = null): array
    {
        $brand = $this->normalizeBrand($brand ?? $user->brand);

        $scopeToBrand = function ($query) use ($brand) {
            return $query->when(
                $brand !== null,
                fn ($scopedQuery) => $scopedQuery->where('galleries.brand', $brand),
            );
        };

        $galleryIds = array_unique(array_merge(
            $scopeToBrand($user->galleries())->pluck('galleries.id')->toArray(),
            $scopeToBrand($user->photographerGalleries())->pluck('galleries.id')->toArray(),
        ));

        $galleriesCount = count($galleryIds);

        $tierFilterDb = function ($query) use ($tier) {
            if ($tier) {
                $query->where('download_logs.resolution_tier', $tier);
            }
        };

        $totalDownloads = DownloadLog::whereIn('gallery_id', $galleryIds)
            ->where('item_type', 'single_image')
            ->where($tierFilterDb)
            ->count();

        $totalDownloads += DownloadLog::whereIn('gallery_id', $galleryIds)
            ->where('item_type', 'full_zip')
            ->where($tierFilterDb)
            ->sum('photo_count');

        $guestDownloads = DownloadLog::whereIn('gallery_id', $galleryIds)
            ->whereNull('user_id')
            ->where($tier ? function ($q) use ($tier) {
                $q->where('resolution_tier', $tier);
            } : function () {})
            ->count();

        $rawDomainStats = DB::table('download_logs')
            ->join('users', 'download_logs.user_id', '=', 'users.id')
            ->whereIn('download_logs.gallery_id', $galleryIds)
            ->where($tierFilterDb)
            ->selectRaw("substr(users.email, instr(users.email, '@') + 1) as domain, COUNT(*) as count")
            ->groupBy('domain')
            ->get();

        $domainStats = $this->processDomainStats($rawDomainStats);

        $topGalleries = DB::table('download_logs')
            ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
            ->whereIn('gallery_id', $galleryIds)
            ->whereNotNull('gallery_name_snapshot')
            ->where($tierFilterDb)
            ->groupBy('gallery_name_snapshot')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        return [
            'galleries_count' => $galleriesCount,
            'total_downloads' => $totalDownloads,
            'domain_stats' => $domainStats,
            'guest_downloads' => $guestDownloads,
            'top_galleries' => $topGalleries,
        ];
    }

    /**
     * Get statistics based on user role
     */
    public function getStatsForUser(User $user, ?string $tier = null): array
    {
        $authorization = app(AuthorizationService::class);
        if ($authorization->isReservedNullBrandActor($user) || $authorization->isTransientGuest($user)) {
            // Keep this service fail-closed as well as its controller/middleware.
            // A non-null sentinel brand intersects the user's gallery set with
            // no real galleries instead of interpreting NULL as cross-brand.
            return $this->getUserStats($user, $tier, '__reserved_null_brand_actor__');
        }

        $brand = $this->normalizeBrand($user->brand);

        if ($user->is_admin) {
            return $this->getAdminStats($tier, $brand);
        } elseif ($user->is_org_admin) {
            return $this->getOrgAdminStats($user, $tier, $brand);
        } else {
            return $this->getUserStats($user, $tier, $brand);
        }
    }
}
