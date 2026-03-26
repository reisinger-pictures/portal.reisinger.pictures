<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Support\Facades\DB;

class GalleryFrontendController extends Controller
{
    public function show($slug)
    {
        $gallery = Gallery::where('slug', $slug)->firstOrFail();
        $user = auth('api')->user();

        if (!$gallery->is_public) {
            if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);
            if (!$user->canAccessGallery($gallery->id)) {
                return response()->json(['error' => 'Kein Zugriff auf diese Galerie.'], 403);
            }
        }

        $photos = $gallery->photos()->paginate(50);

        $photos->getCollection()->transform(function ($photo) use ($gallery, $user) {
            $baseUrl = '/api/media/' . $gallery->slug;
            $photo->url = $baseUrl . '/' . $photo->filename;
            $photo->thumb_url = $baseUrl . '/_thumbs/' . md5($photo->filename . '1024') . '.webp';

            if ($user) {
                $rating = DB::table('ratings')
                    ->where('photo_id', $photo->id)
                    ->where('user_id', $user->id)
                    ->first();
                $photo->rating = $rating ? $rating->rating : null;
                // NEU: Den Kommentar ebenfalls an das Foto-Objekt anheften!
                $photo->comment = $rating ? $rating->comment : '';
            } else {
                $photo->rating = null;
                // NEU: Fallback für Gäste ohne Rating-Objekt
                $photo->comment = '';
            }

            return $photo;
        });

        $breadcrumbs = [];
        $groupId = $gallery->gallery_group_id;
        while ($groupId) {
            $group = AppModelsGalleryGroup::find($groupId);
            if ($group) {
                array_unshift($breadcrumbs, ['name' => $group->name, 'full_path' => 'meta/' . $group->id, 'type' => 'group']);
                $groupId = $group->parent_id;
            } else {
                break;
            }
        }

        return response()->json([
            'gallery' => $gallery,
            'breadcrumbs' => $breadcrumbs,
            'downloads_count' => \App\Models\DownloadLog::where('gallery_id', $gallery->id)->count(),
            'wants_notifications' => $user ? (bool) DB::table('user_galleries')->where('gallery_id', $gallery->id)->where('user_id', $user->id)->value('wants_notifications') : false,
            'photos' => $photos->items(),
            'current_page' => $photos->currentPage(),
            'last_page' => $photos->lastPage(),
            'total' => $photos->total()
        ]);
    }

    public function rate(Request $request, $photoId)
    {
        $request->validate([
            'rating' => 'required|integer|min:0|max:5',
            'comment' => 'nullable|string'
        ]);

        $photo = Photo::with('gallery')->findOrFail($photoId);
        $user = auth('api')->user();

        if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);

        if (!$photo->gallery->is_public) {
            if (!$user->canAccessGallery($photo->gallery_id)) {
                return response()->json(['error' => 'Kein Zugriff auf dieses Foto.'], 403);
            }
        }

        DB::table('ratings')->updateOrInsert(
            ['photo_id' => $photo->id, 'user_id' => $user->id],
            ['rating' => $request->rating, 'comment' => $request->comment ?? '']
        );

        return response()->json(['success' => true]);
    }
}
