<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller {
    
    public function preferences() {
        $user = auth('api')->user();
        if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);

        $user->load(['galleries' => function($q) { 
            $q->select('galleries.id', 'galleries.name', 'galleries.type')->where('type', 'delivery'); 
        }, 'galleryGroups' => function($q) { 
            $q->select('gallery_groups.id', 'gallery_groups.name'); 
        }]);

        $galleries = $user->galleries->map(function($g) {
            return [
                'id' => $g->id, 
                'name' => $g->name, 
                'type' => 'gallery', 
                'gallery_type' => $g->type,
                'wants_notifications' => (bool) $g->pivot->wants_notifications
            ];
        });

        $groups = $user->galleryGroups->map(function($g) {
            return [
                'id' => $g->id, 
                'name' => $g->name, 
                'type' => 'group', 
                'wants_notifications' => (bool) $g->pivot->wants_notifications
            ];
        });

        return response()->json([
            'galleries' => $galleries,
            'groups' => $groups
        ]);
    }

    public function toggleGalleryOptIn(Request $request, $id) {
        $request->validate(['wants_notifications' => 'required|boolean']);
        $user = auth('api')->user();
        if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);

        // IDOR guard: only users who can access the gallery may toggle notifications for it.
        if (!$user->canAccessGallery($id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        DB::table('user_galleries')->updateOrInsert(
            ['user_id' => $user->id, 'gallery_id' => $id],
            ['wants_notifications' => $request->wants_notifications]
        );
        return response()->json(['success' => true]);
    }

    public function toggleGroupOptIn(Request $request, $id) {
        $request->validate(['wants_notifications' => 'required|boolean']);
        $user = auth('api')->user();
        if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);

        // IDOR guard: only users who belong to the group may toggle notifications for it.
        if (!$user->galleryGroups()->where('gallery_groups.id', $id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        DB::table('user_gallery_groups')->updateOrInsert(
            ['user_id' => $user->id, 'gallery_group_id' => $id],
            ['wants_notifications' => $request->wants_notifications]
        );
        return response()->json(['success' => true]);
    }
}
