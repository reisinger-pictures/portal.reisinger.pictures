<?php

namespace App\Services;

use App\Models\User;
use App\Models\Gallery;
use App\Models\DownloadLog;
use Illuminate\Support\Facades\DB;

class StatsCalculationService
{
    /**
     * Calculate domain statistics from raw query results
     */
    public function processDomainStats(object $rawDomainStats): array
    {
        $mapped = [];
        foreach ($rawDomainStats as $stat) {
            $domain = $stat->domain === 'invite.local' ? 'Benannte Invite Links' : $stat->domain;
            if (!isset($mapped[$domain])) {
                $mapped[$domain] = ['domain' => $domain, 'count' => 0];
            }
            $mapped[$domain]['count'] += $stat->count;
        }

        $domainStats = array_values($mapped);
        usort($domainStats, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array_slice($domainStats, 0, 10);
    }

    /**
     * Get statistics for admin users
     */
    public function getAdminStats(?string $tier = null): array
    {
        $tierFilterDb = function($query) use ($tier) {
            if ($tier) $query->where('download_logs.resolution_tier', $tier);
        };

        $galleriesCount = Gallery::count();

        $totalDownloads = DownloadLog::where('item_type', 'single_image')->where($tierFilterDb)->count();
        $totalDownloads += DownloadLog::where('item_type', 'full_zip')->where($tierFilterDb)->sum('photo_count');

        $guestDownloads = DownloadLog::whereNull('user_id')->where($tier ? function($q) use ($tier) {
            $q->where('resolution_tier', $tier);
        } : function() {})->count();

        $rawDomainStats = DB::table('download_logs')
            ->join('users', 'download_logs.user_id', '=', 'users.id')
            ->where($tierFilterDb)
            ->selectRaw("substr(users.email, instr(users.email, '@') + 1) as domain, COUNT(*) as count")
            ->groupBy('domain')
            ->get();

        $domainStats = $this->processDomainStats($rawDomainStats);

        $topGalleries = DB::table('download_logs')
            ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
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
            'top_galleries' => $topGalleries
        ];
    }

    /**
     * Get statistics for org admin users
     */
    public function getOrgAdminStats(User $user, ?string $tier = null): array
    {
        $orgUserIds = User::where('org_id', $user->org_id)->pluck('id')->toArray();

        $tierFilterDb = function($query) use ($tier) {
            if ($tier) $query->where('download_logs.resolution_tier', $tier);
        };

        $totalDownloads = DownloadLog::whereIn('user_id', $orgUserIds)
            ->where('item_type', 'single_image')
            ->where($tierFilterDb)
            ->count();

        $totalDownloads += DownloadLog::whereIn('user_id', $orgUserIds)
            ->where('item_type', 'full_zip')
            ->where($tierFilterDb)
            ->sum('photo_count');

        $guestDownloads = 0;
        $galleriesCount = 0;

        $rawDomainStats = DB::table('download_logs')
            ->join('users', 'download_logs.user_id', '=', 'users.id')
            ->whereIn('download_logs.user_id', $orgUserIds)
            ->where($tierFilterDb)
            ->selectRaw("substr(users.email, instr(users.email, '@') + 1) as domain, COUNT(*) as count")
            ->groupBy('domain')
            ->get();

        $domainStats = $this->processDomainStats($rawDomainStats);

        $topGalleries = DB::table('download_logs')
            ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
            ->whereIn('user_id', $orgUserIds)
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
            'top_galleries' => $topGalleries
        ];
    }

    /**
     * Get statistics for regular users (photographers)
     */
    public function getUserStats(User $user, ?string $tier = null): array
    {
        $galleryIds = array_unique(array_merge(
            $user->galleries()->pluck('galleries.id')->toArray(),
            $user->photographerGalleries()->pluck('galleries.id')->toArray()
        ));
        $galleriesCount = count($galleryIds);

        $tierFilterDb = function($query) use ($tier) {
            if ($tier) $query->where('download_logs.resolution_tier', $tier);
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
            ->where($tier ? function($q) use ($tier) {
                $q->where('resolution_tier', $tier);
            } : function() {})
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
            'top_galleries' => $topGalleries
        ];
    }

    /**
     * Get statistics based on user role
     */
    public function getStatsForUser(User $user, ?string $tier = null): array
    {
        if ($user->is_admin) {
            return $this->getAdminStats($tier);
        } elseif ($user->is_org_admin) {
            return $this->getOrgAdminStats($user, $tier);
        } else {
            return $this->getUserStats($user, $tier);
        }
    }
}
