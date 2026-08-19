<?php

namespace App\Http\Controllers;

use App\Http\Requests\StatsIndexRequest;
use App\Http\Resources\DownloadLogResource;
use App\Models\DownloadLog;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\StatsCalculationService;
use Illuminate\Http\Request;

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
        $svc = app(AuthorizationService::class);
        $tier = $request->query('tier');
        $query = DownloadLog::with('gallery.latestPhoto')->orderBy('id', 'desc');

        if ($tier) {
            $query->where('resolution_tier', $tier);
        }

        if ($svc->isOrgAdmin($user) && ! $svc->isAdmin($user)) {
            $orgUserIds = User::where('org_id', $user->org_id)->pluck('id');
            $query->whereIn('user_id', $orgUserIds);
        } elseif (! $svc->isAdmin($user)) {
            $galleryIds = array_unique(array_merge(
                $user->galleries()->pluck('galleries.id')->toArray(),
                $user->photographerGalleries()->pluck('galleries.id')->toArray()
            ));
            $query->whereIn('gallery_id', $galleryIds);
        }

        $paginated = $query->paginate(50);

        $paginated->getCollection()->transform(function ($log) {
            if ($log->gallery && $log->gallery->latestPhoto) {
                $log->thumb_url = $log->gallery->latestPhoto->thumb_url;
            }

            return $log;
        });

        $paginated->getCollection()->transform(fn ($log) => new DownloadLogResource($log));

        return response()->json($paginated);
    }
}
