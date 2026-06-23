<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GalleryGroup;
use App\Models\Gallery;
use App\Models\Photo;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Requests\StoreGalleryRequest;
use App\Http\Requests\UpdateGalleryRequest;
use App\Services\GalleryTreeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class GalleryController extends Controller
{
    public function __construct(
        private GalleryTreeService $galleryTreeService
    ) {}

    /**
     * Zeigt den gesamten Galerie-Baum für die Verwaltung an.
     */
    public function indexAdmin(Request $request)
    {
        $user = auth('api')->user();
        $filterType = $request->query('filter_type');

        $treeArray = $this->galleryTreeService->getAdminTree($user, $filterType);

        return response()->json($treeArray);
    }

    /**
     * Erstellt eine neue Meta-Galerie (Ordner).
     */
    public function storeGroup(StoreGroupRequest $request)
    {
        $slug = $request->slug ? Str::slug($request->slug) : Str::slug($request->name);
        $count = \App\Models\GalleryGroup::where('slug', 'LIKE', "{$slug}%")->count();
        $slug = $count > 0 ? "{$slug}-{$count}" : $slug;

        $group = GalleryGroup::create([
            'name' => $request->name,
            'slug' => $slug,
            'parent_id' => $request->parent_id,
            'is_public' => $request->is_public,
            'is_free_download' => $request->is_free_download ?? false,
            'is_editorial_only' => $request->is_editorial_only ?? false,
            'is_hidden' => $request->is_hidden ?? false,
            'restricted_photographers' => $request->restricted_photographers,
            'tenant_id' => $request->tenant_id
        ]);
        return response()->json(['success' => true, 'group' => $group]);
    }

    /**
     * Aktualisiert eine Meta-Galerie (Ordner).
     */
    public function updateGroup(UpdateGroupRequest $request, $id)
    {
        $group = GalleryGroup::findOrFail($id);

        $slug = $request->slug ? Str::slug($request->slug) : Str::slug($request->name);
        if ($slug !== $group->slug) {
            $count = \App\Models\GalleryGroup::where('slug', 'LIKE', "{$slug}%")->count();
            $slug = $count > 0 ? "{$slug}-{$count}" : $slug;
        }

        $group->update([
            'name' => $request->name,
            'slug' => $slug,
            'parent_id' => $request->parent_id,
            'is_public' => $request->is_public,
            'is_free_download' => $request->is_free_download,
            'is_editorial_only' => $request->is_editorial_only,
            'is_hidden' => $request->is_hidden,
            'restricted_photographers' => $request->restricted_photographers
        ]);

        return response()->json(['success' => true, 'group' => $group]);
    }

    /**
     * Löscht eine Meta-Galerie. Unterordner fallen durch DB-Constraints in die Root-Ebene.
     */
    public function deleteGroup($id)
    {
        $group = GalleryGroup::findOrFail($id);
        $group->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Erstellt eine neue Galerie.
     */
    public function storeGallery(StoreGalleryRequest $request)
    {
        $user = auth('api')->user();

        $slug = $request->slug ? Str::slug($request->slug) : Str::slug($request->name);
        $count = \App\Models\Gallery::where('slug', 'LIKE', "{$slug}%")->count();
        $slug = $count > 0 ? "{$slug}-{$count}" : $slug;

        $isPublic = $request->is_public ?? false;

        if ($request->gallery_group_id) {
            $group = GalleryGroup::find($request->gallery_group_id);
            if ($group && !is_null($group->is_public)) {
                $isPublic = $group->is_public;
            }
        }

        if ($request->type === 'selection') {
            $isPublic = false;
        }

        $expiresAt = null;
        if ($request->expires_at) {
            try {
                $expiresAt = Carbon::parse($request->expires_at)->endOfDay();
            } catch (\Exception $e) {
                throw \Illuminate\Validation\ValidationException::withMessages(['expires_at' => 'Ungültiges Datumsformat.']);
            }
        }

        return DB::transaction(function () use ($request, $slug, $isPublic, $user, $expiresAt) {
            $gallery = Gallery::create([
                'name' => $request->name,
                'slug' => $slug,
                'type' => $request->type,
                'is_live' => $request->type === 'selection' ? false : ($request->is_live ?? false),
                'is_public' => $isPublic,
                'is_free_download' => $request->is_free_download ?? false,
                'is_editorial_only' => $request->is_editorial_only ?? false,
                'is_hidden' => $request->is_hidden ?? false,
                'restricted_photographers' => $request->restricted_photographers ?? null,
                'tenant_id' => $request->tenant_id,
                'gallery_group_id' => $request->gallery_group_id,
                'password_hash' => $request->password ? Hash::make($request->password) : null,
                'expires_at' => $expiresAt,
                'allow_client_metadata_edit' => $request->allow_client_metadata_edit ?? false,
                'apply_metadata_to_photos' => $request->apply_metadata_to_photos ?? false,
                'default_title' => $request->default_title,
                'default_description' => $request->default_description,
                'default_keywords' => $request->default_keywords,
                'default_location' => $request->default_location,
                'default_city' => $request->default_city,
                'default_state' => $request->default_state,
                'default_country' => $request->default_country,
                'default_iso_country' => $request->default_iso_country,
            ]);

            if ($user && $user->is_photographer) {
                $user->photographerGalleries()->syncWithoutDetaching([$gallery->id]);
            }

            return response()->json(['success' => true, 'gallery' => $gallery]);
        }, 3);
    }

    /**
     * Aktualisiert eine bestehende Galerie.
     */
    public function updateGallery(UpdateGalleryRequest $request, $id)
    {
        $gallery = Gallery::findOrFail($id);
        if (\Illuminate\Support\Facades\Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $validated = $request->validated();

        if (array_key_exists('slug', $validated) && $validated['slug'] !== $gallery->slug) {
            $slug = Str::slug($validated['slug']);
            $count = \App\Models\Gallery::where('slug', 'LIKE', "{$slug}%")->count();
            $validated['slug'] = $count > 0 ? "{$slug}-{$count}" : $slug;
        }

        if (array_key_exists('expires_at', $validated)) {
            if ($validated['expires_at']) {
                try {
                    $validated['expires_at'] = Carbon::parse($validated['expires_at'])->endOfDay();
                } catch (\Exception $e) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['expires_at' => 'Ungültiges Datumsformat.']);
                }
            } else {
                $validated['expires_at'] = null;
            }
        }

        if ($request->filled('password')) {
            $validated['password_hash'] = Hash::make($request->password);
        }
        unset($validated['password']);

        if (isset($validated['type']) && $validated['type'] === 'selection') {
            $validated['is_live'] = false;
            $validated['is_public'] = false;
        }

        foreach (['is_free_download', 'is_editorial_only', 'is_hidden'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] === null) {
                $validated[$field] = false;
            }
        }

        $gallery->update($validated);

        // Retroaktive Metadaten-Übernahme mit sicherem Chunking für Meilisearch
        if ($gallery->apply_metadata_to_photos) {
            $gallery->photos()->chunkById(100, function ($photos) use ($gallery) {
                $hasUpdates = false;
                foreach ($photos as $photo) {
                    $changed = false;
                    if (empty($photo->title) && $gallery->default_title) { $photo->title = $gallery->default_title; $changed = true; }
                    if (empty($photo->description) && $gallery->default_description) { $photo->description = $gallery->default_description; $changed = true; }
                    if (empty($photo->keywords) && $gallery->default_keywords) { $photo->keywords = $gallery->default_keywords; $changed = true; }
                    if (empty($photo->location) && $gallery->default_location) { $photo->location = $gallery->default_location; $changed = true; }
                    if (empty($photo->city) && $gallery->default_city) { $photo->city = $gallery->default_city; $changed = true; }
                    if (empty($photo->state) && $gallery->default_state) { $photo->state = $gallery->default_state; $changed = true; }
                    if (empty($photo->country) && $gallery->default_country) { $photo->country = $gallery->default_country; $changed = true; }
                    if (empty($photo->iso_country) && $gallery->default_iso_country) { $photo->iso_country = $gallery->default_iso_country; $changed = true; }
                    if ($changed) { $photo->save(); $hasUpdates = true; }
                }
                if ($hasUpdates) {
                    $photos->searchable();
                }
            });
        }

        return response()->json(['success' => true, 'gallery' => $gallery]);
    }

    /**
     * Löscht eine Galerie und alle zugehörigen Dateien vom Speicher.
     */
    public function destroyGallery($id)
    {
        $gallery = Gallery::findOrFail($id);
        if (\Illuminate\Support\Facades\Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Nur Super-Admins oder der besitzende Fotograf dürfen diese Galerie löschen.'], 403);
        }
        
        // Dispatch Job to delete files asynchronously
        \App\Jobs\DeleteGalleryFolderJob::dispatch((string) $gallery->id);

        $gallery->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Zeigt den Status der Bewertungen pro Nutzer/Gast für eine Galerie an.
     */
    public function ratingStatus($id)
    {
        $gallery = Gallery::findOrFail($id);
        if (\Illuminate\Support\Facades\Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }
        $totalPhotos = $gallery->photos()->count();

        $users = \App\Models\User::whereHas('galleries', function($q) use ($id) {
            $q->where('galleries.id', $id);
        })->get();

        $status = [];
        foreach ($users as $u) {
            $ratedCount = DB::table('ratings')
                ->join('photos', 'ratings.photo_id', '=', 'photos.id')
                ->where('photos.gallery_id', $id)
                ->where('ratings.user_id', $u->id)
                ->where('ratings.rating', '>', 0)
                ->count();

            $status[] = [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'rated_count' => $ratedCount,
                'total_photos' => $totalPhotos
            ];
        }

        $guestRatings = DB::table('ratings')
            ->join('photos', 'ratings.photo_id', '=', 'photos.id')
            ->where('photos.gallery_id', $id)
            ->whereNull('ratings.user_id')
            ->select('ratings.guest_id', 'ratings.guest_name', DB::raw('COUNT(IF(ratings.rating > 0, 1, NULL)) as rated_count'))
            ->groupBy('ratings.guest_id', 'ratings.guest_name')
            ->get();

        foreach ($guestRatings as $gr) {
            $status[] = [
                'user_id' => 'guest_' . $gr->guest_id,
                'name' => $gr->guest_name ?? 'Gast',
                'email' => '@invite.local',
                'rated_count' => $gr->rated_count,
                'total_photos' => $totalPhotos
            ];
        }

        return response()->json(['users' => $status, 'total_photos' => $totalPhotos]);
    }

    /**
     * Exportiert die Bewertungen einer Galerie für den Lightroom-Abgleich.
     */
    public function exportRatings($id)
    {
        $gallery = Gallery::findOrFail($id);
        if (\Illuminate\Support\Facades\Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }
        $photos = Photo::where('gallery_id', $id)->get();
        $export = [];

        foreach ($photos as $photo) {
            $ratings = DB::table('ratings')
                ->leftJoin('users', 'ratings.user_id', '=', 'users.id')
                ->where('photo_id', $photo->id)
                ->select('ratings.rating', 'ratings.comment', 'ratings.guest_name', 'ratings.guest_id', 'users.name')
                ->get();

            if ($ratings->isEmpty()) continue;

            $comments = [];
            foreach ($ratings as $r) {
                $ratingStr = $r->rating > 0 ? $r->rating . ' Sterne' : 'Ignoriert';
                $displayName = $r->name ?? ($r->guest_name ?? 'Gast');
                $line = "{$displayName} ({$ratingStr})";
                if (!empty($r->comment)) $line .= ": {$r->comment}";
                $comments[] = $line;
            }

            $export[] = [
                'id' => $photo->id,
                'filename' => $photo->title ?: 'Bild ' . substr($photo->id, 0, 8),
                'thumb_url' => $photo->thumb_url,
                'lr_uuid' => $photo->lr_uuid,
                'avg_rating' => ceil($ratings->where('rating', '>', 0)->avg('rating') ?? 0),
                'all_comments' => implode("\n", $comments)
            ];
        }

        return response()->json($export);
    }

    /**
     * Zeigt die Bilder einer Meta-Galerie (Sammelansicht).
     */
    public function showGroup($id)
    {
        $group = GalleryGroup::with('children')->findOrFail($id);
        $user = auth('api')->user();

        $groupIds = $this->galleryTreeService->getAllSubgroupIds($group);
        $groupIds[] = $group->id;

        $galleryIds = Gallery::whereIn('gallery_group_id', $groupIds)->pluck('id')->toArray();

        if (!$user->is_admin) {
            $allowedGalleryIds = $user->getAllowedGalleryIds();
            $galleryIds = array_intersect($galleryIds, $allowedGalleryIds);
        }

        $photos = Photo::whereIn('gallery_id', $galleryIds)->orderBy('id', 'desc')->paginate(50);

        return response()->json([
            'group' => $group,
            'downloads_count' => \App\Models\DownloadLog::whereIn('gallery_id', $galleryIds)->count(),
            'photos' => $photos->items(),
            'current_page' => $photos->currentPage(),
            'last_page' => $photos->lastPage(),
            'total' => $photos->total()
        ]);
    }

    public function syncAccess(Request $request, $id) {
        $user = auth('api')->user();
        if (!$user->is_admin) return response()->json(['error' => 'Nur Admins können Zugriffe direkt verwalten'], 403);
        
        $request->validate(['user_id' => 'required|string', 'action' => 'required|in:attach,detach']);
        $targetUser = \App\Models\User::findOrFail($request->user_id);
        
        if ($request->action === 'attach') {
            $targetUser->galleries()->syncWithoutDetaching([$id]);
        } else {
            $targetUser->galleries()->detach($id);
        }
        return response()->json(['success' => true]);
    }

    public function syncPhotographers(Request $request, $id) {
        $user = auth('api')->user();
        if (!$user->is_admin && !$user->is_photographer) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $request->validate(['user_id' => 'required|string', 'action' => 'required|in:attach,detach']);
        $targetUser = \App\Models\User::findOrFail($request->user_id);
        if ($request->action === 'attach') {
            $targetUser->photographerGalleries()->syncWithoutDetaching([$id]);
        } else {
            $targetUser->photographerGalleries()->detach($id);
        }
        return response()->json(['success' => true]);
    }

    public function syncGroupPhotographers(Request $request, $id) {
        $user = auth('api')->user();
        if (!$user->is_admin && !$user->is_photographer) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $request->validate(['user_id' => 'required|string', 'action' => 'required|in:attach,detach']);
        $targetUser = \App\Models\User::findOrFail($request->user_id);
        if ($request->action === 'attach') {
            $targetUser->photographerGalleryGroups()->syncWithoutDetaching([$id]);
        } else {
            $targetUser->photographerGalleryGroups()->detach($id);
        }
        return response()->json(['success' => true]);
    }
}
