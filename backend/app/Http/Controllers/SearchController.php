<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Location;
use App\Models\GalleryGroup;
use App\Http\Resources\GalleryResource;
use App\Http\Resources\PhotoResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Support\BrandRegistry;

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
            
            $currentBrand = BrandRegistry::current();
            if ($currentBrand !== null) {
                $galQuery->where('brand', $currentBrand);
                $phoQuery->whereHas('gallery', fn($q) => $q->where('brand', $currentBrand));
            }
            
            if (!$canSeeExpired) {
                $galQuery->where(function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); });
                $phoQuery->whereHas('gallery', function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); });
            }
            
            $galResults = $galQuery->orderBy('id', 'desc')->take(12)->get();
            $phoResults = $phoQuery->orderBy('id', 'desc')->take(24)->get();
            return response()->json([
                'galleries' => $galResults->map(fn($g) => new GalleryResource($g))->values(),
                'photos' => $phoResults->map(fn($p) => new PhotoResource($p))->values()
            ]);
        }

        if (strlen($q) < 1) {
            $currentBrand = BrandRegistry::current();
            $publicQuery = Gallery::where('is_public', true)
                ->where(function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); });
            if ($currentBrand !== null) {
                $publicQuery->where('brand', $currentBrand);
            }
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
            return response()->json([
                'galleries' => $galleries->map(fn($g) => new GalleryResource($g))->values(),
                'photos' => $photos->map(fn($p) => new PhotoResource($p))->values()
            ]);
        }

        $photoQuery = Photo::search($q);
        $galleryQuery = Gallery::search($q);
        
        if (!$canSeeExpired) {
            $photoQuery->where('is_hidden', false);
            $galleryQuery->where('is_hidden', false);
        }

        $allowedGalleryIds = $user ? $user->getAllowedGalleryIds() : [];
        $currentBrand = BrandRegistry::current();
        $publicGalleryIds = Gallery::where('is_public', true);
        if ($currentBrand !== null) {
            $publicGalleryIds->where('brand', $currentBrand);
        }
        $publicGalleryIds = $publicGalleryIds->pluck('id')->toArray();
        
        if (!$canSeeExpired) {
            $allowedGalleryIds = empty($allowedGalleryIds) ? [] : Gallery::whereIn('id', $allowedGalleryIds)->where(function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); })->pluck('id')->toArray();
            $publicGalleryIds = empty($publicGalleryIds) ? [] : Gallery::whereIn('id', $publicGalleryIds)->where(function($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); })->pluck('id')->toArray();
        }

        $finalIds = array_values(array_unique(array_merge($allowedGalleryIds, $publicGalleryIds)));

        if (empty($finalIds)) return response()->json(['galleries' => [], 'photos' => []]);

        $photoQuery->whereIn('gallery_id', $finalIds);
        $galleryQuery->whereIn('id', $finalIds);

        $galResults = $galleryQuery->take(50)->get();
        $phoResults = $photoQuery->take(100)->get();
        return response()->json([
            'galleries' => $galResults->map(fn($g) => new GalleryResource($g))->values(),
            'photos' => $phoResults->map(fn($p) => new PhotoResource($p))->values()
        ]);
    }

    public function locations(Request $request)
    {
        $q = $request->query('q');
        $type = $request->query('type');

        if (strlen($q) < 1 || !in_array($type, ['city', 'country'])) {
            return response()->json([]);
        }

        $results = Location::search($q)
            ->where('type', $type)
            ->orderBy('population', 'desc')
            ->orderBy('postal_code', 'asc')
            ->take(30)
            ->get();

        if ($type === 'city') {
            // Merging: Bei Städten behalten wir pro Name nur den Eintrag mit der höchsten Population
            // (oder den ersten Treffer, falls Population bei beiden 0 ist)
            $results = $results->unique(function ($item) {
                return $item->name . '-' . $item->state;
            })->values()->take(10);
        }

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
            'photo' => new PhotoResource($photo),
            'breadcrumbs' => $breadcrumbs,
        ]);
    }
}
