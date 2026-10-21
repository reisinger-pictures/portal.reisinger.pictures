<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\GalleryNotificationSubscription;
use App\Models\GroupNotificationSubscription;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

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

    /**
     * The name of a gallery group the caller can no longer reach.
     *
     * The same rule as for a gallery: the entry stays revocable, the name does
     * not.
     */
    private const UNREACHABLE_GROUP_NAME = 'Nicht zugänglicher Ordner';

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
        $allowedGalleryIds = $user->getAllowedGalleryIds();
        $reachableGalleryIds = array_flip($allowedGalleryIds);

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

        // The group opt-in state lives in its own table as well (owner decision
        // 2026-10-21, D-46 / AD-10). The grant pivot `user_gallery_groups` is
        // not read here anymore: a subscription is not a grant.
        $subscribedGroupIds = $this->subscribedGroupIds((string) $user->id);

        // The list is the groups the user is assigned to (the granted ones)
        // plus the groups the user is subscribed to. A subscription can outlive
        // the membership that justified it — it is still the user's own state
        // and must stay visible so that it can be revoked.
        $groups = $user->galleryGroups->keyBy(fn (GalleryGroup $group): string => (string) $group->id);
        $missingGroups = array_values(array_diff(array_keys($subscribedGroupIds), $groups->keys()->all()));
        if ($missingGroups !== []) {
            foreach (GalleryGroup::query()->whereIn('id', $missingGroups)->get(['id', 'name']) as $group) {
                $groups->put((string) $group->id, $group);
            }
        }

        // The reachable groups: the assigned ones (with their subtree) and the
        // groups that hold a gallery the caller may access.
        //
        // The assigned groups are read from the *assignment* relation, not
        // from the list above: the list also holds the groups the caller is
        // only subscribed to, and a subscription is not an assignment. The
        // values are the groups' own ids — `keys()` on the relation's
        // collection yields the positional indices of the collection (0, 1, 2
        // …) instead of the ids, and the assignment would never reach the
        // reachability computation below.
        $assignedGroupIds = $user->galleryGroups
            ->map(fn (GalleryGroup $group): string => (string) $group->id)
            ->all();

        $reachableGroupIds = $this->reachableGroupIds(
            $assignedGroupIds,
            $allowedGalleryIds,
        );

        $groupList = $groups->map(function (GalleryGroup $g) use ($subscribedGroupIds, $reachableGroupIds) {
            return [
                'id' => $g->id,
                'name' => isset($reachableGroupIds[(string) $g->id])
                    ? $g->name
                    : self::UNREACHABLE_GROUP_NAME,
                'type' => 'group',
                'wants_notifications' => isset($subscribedGroupIds[(string) $g->id]),
            ];
        })->values();

        return response()->json([
            'galleries' => $galleryList,
            'groups' => $groupList,
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

        // Guests have no persistent user row (the subscription table has a NOT
        // NULL user_id), so a guest opt-in can never be stored safely.
        // Registered users only.
        if (! $user->id) {
            return response()->json(['error' => 'Gäste können keine Benachrichtigungen abonnieren.'], 403);
        }

        $subscription = GroupNotificationSubscription::query()
            ->where('user_id', $user->id)
            ->where('gallery_group_id', $id)
            ->first();

        // IDOR guard: only users who belong to the group may subscribe to it.
        // Revoking a subscription the caller already holds is allowed even when
        // the membership that justified it is gone (the subscription outlives
        // the access that justified it) — it touches the caller's own row and
        // grants nothing.
        if (! $subscription && ! $user->galleryGroups()->where('gallery_groups.id', $id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($request->boolean('wants_notifications')) {
            // The subscription lives in its own table. It must never be written
            // into `user_gallery_groups`: that pivot carries the client group
            // grant, and a group grant covers the whole subtree of the group —
            // a subscription would turn into one (owner decision 2026-10-21,
            // D-46 / AD-10).
            //
            // The write is an atomic upsert: two requests of the same user
            // that race each other cannot produce a unique constraint
            // violation (an HTTP 500).
            GroupNotificationSubscription::subscribe((string) $user->id, (string) $id);
        } else {
            GroupNotificationSubscription::unsubscribe((string) $user->id, (string) $id);
        }

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

    /**
     * The groups the user is subscribed to, as a lookup map.
     *
     * The row in `group_notification_subscriptions` *is* the subscription:
     * there is no flag column, so the presence of the row is the state.
     *
     * @return array<string, true>
     */
    private function subscribedGroupIds(string $userId): array
    {
        return GroupNotificationSubscription::query()
            ->where('user_id', $userId)
            ->pluck('gallery_group_id')
            ->mapWithKeys(fn (mixed $groupId): array => [(string) $groupId => true])
            ->all();
    }

    /**
     * The groups the caller can still reach.
     *
     * A group is reachable when the caller is assigned to it — the grant pivot
     * carries the assignment, and a group grant covers the whole subtree — or
     * when the caller may access a gallery that the group holds.
     *
     * A group that is only in the list because of a subscription is not
     * reachable that way: the membership that justified the subscription is
     * gone, and with it the group's content. Such a group keeps its id (it is
     * needed to revoke the subscription) but not its name.
     *
     * The first disjunct is the assignment itself, not the content the
     * assignment would hand out: a group whose subtree is voided (D-39) or
     * empty grants no gallery, but the caller is assigned to it and may name
     * it. That is the rule of
     * features/infrastructure/02-email-system.md §2.2.
     *
     * @param  array<int, string>  $assignedGroupIds  the ids of the groups the caller is assigned to
     * @param  array<string>  $allowedGalleryIds  the galleries the caller may access
     * @return array<string, true>
     */
    private function reachableGroupIds(array $assignedGroupIds, array $allowedGalleryIds): array
    {
        $reachable = [];

        // The assigned groups and their subtree: the assignment carries the
        // whole subtree, so the caller reaches every group below it.
        foreach (app(AuthorizationService::class)->getSubGroupIds($assignedGroupIds) as $groupId) {
            $reachable[(string) $groupId] = true;
        }

        // The groups that hold a gallery the caller may access.
        if ($allowedGalleryIds !== []) {
            $galleryGroupIds = Gallery::query()
                ->whereIn('id', $allowedGalleryIds)
                ->pluck('gallery_group_id')
                ->filter(fn (mixed $groupId): bool => $groupId !== null && $groupId !== '')
                ->map(fn (mixed $groupId): string => (string) $groupId)
                ->all();

            foreach ($galleryGroupIds as $groupId) {
                $reachable[$groupId] = true;
            }
        }

        return $reachable;
    }
}
