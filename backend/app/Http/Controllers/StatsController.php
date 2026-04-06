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

    public function index()
    {
        $user = auth('api')->user();

        if ($user->is_admin) {
            // Admins sehen alles
            $galleriesCount = Gallery::count();
            $totalDownloads = DownloadLog::count();
            $guestDownloads = DownloadLog::whereNull('user_id')->count();
            
            $rawDomainStats = DB::table('download_logs')
                ->join('users', 'download_logs.user_id', '=', 'users.id')
                ->selectRaw('SUBSTRING_INDEX(users.email, "@", -1) as domain, COUNT(*) as count')
                ->groupBy('domain')
                ->get();
                
            $domainStats = $this->processDomainStats($rawDomainStats);

            $topGalleries = DB::table('download_logs')
                ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
                ->whereNotNull('gallery_name_snapshot')
                ->groupBy('gallery_name_snapshot')
                ->orderByDesc('count')->limit(5)->get();

            $topPhotos = DB::table('download_logs')
                ->select('item_identifier as name', DB::raw('COUNT(*) as count'))
                ->where('item_type', 'single_image')
                ->groupBy('item_identifier')
                ->orderByDesc('count')->limit(5)->get();
                
        } elseif ($user->is_customer_manager) {
            // Customer Manager: Sehen Analytics für ihre E-Mail-Domain
            $domain = substr(strrchr($user->email, "@"), 1);
            $tenantUserIds = User::where('email', 'like', '%@' . $domain)->pluck('id')->toArray();
            
            // Sie sehen nur Downloads, die von ihren Mitarbeitern getätigt wurden
            $totalDownloads = DownloadLog::whereIn('user_id', $tenantUserIds)->count();
            $guestDownloads = 0; // Mandanten-Downloads sind immer authentifiziert
            $galleriesCount = 0; // Nicht wirklich relevant für den Mandanten-Scope
            $domainStats = [['domain' => $domain, 'count' => $totalDownloads]];

            $topGalleries = DB::table('download_logs')
                ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
                ->whereIn('user_id', $tenantUserIds)
                ->whereNotNull('gallery_name_snapshot')
                ->groupBy('gallery_name_snapshot')
                ->orderByDesc('count')->limit(5)->get();

            $topPhotos = DB::table('download_logs')
                ->select('item_identifier as name', DB::raw('COUNT(*) as count'))
                ->where('item_type', 'single_image')
                ->whereIn('user_id', $tenantUserIds)
                ->groupBy('item_identifier')
                ->orderByDesc('count')->limit(5)->get();

        } else {
            // Fotografen sehen Statistiken zu ihren EIGENEN Galerien
            $galleryIds = $user->galleries()->pluck('galleries.id')->toArray();
            
            $galleriesCount = count($galleryIds);
            $totalDownloads = DownloadLog::whereIn('gallery_id', $galleryIds)->count();
            $guestDownloads = DownloadLog::whereIn('gallery_id', $galleryIds)->whereNull('user_id')->count();
            
            $rawDomainStats = DB::table('download_logs')
                ->join('users', 'download_logs.user_id', '=', 'users.id')
                ->whereIn('download_logs.gallery_id', $galleryIds)
                ->selectRaw('SUBSTRING_INDEX(users.email, "@", -1) as domain, COUNT(*) as count')
                ->groupBy('domain')
                ->get();
                
            $domainStats = $this->processDomainStats($rawDomainStats);

            $topGalleries = DB::table('download_logs')
                ->select('gallery_name_snapshot as name', DB::raw('COUNT(*) as count'))
                ->whereIn('gallery_id', $galleryIds)
                ->whereNotNull('gallery_name_snapshot')
                ->groupBy('gallery_name_snapshot')
                ->orderByDesc('count')->limit(5)->get();

            $topPhotos = DB::table('download_logs')
                ->select('item_identifier as name', DB::raw('COUNT(*) as count'))
                ->where('item_type', 'single_image')
                ->whereIn('gallery_id', $galleryIds)
                ->groupBy('item_identifier')
                ->orderByDesc('count')->limit(5)->get();
        }

        return response()->json([
            'galleries_count' => $galleriesCount,
            'total_downloads' => $totalDownloads,
            'domain_stats' => $domainStats,
            'guest_downloads' => $guestDownloads,
            'top_galleries' => $topGalleries,
            'top_photos' => $topPhotos
        ]);
    }

    public function logs(Request $request)
    {
        $user = auth('api')->user();
        $query = DownloadLog::orderBy('id', 'desc');

        if ($user->is_customer_manager && !$user->is_admin) {
            // Customer Manager dürfen nur Logs ihrer eigenen Domain-Nutzer sehen
            $domain = substr(strrchr($user->email, "@"), 1);
            $tenantUserIds = User::where('email', 'like', '%@' . $domain)->pluck('id')->toArray();
            $query->whereIn('user_id', $tenantUserIds);
        } elseif (!$user->is_admin) {
            // Fotografen sehen nur Downloads aus ihren Galerien
            $galleryIds = $user->galleries()->pluck('galleries.id')->toArray();
            $query->whereIn('gallery_id', $galleryIds);
        }

        return response()->json($query->paginate(50));
    }
}
