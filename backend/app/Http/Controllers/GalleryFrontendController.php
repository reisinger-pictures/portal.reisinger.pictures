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

        $isExpired = $gallery->expires_at && \Carbon\Carbon::parse($gallery->expires_at)->isPast();
        $canManage = false;
        if ($user) {
            if ($user->is_super_admin || $user->is_admin) {
                $canManage = true;
            } elseif ($user->is_photographer && $user->canPhotographerAccessGallery($gallery->id)) {
                $canManage = true;
            }
        }

        if ($isExpired && !$canManage) {
            return response()->json(['error' => 'Galerie abgelaufen.'], 403);
        }

        if (!$gallery->is_public) {
            if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);
            if (!$user->canAccessGallery($gallery->id)) {
                return response()->json(['error' => 'Kein Zugriff auf diese Galerie.'], 403);
            }
        }

        $photos = $gallery->photos()->paginate(50);

        $photos->getCollection()->transform(function ($photo) use ($gallery, $user) {
            if ($user) {
                $rating = DB::table('ratings')
                    ->where('photo_id', $photo->id)
                    ->where(function($q) use ($user) {
                        if ($user->id) {
                            $q->where('user_id', $user->id);
                        } else {
                            $q->where('guest_id', $user->guest_id);
                        }
                    })
                    ->first();
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
            $group = \App\Models\GalleryGroup::find($groupId);
            if ($group) {
                array_unshift($breadcrumbs, ['name' => $group->name, 'full_path' => 'meta/' . $group->id, 'type' => 'group']);
                $groupId = $group->parent_id;
            } else {
                break;
            }
        }

        return response()->json([
            'gallery' => $gallery,
            'can_manage' => $canManage,
            'breadcrumbs' => $breadcrumbs,
            'downloads_count' => \App\Models\DownloadLog::where('gallery_id', $gallery->id)->count(),
            // ✨ FIX: Notified Count für den "E-Mail senden" Button im Management
            'notified_count' => DB::table('user_galleries')->where('gallery_id', $gallery->id)->where('wants_notifications', true)->count(),
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


        if ($photo->gallery->type !== 'selection') {
            return response()->json(['error' => 'Bewertungen sind nur in Auswahl-Galerien erlaubt.'], 422);
        }
        if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);

        $isExpired = $photo->gallery->expires_at && \Carbon\Carbon::parse($photo->gallery->expires_at)->isPast();
        $canManage = $user && ($user->is_admin || ($user->is_photographer && $user->canAccessGallery($photo->gallery_id)));

        if ($isExpired && !$canManage) {
            return response()->json(['error' => 'Galerie abgelaufen.'], 403);
        }

        if (!$photo->gallery->is_public) {
            if (!$user->canAccessGallery($photo->gallery_id)) {
                return response()->json(['error' => 'Kein Zugriff auf dieses Foto.'], 403);
            }
        }

        $existingRating = \App\Models\Rating::where([
            'photo_id' => $photo->id,
            'user_id' => $user->id,
            'guest_id' => $user->guest_id
        ])->first();

        if ($existingRating) {
            $existingRating->update([
                'rating' => $request->rating,
                'comment' => $request->comment ?? '',
                'guest_name' => $user->id ? null : $user->name
            ]);
        } else {
            \App\Models\Rating::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'photo_id' => $photo->id,
                'user_id' => $user->id,
                'guest_id' => $user->guest_id,
                'rating' => $request->rating,
                'comment' => $request->comment ?? '',
                'guest_name' => $user->id ? null : $user->name
            ]);
        }

        return response()->json(['success' => true]);
    }
}
