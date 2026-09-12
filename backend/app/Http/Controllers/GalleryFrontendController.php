<?php

namespace App\Http\Controllers;

use App\Http\Resources\GalleryResource;
use App\Http\Resources\PhotoResource;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use App\Models\Rating;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GalleryFrontendController extends Controller
{
    public function show($slug)
    {
        $gallery = Gallery::where('slug', $slug)->firstOrFail();
        $currentBrand = BrandRegistry::current();
        if ($currentBrand !== null && $gallery->brand !== null && $gallery->brand !== $currentBrand) {
            return response()->json(['error' => 'Galerie nicht gefunden.'], 404);
        }
        $user = auth('api')->user();
        $svc = app(AuthorizationService::class);

        $isExpired = $gallery->expires_at && Carbon::parse($gallery->expires_at)->isPast();
        $canManage = false;
        if ($user) {
            if ($svc->isSuperAdmin($user) || $svc->isAdmin($user)) {
                $canManage = true;
            } elseif ($svc->isPhotographer($user) && $svc->canPhotographerAccessGallery($user, $gallery->id)) {
                $canManage = true;
            }
        }

        if ($isExpired && ! $canManage) {
            return response()->json(['error' => 'Galerie abgelaufen.'], 403);
        }

        if (! $gallery->is_public) {
            if (! $user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            if (! $svc->canAccessGallery($user, $gallery->id)) {
                return response()->json(['error' => 'Kein Zugriff auf diese Galerie.'], 403);
            }
        }

        $photos = $gallery->photos()->paginate(50);

        $photoIds = $photos->getCollection()->pluck('id')->toArray();
        $ratings = collect();

        if ($user && ! empty($photoIds)) {
            $ratingQuery = DB::table('ratings')->whereIn('photo_id', $photoIds);
            if ($user->id) {
                $ratingQuery->where('user_id', $user->id);
            } else {
                $ratingQuery->where('guest_id', $user->guest_id);
            }
            $ratings = $ratingQuery->get()->keyBy('photo_id');
        }

        $photos->getCollection()->transform(function ($photo) use ($user, $ratings) {
            if ($user) {
                $rating = $ratings->get($photo->id);
                $photo->rating = $rating ? (int) $rating->rating : null;
                $photo->comment = $rating ? $rating->comment : '';
            } else {
                $photo->rating = null;
                $photo->comment = '';
            }

            return $photo;
        });

        $breadcrumbs = [];
        $groupId = $gallery->gallery_group_id;
        while ($groupId) {
            $group = GalleryGroup::find($groupId);
            if ($group) {
                array_unshift($breadcrumbs, ['name' => $group->name, 'full_path' => 'meta/'.$group->id, 'type' => 'group']);
                $groupId = $group->parent_id;
            } else {
                break;
            }
        }

        return response()->json([
            'gallery' => new GalleryResource($gallery),
            'can_manage' => $canManage,
            'breadcrumbs' => $breadcrumbs,
            'downloads_count' => DownloadLog::where('gallery_id', $gallery->id)->count(),
            // ✨ FIX: Notified Count für den "E-Mail senden" Button im Management
            'notified_count' => DB::table('user_galleries')->where('gallery_id', $gallery->id)->where('wants_notifications', true)->count(),
            'wants_notifications' => $user ? (bool) DB::table('user_galleries')->where('gallery_id', $gallery->id)->where('user_id', $user->id)->value('wants_notifications') : false,
            'photos' => $photos->items() ? collect($photos->items())->map(fn ($p) => new PhotoResource($p))->values() : [],
            'current_page' => $photos->currentPage(),
            'last_page' => $photos->lastPage(),
            'total' => $photos->total(),
        ]);
    }

    public function rate(Request $request, $photoId)
    {
        $request->validate([
            'rating' => 'required|integer|min:0|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $photo = Photo::with('gallery')->findOrFail($photoId);
        $user = auth('api')->user();
        $svc = app(AuthorizationService::class);

        if ($photo->gallery->type !== 'selection') {
            return response()->json(['error' => 'Bewertungen sind nur in Auswahl-Galerien erlaubt.'], 422);
        }
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $isExpired = $photo->gallery->expires_at && Carbon::parse($photo->gallery->expires_at)->isPast();
        $canManage = $user && ($svc->isAdmin($user) || ($svc->isPhotographer($user) && $svc->canAccessGallery($user, $photo->gallery_id)));

        if ($isExpired && ! $canManage) {
            return response()->json(['error' => 'Galerie abgelaufen.'], 403);
        }

        if (! $photo->gallery->is_public) {
            if (! $svc->canAccessGallery($user, $photo->gallery_id)) {
                return response()->json(['error' => 'Kein Zugriff auf dieses Foto.'], 403);
            }
        }

        if ($user->id) {
            Rating::updateOrCreate(
                ['photo_id' => $photo->id, 'user_id' => $user->id],
                ['rating' => $request->rating, 'comment' => $request->comment ?? '']
            );
        } else {
            Rating::updateOrCreate(
                ['photo_id' => $photo->id, 'guest_id' => $user->guest_id],
                ['rating' => $request->rating, 'comment' => $request->comment ?? '', 'guest_name' => $user->name]
            );
        }

        return response()->json(['success' => true]);
    }
}
