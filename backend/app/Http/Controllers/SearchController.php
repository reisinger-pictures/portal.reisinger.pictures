<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Location;
use App\Models\GalleryGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $q = $request->input('q', '');
        $user = auth('api')->user();

        $canSeeExpired = $user && ($user->is_admin || $user->is_photographer);

        if ($request->boolean('personal') && $user) {
            $allowedGalleryIds = $user->getAllowedGalleryIds();
            if (empty($allowedGalleryIds)) return response()->json(['galleries' => [], 'photos' => []]);
            
            $galQuery = Gallery::whereIn('id', $allowedGalleryIds);
            $phoQuery = Photo::whereIn('gallery_id', $allowedGalleryIds);
            
            if (!$canSeeExpired) {
                $galQuery->where(function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); });
                $phoQuery->whereHas('gallery', function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); });
            }
            
            return response()->json([
                'galleries' => $galQuery->orderBy('id', 'desc')->take(12)->get(),
                'photos' => $phoQuery->orderBy('id', 'desc')->take(24)->get()
            ]);
        }

        if (strlen($q) < 2) {
            $publicQuery = Gallery::where('is_public', true)
                ->where(function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); });
            $publicGalleryIds = $publicQuery->pluck('id')->toArray();

            if ($user && !$user->is_admin) {
                $allowed = $user->getAllowedGalleryIds();
                if (!$canSeeExpired && !empty($allowed)) {
                    $allowed = Gallery::whereIn('id', $allowed)->where(function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); })->pluck('id')->toArray();
                }
                $publicGalleryIds = array_unique(array_merge($allowed, $publicGalleryIds));
            }
            if (empty($publicGalleryIds)) return response()->json(['galleries' => [], 'photos' => []]);
            
            $galleries = Gallery::whereIn('id', $publicGalleryIds)->orderBy('id', 'desc')->take(12)->get();
            $photos = Photo::whereIn('gallery_id', $publicGalleryIds)->orderBy('id', 'desc')->take(24)->get();
            return response()->json(['galleries' => $galleries, 'photos' => $photos]);
        }

        $photoQuery = Photo::search($q);
        $galleryQuery = Gallery::search($q);
        $allowedGalleryIds = $user ? $user->getAllowedGalleryIds() : [];
        $publicGalleryIds = Gallery::where('is_public', true)->pluck('id')->toArray();
        
        if (!$canSeeExpired) {
            $allowedGalleryIds = empty($allowedGalleryIds) ? [] : Gallery::whereIn('id', $allowedGalleryIds)->where(function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); })->pluck('id')->toArray();
            $publicGalleryIds = empty($publicGalleryIds) ? [] : Gallery::whereIn('id', $publicGalleryIds)->where(function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); })->pluck('id')->toArray();
        }

        $finalIds = array_values(array_unique(array_merge($allowedGalleryIds, $publicGalleryIds)));

        if (empty($finalIds)) return response()->json(['galleries' => [], 'photos' => []]);

        $photoQuery->whereIn('gallery_id', $finalIds);
        $galleryQuery->whereIn('id', $finalIds);

        return response()->json([
            'galleries' => $galleryQuery->take(50)->get(),
            'photos' => $photoQuery->take(100)->get()
        ]);
    }

    public function locations(Request $request)
    {
        $q = $request->query('q');
        $type = $request->query('type');

        if (strlen($q) < 2 || !in_array($type, ['city', 'country'])) {
            return response()->json([]);
        }

        $results = Location::search($q)
            ->where('type', $type)
            ->orderBy('population', 'desc')
            ->take(10)
            ->get();

        return response()->json($results);
    }

    public function photoContext($id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        if (!$photo->gallery->is_public) {
            if (!$user || !$user->canAccessGallery($photo->gallery_id)) {
                abort(403);
            }
        }

        $breadcrumbs = [];
        $groupId = $photo->gallery->gallery_group_id;
        while ($groupId) {
            $group = GalleryGroup::find($groupId);
            if ($group) {
                array_unshift($breadcrumbs, ['name' => $group->name, 'full_path' => 'meta/' . $group->id, 'type' => 'group']);
                $groupId = $group->parent_id;
            } else { break; }
        }
        $breadcrumbs[] = ['name' => $photo->gallery->name, 'full_path' => $photo->gallery->full_path, 'type' => 'gallery'];

        return response()->json([
            'photo' => $photo,
            'breadcrumbs' => $breadcrumbs,
            'downloads_count' => 0 // Altlast entfernt: Einzelbild-Tracking via item_identifier wurde in V004 gedroppt
        ]);
    }
}
