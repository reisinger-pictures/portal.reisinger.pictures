<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\GalleryGroup;
use App\Models\Gallery;
use App\Models\Photo;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Requests\StoreGalleryRequest;
use App\Http\Requests\UpdateGalleryRequest;
use App\Http\Requests\SyncGalleryAccessRequest;
use App\Http\Resources\GalleryResource;
use App\Http\Resources\GalleryGroupResource;
use App\Http\Resources\PhotoResource;
use App\Services\GalleryService;
use App\Services\GalleryTreeService;
use App\Services\RatingService;
use Illuminate\Support\Facades\Gate;

class GalleryController extends Controller
{
    public function __construct(
        private GalleryTreeService $galleryTreeService,
        private GalleryService $galleryService,
        private RatingService $ratingService,
    ) {}

    /**
     * Zeigt den gesamten Galerie-Baum für die Verwaltung an.
     */
    public function indexAdmin(Request $request)
    {
        $user = auth('api')->user();
        $filterType = $request->query('filter_type');
        $orgId = $request->query('org_id');

        $treeArray = $this->galleryTreeService->getAdminTree($user, $filterType, $orgId);

        return response()->json($treeArray);
    }

    /**
     * Erstellt eine neue Meta-Galerie (Ordner).
     */
    public function storeGroup(StoreGroupRequest $request)
    {
        $data = $request->validated();
        $group = $this->galleryService->storeGroup($data);

        return response()->json(['success' => true, 'group' => new GalleryGroupResource($group)]);
    }

    /**
     * Aktualisiert eine Meta-Galerie (Ordner).
     */
    public function updateGroup(UpdateGroupRequest $request, $id)
    {
        $group = GalleryGroup::findOrFail($id);
        $group = $this->galleryService->updateGroup($group, $request->validated());

        return response()->json(['success' => true, 'group' => new GalleryGroupResource($group)]);
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
        $gallery = $this->galleryService->storeGallery($request->validated(), $user);

        return response()->json(['success' => true, 'gallery' => new GalleryResource($gallery)]);
    }

    /**
     * Aktualisiert eine bestehende Galerie.
     */
    public function updateGallery(UpdateGalleryRequest $request, $id)
    {
        $gallery = Gallery::findOrFail($id);
        $gallery = $this->galleryService->updateGallery($gallery, $request->validated());

        return response()->json(['success' => true, 'gallery' => new GalleryResource($gallery)]);
    }

    /**
     * Löscht eine Galerie und alle zugehörigen Dateien vom Speicher.
     */
    public function destroyGallery($id)
    {
        $gallery = Gallery::findOrFail($id);
        if (Gate::denies('manage', $gallery)) {
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
        if (Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        return response()->json($this->ratingService->ratingStatus($gallery));
    }

    /**
     * Exportiert die Bewertungen einer Galerie für den Lightroom-Abgleich.
     */
    public function exportRatings($id)
    {
        $gallery = Gallery::findOrFail($id);
        if (Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        return response()->json($this->ratingService->exportRatings($gallery));
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
            'group' => new GalleryGroupResource($group),
            'downloads_count' => \App\Models\DownloadLog::whereIn('gallery_id', $galleryIds)->count(),
            'photos' => $photos->items() ? collect($photos->items())->map(fn($p) => new PhotoResource($p))->values() : [],
            'current_page' => $photos->currentPage(),
            'last_page' => $photos->lastPage(),
            'total' => $photos->total()
        ]);
    }

    public function syncAccess(SyncGalleryAccessRequest $request, $id): JsonResponse {
        $user = auth('api')->user();
        if (!$user->is_admin) return response()->json(['error' => 'Nur Admins können Zugriffe direkt verwalten'], 403);

        $validated = $request->validated();
        $targetUser = \App\Models\User::findOrFail($validated['user_id']);

        $gallery = Gallery::findOrFail($id);
        if (Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        if ($validated['action'] === 'attach') {
            $targetUser->galleries()->syncWithoutDetaching([$id]);
        } else {
            $targetUser->galleries()->detach($id);
        }
        return response()->json(['success' => true]);
    }

    public function syncPhotographers(SyncGalleryAccessRequest $request, $id): JsonResponse {
        $user = auth('api')->user();
        if (!$user->is_admin && !$user->is_photographer) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $validated = $request->validated();
        $targetUser = \App\Models\User::findOrFail($validated['user_id']);

        $gallery = Gallery::findOrFail($id);
        if (Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        if ($validated['action'] === 'attach') {
            $targetUser->photographerGalleries()->syncWithoutDetaching([$id]);
        } else {
            $targetUser->photographerGalleries()->detach($id);
        }
        return response()->json(['success' => true]);
    }

    public function syncGroupPhotographers(SyncGalleryAccessRequest $request, $id): JsonResponse {
        $user = auth('api')->user();
        if (!$user->is_admin && !$user->is_photographer) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $validated = $request->validated();
        $targetUser = \App\Models\User::findOrFail($validated['user_id']);

        $group = \App\Models\GalleryGroup::findOrFail($id);
        if (!$user->is_super_admin && !$user->is_admin && !($user->is_photographer && $user->photographerGalleryGroups()->where('gallery_groups.id', $group->id)->exists())) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        if ($validated['action'] === 'attach') {
            $targetUser->photographerGalleryGroups()->syncWithoutDetaching([$id]);
        } else {
            $targetUser->photographerGalleryGroups()->detach($id);
        }
        return response()->json(['success' => true]);
    }
}
