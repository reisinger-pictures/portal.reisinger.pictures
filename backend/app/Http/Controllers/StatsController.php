<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\DownloadLog;
use App\Models\User;
use App\Http\Requests\StatsIndexRequest;
use App\Services\StatsCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function __construct(
        private StatsCalculationService $statsCalculationService
    ) {}

    public function index(StatsIndexRequest $request)
    {
        $user = auth('api')->user();
        $tier = $request->query('tier');

        $stats = $this->statsCalculationService->getStatsForUser($user, $tier);

        return response()->json($stats);
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
