<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\EnforcesBrandIsolation;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\InvoiceService;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrgController extends Controller
{
    use EnforcesBrandIsolation;

    public function index()
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();

        $query = Org::withCount(['users', 'galleryGroups'])
            ->whereNotNull('brand')
            ->orderBy('name');

        if ($svc->isOrgAdmin($user)) {
            $query->where('id', $user->org_id);
        }

        $query->when($user->brand !== null, fn ($q) => $q->where('brand', $user->brand));

        if ($svc->isAdmin($user) || $svc->isPhotographer($user)) {
            return response()->json($query->get());
        } elseif ($svc->isOrgAdmin($user)) {
            $org = $query->first();

            return response()->json($org ? [$org] : []);
        }

        return response()->json(['error' => 'Forbidden'], 403);
    }

    public function show($id)
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        $org = Org::with(['users:id,name,email,org_id', 'galleryGroups:id,name,parent_id'])->findOrFail($id);

        if ($this->brandValue($org) === null) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        if (! $svc->isAdmin($user) && $user->org_id !== $id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($this->isBrandMismatch($user, $org)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $openDeliveryNotesCount = app(InvoiceService::class)
            ->countOpenDeliveryNotesForOrg($org);
        $org->setAttribute('open_delivery_notes_count', $openDeliveryNotesCount);

        if ($svc->isOrgAdmin($user)) {
            $org->setAttribute('gallery_assignment_options', $this->galleryAssignmentOptions($org, $svc));
        }

        return response()->json($org);
    }

    public function store(Request $request)
    {
        $svc = app(AuthorizationService::class);
        if (! $svc->isAdmin(auth('api')->user())) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:orgs,domain',
            'invoice_frequency' => 'required|in:immediate,monthly,quarterly',
            'default_flatrate_level' => 'nullable|in:none,web,print,original',
            'shared_flatrate_cents' => 'nullable|integer|min:0',
            'can_purchase_upgrades' => 'boolean',
            'auto_join_policy' => 'in:immediate,requires_invite,disabled',
        ]);

        $data = $request->only(['name', 'domain', 'invoice_frequency', 'default_flatrate_level', 'shared_flatrate_cents', 'can_purchase_upgrades', 'auto_join_policy']);
        $data['brand'] = BrandRegistry::currentOrDefault();
        $org = Org::create($data);

        return response()->json(['success' => true, 'org' => $org]);
    }

    public function update(Request $request, $id)
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        if (! $svc->isAdmin($user) && ! ($svc->isOrgAdmin($user) && $user->org_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $org = Org::findOrFail($id);

        if ($this->brandValue($org) === null || $this->isBrandMismatch($user, $org)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:orgs,domain,'.$id,
            'invoice_frequency' => 'required|in:immediate,monthly,quarterly',
            'default_flatrate_level' => 'nullable|in:none,web,print,original',
            'shared_flatrate_cents' => 'nullable|integer|min:0',
            'can_purchase_upgrades' => 'boolean',
            'auto_join_policy' => 'in:immediate,requires_invite,disabled',
        ]);

        $org->update($request->only(['name', 'domain', 'invoice_frequency', 'default_flatrate_level', 'shared_flatrate_cents', 'can_purchase_upgrades', 'auto_join_policy']));

        return response()->json(['success' => true, 'org' => $org]);
    }

    public function destroy($id)
    {
        $svc = app(AuthorizationService::class);
        if (! $svc->isAdmin(auth('api')->user())) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $org = Org::findOrFail($id);

        if ($this->brandValue($org) === null || $this->isBrandMismatch(auth('api')->user(), $org)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        DB::transaction(function () use ($org) {
            $userIds = $org->users()->pluck('users.id');

            $users = User::whereIn('id', $userIds)->get();
            foreach ($users as $user) {
                if ($org->default_role_id && $user->roles->contains($org->default_role_id)) {
                    $user->roles()->detach($org->default_role_id);
                }
                if ($org->default_flatrate_level && $user->flatrate_level === $org->default_flatrate_level) {
                    $user->flatrate_level = 'none';
                }
                if ($org->can_purchase_upgrades && $user->can_purchase_upgrades) {
                    $user->can_purchase_upgrades = false;
                }
                $user->org_id = null;
                $user->save();
            }

            $org->delete();
        });

        return response()->json(['success' => true]);
    }

    public function syncUsers(Request $request, $id)
    {
        $svc = app(AuthorizationService::class);
        $request->validate([
            'user_ids' => 'array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $authenticatedUserId = auth('api')->id();
        $newUserIds = array_values(array_unique(array_map(
            fn ($userId): string => (string) $userId,
            $request->input('user_ids', []),
        )));

        return DB::transaction(function () use ($svc, $authenticatedUserId, $id, $newUserIds) {
            // D-40: every sync for this org takes the destination lock first.
            // The org row is locked before any user row, so the table order is
            // orgs -> users for every sync, and between two syncs the org lock
            // cannot close a cycle with a user lock. The one exception is
            // destroy(): it updates the member rows first and takes the org
            // row's lock only at the delete, i.e. the inverse order, so a
            // destroy of the same org can still form a cycle with a sync of
            // that org (open item, see AGENTS.todo.md).
            $org = Org::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            // The current membership is read inside the org lock, so it is
            // stable against other *syncs* of this org: such a sync would have
            // to take the same org row first. It is NOT stable against every
            // writer of `users.org_id` — none of OrgInviteController::redeem(),
            // UserController::store(), AuthController::register() and
            // AuthController::resetPassword() (membership added) or
            // OrgController::destroy() (membership removed) takes this org row
            // lock. This read decides the *membership* — who is detached — not
            // the lock set: a member added after it is not detached by this
            // sync, and if it is submitted in `user_ids` as well it enters the
            // lock set through the requested ids, but a requested member is
            // never in the removed set either.
            $currentMemberIds = User::query()
                ->where('org_id', $id)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($memberId): string => (string) $memberId)
                ->all();

            // D-40: the actor, the current members and the incoming members are
            // locked in ONE ascending query. A single primary-key range scan
            // takes the rows in ascending id order, so every transaction locks
            // the same rows in the same order. The actor is part of that set
            // instead of being locked on its own: a separately locked actor
            // breaks the order and lets two syncs over different orgs — whose
            // actors are members of each other's org — wait for each other.
            $lockIds = array_values(array_unique(array_merge(
                $authenticatedUserId === null ? [] : [(string) $authenticatedUserId],
                $currentMemberIds,
                $newUserIds,
            )));
            sort($lockIds);

            $lockedUsers = User::query()
                ->with('roles')
                ->whereIn('id', $lockIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (User $member): string => (string) $member->id);

            // The authorization decision is taken on the locked actor row: the
            // roles used here come from the locked row, not from a stale read.
            $user = $authenticatedUserId === null
                ? null
                : $lockedUsers->get((string) $authenticatedUserId);

            if (! $user || (! $svc->isAdmin($user) && ! ($svc->isOrgAdmin($user) && (string) $user->org_id === (string) $id))) {
                return response()->json(['error' => 'Forbidden'], 403);
            }

            if ($this->brandValue($org) === null || $this->isBrandMismatch($user, $org)) {
                return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
            }

            // A scoped admin cannot remove the account that authorizes this
            // sync by omitting their own id from the submitted membership set.
            if ($svc->isOrgAdmin($user)) {
                $newUserIds[] = (string) $user->id;
                $newUserIds = array_values(array_unique($newUserIds));
            }

            // The membership decision is taken on the locked rows, so a member
            // whose row changed in the meantime is judged on its locked state
            // and is never detached twice. The membership itself comes from the
            // read above, so a member added after that read is not detached by
            // this sync — it is in the lock set only if it was requested.
            $oldUserIds = $lockedUsers
                ->filter(fn (User $member): bool => (string) $member->org_id === (string) $id)
                ->keys()
                ->all();
            $removedUserIds = array_values(array_diff($oldUserIds, $newUserIds));

            foreach ($newUserIds as $newUserId) {
                if (! $lockedUsers->has($newUserId)) {
                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => ['user_ids' => ['One or more selected users no longer exist.']],
                    ], 422);
                }
            }

            // Brand consistency is revalidated against the locked user rows;
            // an earlier `exists` validation cannot authorize stale membership.
            $orgBrand = $this->brandValue($org);
            foreach ($newUserIds as $newUserId) {
                $incomingUser = $lockedUsers->get($newUserId);
                if ($this->brandValue($incomingUser) === null) {
                    return response()->json(['error' => 'Benutzer ohne Brand-Zuweisung können keiner Organisation zugewiesen werden.'], 422);
                }

                if ($this->brandValue($incomingUser) !== $orgBrand) {
                    return response()->json(['error' => 'Ein oder mehrere Benutzer sind für eine andere Marke registriert und können dieser Organisation nicht zugewiesen werden.'], 422);
                }

                if ($incomingUser->roles->contains('name', UserRole::SUPER_ADMIN->value)) {
                    return response()->json(['error' => 'Super-Admins können keiner Organisation zugewiesen werden.'], 422);
                }
            }

            // SEC-20261018-01: membership cannot hand an org admin a studio role
            // capability. Existing out-of-domain members may remain in a sync
            // unchanged, but may not be newly imported or detached. Admins and
            // Super Admins retain the existing unrestricted membership path.
            if (! $svc->isAdmin($user)) {
                $actorRank = $svc->highestRoleRank($user);
                $orgRoleNames = $svc->orgDelegableRoleNames();
                $newlyAddedUserIds = array_values(array_diff($newUserIds, $oldUserIds));

                foreach ($newlyAddedUserIds as $newUserId) {
                    $incomingUser = $lockedUsers->get($newUserId);
                    $incomingRoleNames = $incomingUser->roles->pluck('name')->all();

                    if ($incomingUser->org_id !== null && (string) $incomingUser->org_id !== (string) $id) {
                        return response()->json([
                            'error' => 'Benutzer aus einer anderen Organisation können nicht übernommen werden.',
                        ], 422);
                    }

                    if (array_diff($incomingRoleNames, $orgRoleNames) !== []) {
                        return response()->json([
                            'error' => 'Es dürfen nur Benutzer mit Rollen der eigenen Organisation hinzugefügt werden.',
                        ], 422);
                    }

                    if ($svc->highestRoleRank($incomingUser) > $actorRank) {
                        return response()->json([
                            'error' => 'Benutzer mit einer höheren Berechtigungsstufe können nicht hinzugefügt werden.',
                        ], 422);
                    }
                }

                foreach ($removedUserIds as $removedUserId) {
                    $removedUser = $lockedUsers->get($removedUserId);
                    $removedRoleNames = $removedUser->roles->pluck('name')->all();

                    if (array_diff($removedRoleNames, $orgRoleNames) !== []) {
                        return response()->json([
                            'error' => 'Es dürfen nur Benutzer mit Rollen der eigenen Organisation entfernt werden.',
                        ], 422);
                    }

                    if ($svc->highestRoleRank($removedUser) > $actorRank) {
                        return response()->json([
                            'error' => 'Benutzer mit einer höheren Berechtigungsstufe können nicht entfernt werden.',
                        ], 422);
                    }
                }
            }

            foreach (array_values(array_diff($newUserIds, $oldUserIds)) as $newUserId) {
                $incomingUser = $lockedUsers->get($newUserId);
                $incomingUser->org_id = $id;
                $incomingUser->save();
            }

            foreach ($removedUserIds as $removedUserId) {
                $removedUser = $lockedUsers->get($removedUserId);

                // Revoke organization-derived role + flatrate from removed users.
                if ($org->default_role_id && $removedUser->roles->contains('id', $org->default_role_id)) {
                    $removedUser->roles()->detach($org->default_role_id);
                }
                if ($org->default_flatrate_level && $removedUser->flatrate_level === $org->default_flatrate_level) {
                    $removedUser->flatrate_level = 'none';
                }
                if ($org->can_purchase_upgrades && $removedUser->can_purchase_upgrades) {
                    $removedUser->can_purchase_upgrades = false;
                }
                $removedUser->org_id = null;
                $removedUser->save();
            }

            return response()->json(['success' => true]);
        });
    }

    public function syncGroups(Request $request, $id)
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        if (! $svc->isAdmin($user) && ! ($svc->isOrgAdmin($user) && $user->org_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'group_ids' => 'array',
            'group_ids.*' => 'exists:gallery_groups,id',
        ]);
        $org = Org::findOrFail($id);

        if ($this->brandValue($org) === null || $this->isBrandMismatch($user, $org)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        // Brand consistency: only groups sharing the org's brand may be attached.
        $orgBrand = $this->brandValue($org);
        $groupIds = $request->group_ids ?? [];
        if (! empty($groupIds)) {
            $foreignGroups = GalleryGroup::whereIn('id', $groupIds)
                ->when(
                    $orgBrand !== null,
                    fn ($query) => $query->where(function ($inner) use ($orgBrand) {
                        $inner->where('brand', '!=', $orgBrand)->orWhereNull('brand');
                    }),
                    fn ($query) => $query->whereNotNull('brand'),
                )
                ->exists();

            if ($foreignGroups) {
                return response()->json(['error' => 'Ein oder mehrere Galerie-Gruppen gehören zu einer anderen Marke und können dieser Organisation nicht zugewiesen werden.'], 422);
            }
        }

        // SEC-20261018-02: an org admin may manage the groups the org already
        // owns (attach a descendant, detach an assignment) but may not widen the
        // org's scope with a group it never had. That is an admin decision,
        // because widening the group scope widens every member's gallery access
        // through getAllowedGalleryIds(). Admins keep today's behaviour.
        if (! $svc->isAdmin($user)) {
            $scope = array_map(
                fn ($groupId): string => (string) $groupId,
                $svc->getOrgGroupScope($org->id),
            );
            $requested = array_map(fn ($groupId): string => (string) $groupId, $groupIds);

            if (array_diff($requested, $scope) !== []) {
                return response()->json([
                    'error' => 'Es dürfen nur Galerie-Gruppen zugewiesen werden, die bereits zur Organisation gehören.',
                ], 403);
            }
        }

        $org->galleryGroups()->sync($groupIds);

        return response()->json(['success' => true]);
    }

    public function generateCollectiveInvoice($id, InvoiceService $invoiceService)
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        if (! $svc->isAdmin($user) && ! ($svc->isOrgAdmin($user) && $user->org_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $org = Org::findOrFail($id);

        if ($this->brandValue($org) === null || $this->isBrandMismatch($user, $org)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        $result = $invoiceService->generateForOrg($org, $user);

        if (! $result['success']) {
            return response()->json(['error' => $result['error']], (int) ($result['status'] ?? 400));
        }

        return response()->json($result);
    }

    /**
     * Return only the safe assignment choices used by the org-admin user editor.
     * The broad management gallery tree remains unavailable to org admins.
     *
     * @return array{groups: array<int, array<string, mixed>>, root_galleries: array<int, Gallery>}
     */
    private function galleryAssignmentOptions(Org $org, AuthorizationService $authorization): array
    {
        $safeGroupIds = array_map(
            fn ($groupId): string => (string) $groupId,
            $authorization->getOrgAssignableGalleryGroupIds($org),
        );
        $safeGroupLookup = array_fill_keys($safeGroupIds, true);
        $deliveryGalleryIds = $authorization->getOrgGalleryScope($org);

        $groups = GalleryGroup::query()
            ->whereIn('id', $safeGroupIds)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'is_public']);
        $galleries = Gallery::query()
            ->whereIn('id', $deliveryGalleryIds)
            ->where('type', 'delivery')
            ->orderBy('name')
            ->get();

        $childrenByParent = $groups->groupBy(fn (GalleryGroup $group): string => (string) ($group->parent_id ?? ''));
        $galleriesByGroup = $galleries
            ->filter(fn (Gallery $gallery): bool => isset($safeGroupLookup[(string) $gallery->gallery_group_id]))
            ->groupBy(fn (Gallery $gallery): string => (string) $gallery->gallery_group_id);

        $buildGroup = function (GalleryGroup $group) use (&$buildGroup, $childrenByParent, $galleriesByGroup): array {
            return [
                'id' => (string) $group->id,
                'name' => $group->name,
                'parent_id' => $group->parent_id,
                'is_public' => $group->is_public,
                'children' => $childrenByParent
                    ->get((string) $group->id, collect())
                    ->map(fn (GalleryGroup $child): array => $buildGroup($child))
                    ->values()
                    ->all(),
                'galleries' => $galleriesByGroup
                    ->get((string) $group->id, collect())
                    ->values()
                    ->all(),
            ];
        };

        $rootGroups = $groups
            ->filter(fn (GalleryGroup $group): bool => $group->parent_id === null
                || ! isset($safeGroupLookup[(string) $group->parent_id]))
            ->map(fn (GalleryGroup $group): array => $buildGroup($group))
            ->values()
            ->all();

        $rootGalleries = $galleries
            ->reject(fn (Gallery $gallery): bool => isset($safeGroupLookup[(string) $gallery->gallery_group_id]))
            ->values()
            ->all();

        return ['groups' => $rootGroups, 'root_galleries' => $rootGalleries];
    }
}
