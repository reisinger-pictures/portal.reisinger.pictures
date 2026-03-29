<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $q = $request->input('q', '');
        $user = auth('api')->user();

        // --- PERSONAL FEED MODE (Für das Fotografen-Dashboard) ---
        if ($request->boolean('personal') && $user) {
            $allowedGalleryIds = $user->getAllowedGalleryIds();
            if (empty($allowedGalleryIds)) {
                return response()->json(['galleries' => [], 'photos' => []]);
            }
            $galleries = Gallery::whereIn('id', $allowedGalleryIds)->orderBy('id', 'desc')->take(12)->get();
            $photos = Photo::whereIn('gallery_id', $allowedGalleryIds)->orderBy('id', 'desc')->take(24)->get();

            $photos->transform(function($p) {
                $p->load('gallery');
                // URL Generierung wurde ins Photo Model ausgelagert
                return $p;
            });

            return response()->json(['galleries' => $galleries, 'photos' => $photos]);
        }

        // --- DISCOVERY MODE (Leere Suche) ---
        if (strlen($q) < 2) {
            $publicGalleryIds = Gallery::where('is_public', true)->pluck('id')->toArray();
            
            if ($user && !$user->is_admin) {
                $allowedGalleryIds = $user->getAllowedGalleryIds();
                $publicGalleryIds = array_unique(array_merge($allowedGalleryIds, $publicGalleryIds));
            } elseif ($user && $user->is_admin) {
                $publicGalleryIds = Gallery::pluck('id')->toArray();
            }

            if (empty($publicGalleryIds)) {
                return response()->json(['galleries' => [], 'photos' => []]);
            }

            $galleries = Gallery::whereIn('id', $publicGalleryIds)->orderBy('id', 'desc')->take(12)->get();
            $photos = Photo::whereIn('gallery_id', $publicGalleryIds)->orderBy('id', 'desc')->take(24)->get();

            $photos->transform(function($p) {
                $p->load('gallery');
                // URL Generierung wurde ins Photo Model ausgelagert
                return $p;
            });

            return response()->json([
                'galleries' => $galleries,
                'photos' => $photos
            ]);
        }

        // --- MEILISEARCH MODE ---
        $photoQuery = Photo::search($q);
        $galleryQuery = Gallery::search($q);

        if (!$user || !$user->is_admin) {
            $allowedGalleryIds = $user ? $user->getAllowedGalleryIds() : [];
            $publicGalleryIds = Gallery::where('is_public', true)->pluck('id')->toArray();
            
            // Clean up and merge unique string UUIDs for Meilisearch JSON compatibility
            $allowedGalleryIds = array_values(array_unique(array_merge($allowedGalleryIds, $publicGalleryIds)));

            if (empty($allowedGalleryIds)) {
                return response()->json(['galleries' => [], 'photos' => []]);
            }
            
            $photoQuery->whereIn('gallery_id', $allowedGalleryIds);
            $galleryQuery->whereIn('id', $allowedGalleryIds);
        }

        try {
            $photos = $photoQuery->take(100)->get();

            $photos->transform(function($p) {
                $p->load('gallery');
                // URL Generierung wurde ins Photo Model ausgelagert
                return $p;
            });

            $galleries = $galleryQuery->take(50)->get();
        } catch (\Exception $e) {
            Log::warning("Search error (missing index?): " . $e->getMessage());
            $photos = collect();
            $galleries = collect();
        }

        return response()->json([
            'galleries' => $galleries,
            'photos' => $photos
        ]);
    }

    public function photoContext($id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        if (!$photo->gallery->is_public) {
            if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);
            if (!$user->canAccessGallery($photo->gallery_id)) {
                return response()->json(['error' => 'Kein Zugriff auf dieses Foto.'], 403);
            }
        }

        $breadcrumbs = [];
        $breadcrumbs[] = ['name' => $photo->gallery->name, 'full_path' => $photo->gallery->full_path, 'type' => 'gallery'];

        $groupId = $photo->gallery->gallery_group_id;
        while ($groupId) {
            $group = GalleryGroup::find($groupId);
            if ($group) {
                $gPath = $group->slug;
                $pGroup = $group->parent;
                while($pGroup) { $gPath = $pGroup->slug . '/' . $gPath; $pGroup = $pGroup->parent; }
                
                array_unshift($breadcrumbs, ['name' => $group->name, 'full_path' => 'galleries/' . $gPath, 'type' => 'group']);
                $groupId = $group->parent_id;
            } else {
                break;
            }
        }

        // URL Generierung wurde ins Photo Model ausgelagert

        return response()->json([
            'photo' => $photo,
            'breadcrumbs' => $breadcrumbs,
            'downloads_count' => \App\Models\DownloadLog::where('gallery_id', $photo->gallery_id)->where('item_type', 'single_image')->where('item_identifier', $photo->filename)->count()
        ]);
    }

    public function locations(Request $request)
    {
        $q = $request->input('q', '');
        $type = $request->input('type');

        if (strlen($q) < 2 || !in_array($type, ['city', 'country'])) {
            return response()->json([]);
        }

        try {
            // Nutzung von Meilisearch für Typo-Toleranz und Relevanz-basiertes Ranking
            $locations = \App\Models\Location::search($q)
                ->where('type', $type)
                ->orderBy('population', 'desc')
                ->take(10)
                ->get();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Location DB error: " . $e->getMessage());
            $locations = collect();
        }

        return response()->json($locations);
    }
}
