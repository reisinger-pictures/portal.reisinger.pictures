<?php

namespace App\Http\Controllers;

use App\Http\Resources\GalleryResource;
use App\Http\Resources\PhotoResource;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Location;
use App\Models\Photo;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $q = $request->input('q', '');
        $user = auth('api')->user();
        $svc = app(AuthorizationService::class);
        $currentBrand = BrandRegistry::currentIdOrNull();

        // Search results are public surfaces.  Without a concrete host brand
        // (or for a null-brand request context) fail closed instead of
        // exposing every configured brand.
        if ($currentBrand === null) {
            return response()->json(['galleries' => [], 'photos' => []]);
        }

        $canSeeExpired = $user && ($svc->isAdmin($user) || $svc->isPhotographer($user));

        if ($request->boolean('personal') && $user) {
            $allowedGalleryIds = $user->getAllowedGalleryIds();
            if (empty($allowedGalleryIds)) {
                return response()->json(['galleries' => [], 'photos' => []]);
            }

            $galQuery = Gallery::whereIn('id', $allowedGalleryIds);
            $phoQuery = Photo::whereIn('gallery_id', $allowedGalleryIds);
            $galQuery->where('brand', $currentBrand)
                ->where('is_hidden', false);
            $phoQuery->where('is_hidden', false)
                ->whereHas('gallery', fn ($query) => $query
                    ->where('brand', $currentBrand)
                    ->where('is_hidden', false));

            if (! $canSeeExpired) {
                $galQuery->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                });
                $phoQuery->whereHas('gallery', function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                });
            }

            // Raw flags are only query prefilters; the effective accessor below
            // is the authoritative inherited-visibility decision.
            $galResults = $galQuery->orderBy('id', 'desc')->take(12)->get()
                ->filter(fn (Gallery $gallery): bool => ! $gallery->effective_is_hidden
                    && BrandRegistry::galleryTreeMatchesCurrent($gallery))
                ->values();
            $phoResults = $phoQuery->orderBy('id', 'desc')->take(24)->get()
                ->filter(fn (Photo $photo): bool => ! $photo->effective_is_hidden
                    && $photo->gallery instanceof Gallery
                    && BrandRegistry::galleryTreeMatchesCurrent($photo->gallery))
                ->values();

            return response()->json([
                'galleries' => $galResults->map(fn ($g) => new GalleryResource($g))->values(),
                'photos' => $phoResults->map(fn ($p) => new PhotoResource($p))->values(),
            ]);
        }

        if (strlen($q) < 1) {
            $publicQuery = Gallery::where('is_public', true)
                ->where('type', 'delivery')
                ->where('brand', $currentBrand)
                ->where('is_hidden', false)
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                });
            $publicGalleryIds = $this->brandScopedGalleryIds(
                $publicQuery->pluck('id')->toArray(),
                $currentBrand,
            );

            if ($user && ! $svc->isAdmin($user)) {
                $allowed = $user->getAllowedGalleryIds();
                $allowed = $this->brandScopedGalleryIds($allowed, $currentBrand);
                if (! $canSeeExpired && ! empty($allowed)) {
                    $allowed = Gallery::whereIn('id', $allowed)->where(function ($query) {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })->pluck('id')->toArray();
                }
                $publicGalleryIds = array_unique(array_merge($allowed, $publicGalleryIds));
            }
            if (empty($publicGalleryIds)) {
                return response()->json(['galleries' => [], 'photos' => []]);
            }

            $galleries = Gallery::whereIn('id', $publicGalleryIds)
                ->orderBy('id', 'desc')
                ->take(12)
                ->get()
                ->filter(fn (Gallery $gallery): bool => ! $gallery->effective_is_hidden)
                ->values();
            $photos = Photo::whereIn('gallery_id', $publicGalleryIds)
                ->where('is_hidden', false)
                ->orderBy('id', 'desc')
                ->take(24)
                ->get()
                ->filter(fn (Photo $photo): bool => ! $photo->effective_is_hidden)
                ->values();

            return response()->json([
                'galleries' => $galleries->map(fn ($g) => new GalleryResource($g))->values(),
                'photos' => $photos->map(fn ($p) => new PhotoResource($p))->values(),
            ]);
        }

        $photoQuery = Photo::search($q);
        $galleryQuery = Gallery::search($q);

        if (! $canSeeExpired) {
            $photoQuery->where('is_hidden', false);
            $galleryQuery->where('is_hidden', false);
        }

        $allowedGalleryIds = $user ? $user->getAllowedGalleryIds() : [];
        $allowedGalleryIds = $this->brandScopedGalleryIds($allowedGalleryIds, $currentBrand);
        $publicGalleryIds = $this->brandScopedGalleryIds(
            Gallery::where('is_public', true)
                ->where('type', 'delivery')
                ->where('brand', $currentBrand)
                ->where('is_hidden', false)
                ->pluck('id')
                ->toArray(),
            $currentBrand,
        );

        if (! $canSeeExpired) {
            $allowedGalleryIds = empty($allowedGalleryIds) ? [] : Gallery::whereIn('id', $allowedGalleryIds)->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->pluck('id')->toArray();
            $publicGalleryIds = empty($publicGalleryIds) ? [] : Gallery::whereIn('id', $publicGalleryIds)->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->pluck('id')->toArray();
        }

        $finalIds = array_values(array_unique(array_merge($allowedGalleryIds, $publicGalleryIds)));

        if (empty($finalIds)) {
            return response()->json(['galleries' => [], 'photos' => []]);
        }

        $photoQuery->whereIn('gallery_id', $finalIds);
        $galleryQuery->whereIn('id', $finalIds);

        $galResults = $galleryQuery->take(50)->get()
            ->filter(fn (Gallery $gallery): bool => ! $gallery->effective_is_hidden)
            ->values();
        $phoResults = $photoQuery->take(100)->get()
            ->filter(fn (Photo $photo): bool => ! $photo->effective_is_hidden)
            ->values();

        return response()->json([
            'galleries' => $galResults->map(fn ($g) => new GalleryResource($g))->values(),
            'photos' => $phoResults->map(fn ($p) => new PhotoResource($p))->values(),
        ]);
    }

    /**
     * Restrict a user's gallery grants to the active host brand and remove
     * effectively hidden galleries.  This is intentionally separate from
     * cross-brand management authorization: a public search response must
     * never contain another brand's or hidden resource row.
     *
     * @param  array<int, string>  $galleryIds
     * @return array<int, string>
     */
    private function brandScopedGalleryIds(array $galleryIds, string $brand): array
    {
        if ($galleryIds === []) {
            return [];
        }

        return Gallery::whereIn('id', $galleryIds)
            ->where('brand', $brand)
            ->get()
            ->filter(fn (Gallery $gallery): bool => ! $gallery->effective_is_hidden
                && BrandRegistry::galleryTreeMatchesCurrent($gallery))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public function locations(Request $request)
    {
        $q = $request->query('q');
        $type = $request->query('type');

        if (strlen($q) < 1 || ! in_array($type, ['city', 'country'])) {
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
                return $item->name.'-'.$item->state;
            })->values()->take(10);
        }

        return response()->json($results);
    }

    public function photoContext($id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        if (! $photo->gallery || ! BrandRegistry::galleryTreeMatchesCurrent($photo->gallery)) {
            abort(404);
        }
        if ($photo->effective_is_hidden) {
            abort(404);
        }
        $user = auth('api')->user();
        $svc = app(AuthorizationService::class);

        if (! $photo->gallery->effective_is_public) {
            if (! $user || ! $svc->canAccessGallery($user, $photo->gallery_id)) {
                abort(403);
            }
        }

        $breadcrumbs = [];
        $groupId = $photo->gallery->gallery_group_id;
        while ($groupId) {
            $group = GalleryGroup::find($groupId);
            if ($group) {
                if (! BrandRegistry::resourceMatchesCurrent($group->brand)) {
                    abort(404);
                }
                array_unshift($breadcrumbs, ['name' => $group->name, 'full_path' => 'meta/'.$group->id, 'type' => 'group']);
                $groupId = $group->parent_id;
            } else {
                break;
            }
        }
        $breadcrumbs[] = ['name' => $photo->gallery->name, 'full_path' => $photo->gallery->full_path, 'type' => 'gallery'];

        return response()->json([
            'photo' => new PhotoResource($photo),
            'breadcrumbs' => $breadcrumbs,
        ]);
    }
}
