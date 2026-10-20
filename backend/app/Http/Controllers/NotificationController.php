<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\GalleryNotificationSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    /**
     * The name of a gallery the caller can no longer reach.
     *
     * The entry itself stays in the response — the id is needed to revoke the
     * subscription — but the name of a gallery that is out of reach is not
     * disclosed.
     */
    private const UNREACHABLE_GALLERY_NAME = 'Nicht zugängliche Galerie';

    public function preferences()
    {
        $user = auth('api')->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user->load(['galleries' => function ($q) {
            $q->select('galleries.id', 'galleries.name', 'galleries.type')->where('type', 'delivery');
        }, 'galleryGroups' => function ($q) {
            $q->select('gallery_groups.id', 'gallery_groups.name');
        }]);

        // The opt-in state lives in its own table (owner decision 2026-10-20,
        // D-45 / AD-9). The grant pivot `user_galleries` is not read here
        // anymore: a subscription is not a grant.
        $subscribedGalleryIds = $this->subscribedGalleryIds((string) $user->id);

        // The list is the delivery galleries the user may subscribe to (the
        // granted ones) plus the galleries the user is subscribed to. A
        // subscription can outlive the membership that justified it — the
        // subscription is still the user's own state and must be visible so
        // that it can be revoked.
        $galleries = $user->galleries->keyBy(fn (Gallery $gallery): string => (string) $gallery->id);
        $missing = array_values(array_diff(array_keys($subscribedGalleryIds), $galleries->keys()->all()));
        if ($missing !== []) {
            foreach (Gallery::query()->whereIn('id', $missing)->where('type', 'delivery')->get(['id', 'name', 'type']) as $gallery) {
                $galleries->put((string) $gallery->id, $gallery);
            }
        }

        // The name of a gallery is disclosed only while the caller can reach
        // the gallery. A subscription outlives the access that justified it,
        // and the gallery may even have been moved to another brand in the
        // meantime. The entry stays revocable, the name does not.
        $reachableGalleryIds = array_flip($user->getAllowedGalleryIds());

        $galleryList = $galleries->map(function (Gallery $g) use ($subscribedGalleryIds, $reachableGalleryIds) {
            return [
                'id' => $g->id,
                'name' => isset($reachableGalleryIds[(string) $g->id])
                    ? $g->name
                    : self::UNREACHABLE_GALLERY_NAME,
                'type' => 'gallery',
                'gallery_type' => $g->type,
                'wants_notifications' => isset($subscribedGalleryIds[(string) $g->id]),
            ];
        })->values();

        $groups = $user->galleryGroups->map(function ($g) {
            return [
                'id' => $g->id,
                'name' => $g->name,
                'type' => 'group',
                'wants_notifications' => (bool) $g->pivot->wants_notifications,
            ];
        });

        return response()->json([
            'galleries' => $galleryList,
            'groups' => $groups,
        ]);
    }

    public function toggleGalleryOptIn(Request $request, $id)
    {
        $request->validate(['wants_notifications' => 'required|boolean']);
        $user = auth('api')->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Guests have no persistent user row (the subscription table has a NOT
        // NULL user_id), so a guest opt-in can never be stored safely.
        // Registered users only.
        if (! $user->id) {
            return response()->json(['error' => 'Gäste können keine Benachrichtigungen abonnieren.'], 403);
        }

        $subscription = GalleryNotificationSubscription::query()
            ->where('user_id', $user->id)
            ->where('gallery_id', $id)
            ->first();

        // IDOR guard: a subscription may only be created for a gallery the user
        // may access. Revoking a subscription the caller already holds is
        // allowed even when the gallery has become inaccessible in the
        // meantime (the membership that justified the subscription was
        // revoked) — it touches the caller's own row and grants nothing.
        if (! $subscription && ! $user->canAccessGallery($id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($request->boolean('wants_notifications')) {
            // The subscription lives in its own table. It must never be written
            // into `user_galleries`: that pivot carries the client grant, and
            // a subscription would turn into one (owner decision 2026-10-20,
            // D-45 / AD-9).
            //
            // The write is an atomic upsert: two requests of the same user
            // that race each other cannot produce a unique constraint
            // violation (an HTTP 500).
            GalleryNotificationSubscription::subscribe((string) $user->id, (string) $id);
        } else {
            GalleryNotificationSubscription::unsubscribe((string) $user->id, (string) $id);
        }

        return response()->json(['success' => true]);
    }

    public function toggleGroupOptIn(Request $request, $id)
    {
        $request->validate(['wants_notifications' => 'required|boolean']);
        $user = auth('api')->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Guests have no persistent user row (user_gallery_groups.user_id is NOT NULL),
        // so a guest opt-in can never be stored safely. Registered users only.
        if (! $user->id) {
            return response()->json(['error' => 'Gäste können keine Benachrichtigungen abonnieren.'], 403);
        }

        // IDOR guard: only users who belong to the group may toggle notifications for it.
        if (! $user->galleryGroups()->where('gallery_groups.id', $id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        DB::table('user_gallery_groups')->updateOrInsert(
            ['user_id' => $user->id, 'gallery_group_id' => $id],
            ['wants_notifications' => $request->wants_notifications]
        );

        return response()->json(['success' => true]);
    }

    /**
     * The galleries the user is subscribed to, as a lookup map.
     *
     * The row in `gallery_notification_subscriptions` *is* the subscription:
     * there is no flag column, so the presence of the row is the state.
     *
     * @return array<string, true>
     */
    private function subscribedGalleryIds(string $userId): array
    {
        return GalleryNotificationSubscription::query()
            ->where('user_id', $userId)
            ->pluck('gallery_id')
            ->mapWithKeys(fn (mixed $galleryId): array => [(string) $galleryId => true])
            ->all();
    }
}
