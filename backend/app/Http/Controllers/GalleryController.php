<?php

namespace App\Http\Controllers;

use App\Exceptions\GalleryGroupBudgetExceededException;
use App\Http\Requests\StoreGalleryRequest;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\SyncGalleryAccessRequest;
use App\Http\Requests\UpdateGalleryRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GalleryGroupResource;
use App\Http\Resources\GalleryResource;
use App\Http\Resources\PhotoResource;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\GalleryPhotoCleanupService;
use App\Services\GalleryService;
use App\Services\GalleryTreeService;
use App\Services\RatingService;
use App\Support\BrandRegistry;
use App\Support\GalleryGroupSubtree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class GalleryController extends Controller
{
    public function __construct(
        private GalleryTreeService $galleryTreeService,
        private GalleryService $galleryService,
        private RatingService $ratingService,
        private GalleryPhotoCleanupService $galleryPhotoCleanup,
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
        $group->load('orgs');

        return response()->json(['success' => true, 'group' => new GalleryGroupResource($group)]);
    }

    /**
     * Aktualisiert eine Meta-Galerie (Ordner).
     */
    public function updateGroup(UpdateGroupRequest $request, $id)
    {
        $group = GalleryGroup::with('orgs')->findOrFail($id);
        $group = $this->galleryService->updateGroup($group, $request->validated());
        // sync() does not refresh an already eager-loaded relation. Reload it
        // so the resource always reflects the pivot after an explicit change.
        $group->load('orgs');

        return response()->json(['success' => true, 'group' => new GalleryGroupResource($group)]);
    }

    /**
     * Löscht eine Meta-Galerie. Unterordner fallen durch DB-Constraints in die Root-Ebene.
     */
    public function deleteGroup($id)
    {
        $group = GalleryGroup::findOrFail($id);
        $user = auth('api')->user();
        $svc = app(AuthorizationService::class);
        if (! $user || ! $svc->canManageGalleryGroup($user, $group)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

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
        $user = auth('api')->user();
        // Fail closed on the service's non-nullable actor contract.
        // `UpdateGalleryRequest::authorize()` already requires the `manage`
        // gate, so an unauthenticated request is rejected before this point;
        // the explicit check keeps the guarantee local instead of implicit.
        abort_if($user === null, 401, 'Unauthenticated');

        $gallery = $this->galleryService->updateGallery($gallery, $request->validated(), $user);

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

        $galleryId = (string) $gallery->getKey();

        DB::transaction(function () use ($galleryId): void {
            // Always reload and lock the row inside the transaction. A retried
            // DB::transaction() must not reuse an Eloquent instance whose
            // exists/deleted state belongs to the rolled-back attempt.
            $lockedGallery = Gallery::query()
                ->whereKey($galleryId)
                ->lockForUpdate()
                ->firstOrFail();
            $photoIds = Photo::query()
                ->where('gallery_id', $galleryId)
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $photoId): string => (string) $photoId)
                ->all();

            $deleted = Gallery::withoutSyncingToSearch(
                static fn (): bool => $lockedGallery->delete(),
            );
            if ($deleted !== true) {
                throw new \RuntimeException('Gallery deletion was cancelled.');
            }

            // Register the public photos-disk and Scout-removal intents in the
            // same transaction as the row deletion. The durable dispatcher
            // commits the intents first, then dispatches after commit.
            $this->galleryPhotoCleanup->afterGalleryDelete(
                $galleryId,
                'manual_gallery_delete',
                $photoIds,
            );
        }, 3);

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
        $group = GalleryGroup::with('orgs')->findOrFail($id);
        // Bounded, cycle-safe subtree load. The `children` relation itself is
        // deliberately not self-eager-loading (unbounded depth, endless loop
        // on corrupt cycles), so the nested group structure is filled here
        // within the documented depth budget.
        try {
            GalleryGroupSubtree::loadSubtree($group);
        } catch (GalleryGroupBudgetExceededException $exception) {
            // Fail closed: the group itself is still rendered, but no nested
            // structure and therefore no descendant galleries are exposed.
            Log::error('gallery_groups.show_subtree_budget_exceeded', [
                'group_id' => (string) $group->getKey(),
                'limit' => $exception->limit(),
                'requested' => $exception->requested(),
                'kind' => $exception->kind(),
            ]);
            $group->setRelation('children', new Collection);
        }
        $user = auth('api')->user();
        $svc = app(AuthorizationService::class);

        // A brand-bound actor must not use a current-brand child to bypass a
        // foreign/null parent.  Cross-brand Super-Admins intentionally retain
        // their documented all-brand management access.
        if (! $user
            || ! $svc->sharesBrand($user, $group->brand)
            || ($user->brand !== null && ! BrandRegistry::galleryGroupTreeMatchesBrand($group, $user->brand))) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $userBrand = BrandRegistry::normalizeId($user?->brand);
        if ($userBrand !== null) {
            $group->setRelation(
                'children',
                $this->filterGroupChildrenForBrand($group->children, $userBrand),
            );
        }

        $groupIds = $this->galleryTreeService->getAllSubgroupIds($group);
        $groupIds[] = $group->id;

        $galleryQuery = Gallery::query()
            ->with('galleryGroup')
            ->withCount('photos');
        $galleryQuery->whereIn('gallery_group_id', $groupIds);
        if ($user->brand !== null) {
            $galleryQuery->where('brand', $user->brand);
        }

        // Do not let an own-brand gallery under a foreign descendant leak into
        // the management group response.  The same helper is also used by the
        // public boundaries, keeping the invariant centralized.
        $galleries = $galleryQuery->get()
            ->filter(function (Gallery $gallery) use ($user): bool {
                return $user->brand === null
                    || BrandRegistry::galleryTreeMatchesBrand($gallery, $user->brand);
            });

        if (! $svc->isAdmin($user)) {
            $allowedGalleryIds = array_flip($user->getAllowedGalleryIds());
            $galleries = $galleries->filter(
                fn (Gallery $gallery): bool => isset($allowedGalleryIds[$gallery->id])
            );
        }

        $galleryIds = $galleries->pluck('id')->values()->all();
        $galleryPricingSources = $galleries
            ->sortBy('id')
            ->map(fn (Gallery $gallery): array => [
                'gallery_id' => $gallery->id,
                'gallery_group_id' => $gallery->gallery_group_id,
                'gallery_name' => $gallery->name,
                'photo_count' => (int) $gallery->photos_count,
            ])
            ->values();

        // The meta-gallery UI resolves licensing per child gallery. Eager-load
        // the gallery and its parent-group reference so the response carries
        // the same child descriptor contract as the rest of the management API;
        // the paginated photo list must not force a lazy load per row.
        $photos = Photo::with('gallery.galleryGroup')
            ->whereIn('gallery_id', $galleryIds)
            ->orderBy('id', 'desc')
            ->paginate(50);

        return response()->json([
            'group' => new GalleryGroupResource($group),
            // This summary is complete for the authorized gallery set, not just
            // the current photo page. The management UI needs full per-child
            // counts to resolve retroactive volume tiers deterministically.
            'gallery_pricing_sources' => $galleryPricingSources,
            'downloads_count' => DownloadLog::whereIn('gallery_id', $galleryIds)->count(),
            'photos' => $photos->items() ? collect($photos->items())->map(fn ($p) => new PhotoResource($p))->values() : [],
            'current_page' => $photos->currentPage(),
            'last_page' => $photos->lastPage(),
            'total' => $photos->total(),
        ]);
    }

    /**
     * Remove foreign/null descendants before a management group resource is
     * serialized.  Photo filtering alone is insufficient because the resource
     * also exposes the nested group structure.
     *
     * @param  Collection<int, GalleryGroup>  $groups
     * @param  array<string, bool>  $visited
     * @return Collection<int, GalleryGroup>
     */
    private function filterGroupChildrenForBrand(
        Collection $groups,
        string $brand,
        int $depth = 0,
        array $visited = [],
    ): Collection {
        if ($depth >= GalleryGroupSubtree::MAX_LEVELS) {
            return new Collection;
        }

        return $groups
            ->map(function (GalleryGroup $group) use ($brand, $depth, $visited): ?GalleryGroup {
                $key = (string) $group->getKey();
                if (isset($visited[$key])) {
                    // Cycle or repeated node in a malformed tree.
                    return null;
                }

                if (! BrandRegistry::galleryGroupTreeMatchesBrand($group, $brand)) {
                    return null;
                }

                $branchVisited = $visited;
                $branchVisited[$key] = true;

                if ($group->relationLoaded('children')) {
                    $group->setRelation(
                        'children',
                        $this->filterGroupChildrenForBrand(
                            $group->getRelation('children'),
                            $brand,
                            $depth + 1,
                            $branchVisited,
                        ),
                    );
                }
                if ($group->relationLoaded('galleries')) {
                    $group->setRelation(
                        'galleries',
                        $group->getRelation('galleries')
                            ->filter(fn (Gallery $gallery): bool => BrandRegistry::galleryTreeMatchesBrand($gallery, $brand))
                            ->values(),
                    );
                }

                return $group;
            })
            ->filter()
            ->values();
    }

    public function syncAccess(SyncGalleryAccessRequest $request, $id): JsonResponse
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        if (! $svc->isAdmin($user)) {
            return response()->json(['error' => 'Nur Admins können Zugriffe direkt verwalten'], 403);
        }

        $validated = $request->validated();
        $targetUser = User::findOrFail($validated['user_id']);

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

    public function syncPhotographers(SyncGalleryAccessRequest $request, $id): JsonResponse
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        if (! $svc->isAdmin($user) && ! $svc->isPhotographer($user)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }
        $validated = $request->validated();
        $targetUser = User::findOrFail($validated['user_id']);

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

    public function syncGroupPhotographers(SyncGalleryAccessRequest $request, $id): JsonResponse
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        if (! $svc->isAdmin($user) && ! $svc->isPhotographer($user)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }
        $validated = $request->validated();
        $targetUser = User::findOrFail($validated['user_id']);

        $group = GalleryGroup::findOrFail($id);
        if (! $user || ! $svc->canManageGalleryGroup($user, $group)) {
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
