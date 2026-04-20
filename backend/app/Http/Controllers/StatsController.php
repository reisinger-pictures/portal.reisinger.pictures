<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\DownloadLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    private function processDomainStats($rawDomainStats)
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
        usort($domainStats, function($a, $b) { return $b['count'] <=> $a['count']; });
        return array_slice($domainStats, 0, 10);
    }

    public function index(Request $request)
    {
        $user = auth('api')->user();
        $tier = $request->query('tier');

        $tierFilter = function($query) use ($tier) {
            if ($tier) $query->where('resolution_tier', $tier);
        };

        $tierFilterDb = function($query) use ($tier) {
            if ($tier) $query->where('download_logs.resolution_tier', $tier);
        };

        if ($user->is_admin) {
            $galleriesCount = Gallery::count();

            $totalDownloads = DownloadLog::where('item_type', 'single_image')->where($tierFilterDb)->count();
            $totalDownloads += DownloadLog::where('item_type', 'full_zip')->where($tierFilterDb)->sum('photo_count');

            $guestDownloads = DownloadLog::whereNull('user_id')->where($tierFilter)->count();

            $rawDomainStats = DB::table('download_logs')
                ->join('users', 'download_logs.user_id', '=', 'users.id')
                ->where($tierFilterDb)
                ->selectRaw('SUBSTRING_INDEX(users.email, "@", -1) as domain, COUNT(*) as count')
                ->groupBy('domain')
                ->get();

            $domainStats = $this->processDomainStats($rawDomainStats);

            $topGalleries = DB::table('download_logs')
                ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
                ->whereNotNull('gallery_name_snapshot')
                ->where($tierFilterDb)
                ->groupBy('gallery_name_snapshot')
                ->orderByDesc('count')->limit(5)->get();


        } elseif ($user->is_customer_manager) {
            $domain = substr(strrchr($user->email, "@"), 1);
            $tenantUserIds = User::where('email', 'like', '%@' . $domain)->pluck('id')->toArray();

            $totalDownloads = DownloadLog::whereIn('user_id', $tenantUserIds)->where('item_type', 'single_image')->where($tierFilterDb)->count();
            $totalDownloads += DownloadLog::whereIn('user_id', $tenantUserIds)->where('item_type', 'full_zip')->where($tierFilterDb)->sum('photo_count');

            $guestDownloads = 0;
            $galleriesCount = 0;
            $domainStats = [['domain' => $domain, 'count' => $totalDownloads]];

            $topGalleries = DB::table('download_logs')
                ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
                ->whereIn('user_id', $tenantUserIds)
                ->whereNotNull('gallery_name_snapshot')
                ->where($tierFilterDb)
                ->groupBy('gallery_name_snapshot')
                ->orderByDesc('count')->limit(5)->get();


        } else {
            $galleryIds = $user->galleries()->pluck('galleries.id')->toArray();

            $galleriesCount = count($galleryIds);

            $totalDownloads = DownloadLog::whereIn('gallery_id', $galleryIds)->where('item_type', 'single_image')->where($tierFilterDb)->count();
            $totalDownloads += DownloadLog::whereIn('gallery_id', $galleryIds)->where('item_type', 'full_zip')->where($tierFilterDb)->sum('photo_count');

            $guestDownloads = DownloadLog::whereIn('gallery_id', $galleryIds)->whereNull('user_id')->where($tierFilter)->count();

            $rawDomainStats = DB::table('download_logs')
                ->join('users', 'download_logs.user_id', '=', 'users.id')
                ->whereIn('download_logs.gallery_id', $galleryIds)
                ->where($tierFilterDb)
                ->selectRaw('SUBSTRING_INDEX(users.email, "@", -1) as domain, COUNT(*) as count')
                ->groupBy('domain')
                ->get();

            $domainStats = $this->processDomainStats($rawDomainStats);

            $topGalleries = DB::table('download_logs')
                ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
                ->whereIn('gallery_id', $galleryIds)
                ->whereNotNull('gallery_name_snapshot')
                ->where($tierFilterDb)
                ->groupBy('gallery_name_snapshot')
                ->orderByDesc('count')->limit(5)->get();

        }

        return response()->json([
            'galleries_count' => $galleriesCount,
            'total_downloads' => $totalDownloads,
            'domain_stats' => $domainStats,
            'guest_downloads' => $guestDownloads,
            'top_galleries' => $topGalleries
        ]);
    }

    public function logs(Request $request)
    {
        $user = auth('api')->user();
        $tier = $request->query('tier');
        $query = DownloadLog::with('gallery.latestPhoto')->orderBy('id', 'desc');

        if ($tier) {
            $query->where('resolution_tier', $tier);
        }

        if ($user->is_customer_manager && !$user->is_admin) {
            $domain = substr(strrchr($user->email, "@"), 1);
            $tenantUserIds = User::where('email', 'like', '%@' . $domain)->pluck('id')->toArray();
            $query->whereIn('user_id', $tenantUserIds);
        } elseif (!$user->is_admin) {
            $galleryIds = $user->galleries()->pluck('galleries.id')->toArray();
            $query->whereIn('gallery_id', $galleryIds);
        }

        $paginated = $query->paginate(50);

        $paginated->getCollection()->transform(function ($log) {
            if ($log->gallery && $log->gallery->latestPhoto) {
                $log->thumb_url = $log->gallery->latestPhoto->thumb_url;
            }
            return $log;
        });

        return response()->json($paginated);
    }
}
