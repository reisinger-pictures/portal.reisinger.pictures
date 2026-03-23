<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GalleryGroup;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class GalleryController extends Controller
{
    public function indexAdmin(Request $request)
    {
        $user = auth('api')->user();
        
        $tree = Cache::rememberForever('gallery_tree_admin', function () {
            $groups = GalleryGroup::whereNull('parent_id')->with(['children', 'galleries'])->get();
            $rootGalleries = Gallery::whereNull('gallery_group_id')->get();
            return [
                'groups' => $groups->toArray(), 
                'root_galleries' => $rootGalleries->toArray()
            ];
        });

        $filterType = $request->query('filter_type');
        $treeArray = json_decode(json_encode($tree), true);

        if (!$user->is_admin && $user->is_photographer) {
            $allowedGalleryIds = $user->getAllowedGalleryIds();
            
            $filterNode = function($groups) use (&$filterNode, $allowedGalleryIds) {
                $result = [];
                foreach ($groups as $group) {
                    if (isset($group['galleries'])) {
                        $group['galleries'] = array_values(array_filter($group['galleries'], fn($g) => in_array($g['id'], $allowedGalleryIds)));
                    }
                    if (isset($group['children'])) {
                        $group['children'] = $filterNode($group['children']);
                    }
                    $result[] = $group;
                }
                return $result;
            };

            $treeArray['groups'] = $filterNode($treeArray['groups']);
            $treeArray['root_galleries'] = array_values(array_filter($treeArray['root_galleries'], fn($g) => in_array($g['id'], $allowedGalleryIds)));
        }

        if ($filterType) {
            $filterNode = function($groups) use (&$filterNode, $filterType) {
                $result = [];
                foreach ($groups as $group) {
                    if (isset($group['galleries'])) {
                        $group['galleries'] = array_values(array_filter($group['galleries'], function($g) use ($filterType) {
                            return $g['type'] === $filterType;
                        }));
                    }
                    if (isset($group['children'])) {
                        $group['children'] = $filterNode($group['children']);
                    }
                    $result[] = $group;
                }
                return $result;
            };

            $treeArray['groups'] = $filterNode($treeArray['groups']);
            $treeArray['root_galleries'] = array_values(array_filter($treeArray['root_galleries'], function($g) use ($filterType) {
                return $g['type'] === $filterType;
            }));
        }

        return response()->json($treeArray);
    }

    public function storeGroup(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer|exists:gallery_groups,id',
            'is_public' => 'nullable|boolean'
        ]);
        
        $slug = $request->slug ? Str::slug($request->slug) : Str::slug($request->name);
        if (GalleryGroup::where('slug', $slug)->exists()) {
            $slug = $slug . '-' . time();
        }
        
        $group = GalleryGroup::create([
            'name' => $request->name, 
            'slug' => $slug, 
            'parent_id' => $request->parent_id,
            'is_public' => $request->is_public
        ]);
        return response()->json(['success' => true, 'group' => $group]);
    }

    public function storeGallery(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->is_photographer) {
            return response()->json(['error' => 'Nur Fotografen dürfen Galerien erstellen.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'type' => 'required|in:selection,delivery',
            'gallery_group_id' => 'nullable|integer|exists:gallery_groups,id',
            'is_public' => 'boolean',
            'is_live' => 'boolean',
            'password' => 'nullable|string',
            'expires_at' => 'nullable|date',
        ]);

        $slug = $request->slug ? Str::slug($request->slug) : Str::slug($request->name);
        if (Gallery::where('slug', $slug)->exists()) $slug = $slug . '-' . time();

        $isPublic = $request->is_public ?? false;
        
        // Sichtbarkeit vom Parent erzwingen, falls dort gesetzt
        if ($request->gallery_group_id) {
            $group = GalleryGroup::find($request->gallery_group_id);
            if ($group && !is_null($group->is_public)) {
                $isPublic = $group->is_public;
            }
        }

        // SECURITY FORCEMENT: Selection Galerien MÜSSEN privat sein.
        if ($request->type === 'selection') {
            $isPublic = false;
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $slug, $isPublic, $user) {
            $gallery = Gallery::create([
                'name' => $request->name,
                'slug' => $slug,
                'type' => $request->type,
                'is_live' => $request->type === 'selection' ? false : ($request->is_live ?? false),
                'is_public' => $isPublic,
                'gallery_group_id' => $request->gallery_group_id,
                'password_hash' => $request->password ? Hash::make($request->password) : null,
                'expires_at' => $request->expires_at ? Carbon::parse($request->expires_at)->endOfDay() : null,
            ]);

            if ($user && !$user->is_admin && $user->is_photographer) {
                $user->galleries()->syncWithoutDetaching([$gallery->id]);
            }

            return response()->json(['success' => true, 'gallery' => $gallery]);
        });
    }

    public function updateGallery(Request $request, $id)
    {
        $gallery = Gallery::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'type' => 'nullable|in:selection,delivery',
            'is_live' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
            'gallery_group_id' => 'nullable|integer|exists:gallery_groups,id',
        ]);

        if (isset($validated['type']) && $validated['type'] === 'selection') {
            $validated['is_live'] = false;
            $validated['is_public'] = false;
        } elseif ($gallery->type === 'selection' && !isset($validated['type'])) {
            $validated['is_public'] = false;
        }

        // Vererbung prüfen, falls sich die Gruppe ändert oder das Update is_public überschreiben will
        $groupIdToCheck = array_key_exists('gallery_group_id', $validated) ? $validated['gallery_group_id'] : $gallery->gallery_group_id;
        if ($groupIdToCheck) {
            $group = GalleryGroup::find($groupIdToCheck);
            if ($group && !is_null($group->is_public)) {
                $validated['is_public'] = $group->is_public;
            }
        }

        $gallery->update($validated);
        return response()->json(['success' => true, 'gallery' => $gallery]);
    }

    public function destroyGallery($id)
    {
        $gallery = Gallery::findOrFail($id);
        \Illuminate\Support\Facades\Storage::disk('photos')->deleteDirectory((string) $gallery->id);
        
        $gallery->delete();
        return response()->json(['success' => true]);
    }


    public function ratingStatus($id)
    {
        $gallery = Gallery::findOrFail($id);
        $totalPhotos = $gallery->photos()->count();

        // Finde alle User, die mit dieser Galerie verknüpft sind (inklusive Magic Link Gäste)
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

        return response()->json([
            'users' => $status,
            'total_photos' => $totalPhotos
        ]);
    }

    public function exportRatings($id)
    {
        Gallery::findOrFail($id);
        $photos = Photo::where('gallery_id', $id)->get();
        $export = [];

        foreach ($photos as $photo) {
            $ratings = DB::table('ratings')
                ->join('users', 'ratings.user_id', '=', 'users.id')
                ->where('photo_id', $photo->id)
                ->select('ratings.rating', 'ratings.comment', 'users.name')
                ->get();

            if ($ratings->isEmpty()) continue;
            $validRatings = $ratings->where('rating', '>', 0);
            $avg = $validRatings->avg('rating');

            $comments = [];
            foreach ($ratings as $r) {
                $ratingStr = $r->rating > 0 ? $r->rating . ' Sterne' : 'Ignoriert';
                $line = "{$r->name} ({$ratingStr})";
                if (!empty($r->comment)) $line .= ": {$r->comment}";
                $comments[] = $line;
            }

            $export[] = [
                'id' => $photo->id,
                'filename' => $photo->filename,
                'thumb_url' => '/api/media/' . $photo->gallery->slug . '/_thumbs/' . md5($photo->filename . '1024') . '.webp',
                'lr_uuid' => $photo->lr_uuid,
                'avg_rating' => $avg ? ceil($avg) : 0,
                'all_comments' => implode("\n", $comments)
            ];
        }

        return response()->json($export);
    }


    public function showGroup($id)
    {
        $group = GalleryGroup::with('children')->findOrFail($id);
        $user = auth('api')->user();

        $groupIds = $this->getAllSubgroupIDs($group);
        $groupIds[] = $group->id;

        $galleryIds = Gallery::whereIn('gallery_group_id', $groupIds)->pluck('id')->toArray();

        if (!$user->is_admin) {
            $allowedGalleryIds = $user->getAllowedGalleryIds();
            $galleryIds = array_intersect($galleryIds, $allowedGalleryIds);
        }

        $photos = Photo::whereIn('gallery_id', $galleryIds)->orderBy('id', 'desc')->paginate(50);

        $photos->getCollection()->transform(function ($photo) {
            $photo->load('gallery');
            $baseUrl = '/api/media/' . $photo->gallery->slug;
            $photo->url = $baseUrl . '/' . $photo->filename;
            $photo->thumb_url = $baseUrl . '/_thumbs/' . md5($photo->filename . '1024') . '.webp';
            return $photo;
        });

        return response()->json([
            'group' => $group,
            'downloads_count' => \App\Models\DownloadLog::whereIn('gallery_id', $galleryIds)->count(),
            'photos' => $photos->items(),
            'current_page' => $photos->currentPage(),
            'last_page' => $photos->lastPage(),
            'total' => $photos->total()
        ]);
    }

    private function getAllSubgroupIDs($group)
    {
        $ids = [];
        foreach ($group->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $this->getAllSubgroupIDs($child));
        }
        return $ids;
    }
}