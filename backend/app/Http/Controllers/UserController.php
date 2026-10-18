<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\EnforcesBrandIsolation;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Mail\ActivateAccountMail;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivationTokenService;
use App\Services\AuthorizationService;
use App\Services\FtpCredentialService;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class UserController extends Controller
{
    use EnforcesBrandIsolation;

    public function index()
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        if ($user && $svc->isReservedNullBrandActor($user)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }
        $query = User::with(['roles', 'galleryGroups', 'galleries', 'photographerGalleries', 'photographerGalleryGroups']);

        if (! $svc->isAdmin($user)) {
            if (! $svc->isOrgAdmin($user)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            if ($user->org_id === null) {
                return response()->json(['data' => []]);
            }
            $query->where('org_id', $user->org_id);
        }

        // Brand isolation: a brand-bound actor only sees users of their own brand
        // (a brand-less cross-brand user stays hidden).
        if (! $this->isCrossBrand($user)) {
            $query->where('brand', $this->brandValue($user));
        }

        return UserResource::collection($query->get());
    }

    public function roles()
    {
        $svc = app(AuthorizationService::class);
        $user = auth('api')->user();
        if ($user && $svc->isReservedNullBrandActor($user)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }
        $query = Role::query();
        if (! $user || ! $svc->isSuperAdmin($user)) {
            $query->where('name', '!=', UserRole::SUPER_ADMIN->value);
        }

        // Do not advertise what the caller cannot assign. An org admin manages
        // customer accounts, so the studio-capability roles (photographer,
        // admin, super_admin) are not part of their vocabulary at all.
        if ($user && ! $svc->isAdmin($user)) {
            $query->whereIn('name', $svc->orgDelegableRoleNames());
        }

        return $query->get();
    }

    public function store(StoreUserRequest $request)
    {
        $svc = app(AuthorizationService::class);
        $currentUser = auth('api')->user();
        if ($currentUser && $svc->isReservedNullBrandActor($currentUser)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        // Org Admin: scope to their org
        $managerOrg = null;
        if ($currentUser && $svc->isOrgAdmin($currentUser)) {
            $managerOrg = Org::find($currentUser->org_id);
            if (! $managerOrg) {
                return response()->json(['error' => 'Customer Manager hat keine Organisation.'], 422);
            }
            if ($this->brandValue($managerOrg) === null) {
                return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
            }
        }

        return DB::transaction(function () use ($request, $managerOrg) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => null,
                'brand' => BrandRegistry::currentOrDefault(),
            ]);

            // If created by a Customer Manager, assign to their org
            if ($managerOrg) {
                $user->org_id = $managerOrg->id;

                // Inherit role from org's default_role_id
                $roleId = $managerOrg->default_role_id;
                if (! $roleId) {
                    $clientRole = Role::where('name', UserRole::CLIENT->value)->first();
                    $roleId = $clientRole?->id;
                }
                if ($roleId) {
                    $user->roles()->attach($roleId);
                }

                // Inherit flatrate settings from org
                if ($managerOrg->default_flatrate_level) {
                    $user->flatrate_level = $managerOrg->default_flatrate_level;
                }
                if ($managerOrg->can_purchase_upgrades) {
                    $user->can_purchase_upgrades = true;
                }
                $user->save();
            }

            // Claimed, never overwritten (D-17): see ActivationTokenService. A
            // live token from an earlier issuance stays the link this person
            // holds, so an admin who creates the same address twice cannot kill
            // a mail that is already in flight.
            $token = app(ActivationTokenService::class)->issue($user->email);

            if ($token !== null) {
                $link = BrandRegistry::frontendUrl().'/reset-password?token='.$token.'&email='.urlencode($user->email);

                Mail::to($user->email)->send(
                    new ActivateAccountMail(
                        $user->name,
                        'Es wurde ein Account für dich angelegt. Klicke hier, um ein Passwort zu vergeben:',
                        $link,
                        'Account aktivieren',
                        'Dein neuer Account'
                    )
                );
            }

            return response()->json(['success' => true, 'user' => new UserResource($user)]);
        });
    }

    public function update(UpdateUserRequest $request, $id)
    {
        $svc = app(AuthorizationService::class);
        $currentUser = auth('api')->user();
        $user = User::findOrFail($id);

        if (! $svc->isAdmin($currentUser)) {
            if (! $svc->isOrgAdmin($currentUser)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            if ($user->org_id !== $currentUser->org_id) {
                return response()->json(['error' => 'Forbidden (Org Isolation)'], 403);
            }
        }

        // Brand isolation: a brand-bound actor may only manage users of their own brand.
        if ($this->isBrandMismatch($currentUser, $user)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        // SEC-20261018-03: self-service role changes must not exist. This
        // endpoint administers *other* people's accounts; the only actor who may
        // change their own roles here is a Super Admin. An unchanged role set is
        // not a change — a profile edit that re-submits the current selection
        // keeps working.
        if (! $svc->isSuperAdmin($currentUser)
            && $currentUser->is($user)
            && $request->has('role_ids')
            && $this->roleSelectionChanged($request->input('role_ids', []), $user)
        ) {
            return response()->json(['error' => 'Die eigenen Rollen können nicht über die Benutzerverwaltung geändert werden.'], 403);
        }

        $superAdminRole = Role::where('name', UserRole::SUPER_ADMIN->value)->first();
        $wantsSuperAdmin = $superAdminRole && in_array($superAdminRole->id, $request->role_ids ?? []);

        if ($wantsSuperAdmin !== $svc->isSuperAdmin($user) && ! $svc->isSuperAdmin($currentUser)) {
            return response()->json(['error' => 'Nur Super Admins können die Super Admin Rolle verwalten.'], 403);
        }

        // Privilege guard: a non-super-admin may only assign roles at or below
        // their own privilege level. For a non-admin actor (org_admin) the org
        // domain is narrower than "everything below me": client, power_user and
        // org_admin describe *this customer account*, while photographer, admin
        // and super_admin are different capabilities, not higher rungs (see
        // AuthorizationService::orgDelegableRoleNames()). Roles outside the org
        // domain that the target already holds are carried through unchanged —
        // otherwise a metadata edit by an org admin would silently detach a
        // photographer and revoke their camera account (§7.16).
        if (! $svc->isSuperAdmin($currentUser) && $request->has('role_ids')) {
            $callerRank = $svc->highestRoleRank($currentUser);
            $requestedRoleNames = Role::whereIn('id', $request->role_ids ?? [])->pluck('name')->all();

            if (! $svc->isAdmin($currentUser)) {
                $currentRoleNames = $user->roles()->pluck('name')->all();

                foreach (array_unique(array_merge($currentRoleNames, $requestedRoleNames)) as $roleName) {
                    if (in_array($roleName, $svc->orgDelegableRoleNames(), true)) {
                        continue;
                    }

                    $heldBefore = in_array($roleName, $currentRoleNames, true);
                    $heldAfter = in_array($roleName, $requestedRoleNames, true);
                    if ($heldBefore !== $heldAfter) {
                        return response()->json([
                            'error' => 'Es dürfen nur die Rollen der eigenen Organisation (client, power_user, org_admin) vergeben werden.',
                        ], 403);
                    }
                }

                // Carried-through roles were not granted by this actor, so only
                // newly granted roles still have to pass the rank ceiling.
                $requestedRoleNames = array_values(array_diff($requestedRoleNames, $currentRoleNames));
            }

            foreach ($requestedRoleNames as $requestedRoleName) {
                if ($svc->roleRank($requestedRoleName) > $callerRank) {
                    return response()->json(['error' => 'Es dürfen nur Rollen auf oder unterhalb der eigenen Berechtigungsstufe vergeben werden.'], 403);
                }
            }
        }

        // SEC-20261018-02: an org admin delegates within the organization's
        // scope only. `getOrgGalleryScope()` is the same resolution the reader
        // uses for org integration, so this cannot grant more than an org member
        // can already reach.
        if (! $svc->isAdmin($currentUser)) {
            $actorOrgId = $currentUser->org_id;
            $orgScope = [
                'galleries' => $actorOrgId === null ? [] : $svc->getOrgGalleryScope($actorOrgId),
                'groups' => $actorOrgId === null ? [] : $svc->getOrgAssignableGalleryGroupIds($actorOrgId),
            ];

            if ($request->has('gallery_ids')) {
                $existingGalleryIds = $user->galleries()
                    ->pluck('galleries.id')
                    ->map(fn ($galleryId): string => (string) $galleryId)
                    ->all();
                $newGalleryIds = $this->newlyRequestedIds($request->input('gallery_ids', []), $existingGalleryIds);

                if ($this->outsideScope($newGalleryIds, $orgScope['galleries'])) {
                    return response()->json(['error' => 'Es dürfen nur Galerien der eigenen Organisation zugewiesen werden.'], 403);
                }
            }

            if ($request->has('gallery_group_ids')) {
                $existingGalleryGroupIds = $user->galleryGroups()
                    ->pluck('gallery_groups.id')
                    ->map(fn ($groupId): string => (string) $groupId)
                    ->all();
                $newGalleryGroupIds = $this->newlyRequestedIds($request->input('gallery_group_ids', []), $existingGalleryGroupIds);

                if ($this->outsideScope($newGalleryGroupIds, $orgScope['groups'])) {
                    return response()->json(['error' => 'Es dürfen nur Galerie-Gruppen der eigenen Organisation zugewiesen werden.'], 403);
                }
            }
        }

        $validated = $request->validated();
        $roleIds = $request->input('role_ids');
        $selectedRoleNames = $request->has('role_ids')
            ? Role::whereIn('id', $roleIds ?? [])->pluck('name')->all()
            : $user->roles()->pluck('name')->all();
        $isSuperAdminSelection = in_array(UserRole::SUPER_ADMIN->value, $selectedRoleNames, true);

        // §7.16: losing the photographer role ends the camera account. The two
        // facts are read before the transaction because the decision is the
        // *transition*, not the new state — `revoke()` then runs before the role
        // rows change.
        $hadPhotographerRole = $user->roles()->where('name', UserRole::PHOTOGRAPHER->value)->exists();
        $keepsPhotographerRole = in_array(UserRole::PHOTOGRAPHER->value, $selectedRoleNames, true);

        $updates = [];
        foreach ([
            'can_edit_metadata',
            'flatrate_level',
            'can_purchase_upgrades',
        ] as $field) {
            if ($request->has($field)) {
                $updates[$field] = $validated[$field] ?? $request->input($field);
            }
        }

        // Role-only promotion is a single state transition. Do not leave a
        // newly-created Super-Admin with its previous brand merely because the
        // request omitted the optional `brand` field.
        if ($request->has('brand') || ($request->has('role_ids') && $isSuperAdminSelection)) {
            $updates['brand'] = $isSuperAdminSelection ? null : $request->input('brand');
        }

        DB::transaction(function () use ($request, $user, $roleIds, $updates, $hadPhotographerRole, $keepsPhotographerRole) {
            // The account goes before the role does, inside the same
            // transaction: a raise from SFTPGo (unreachable) rolls the whole
            // update back, so a live credential never outlives the role that
            // justified it and the sync cannot run without the revocation.
            if ($hadPhotographerRole && ! $keepsPhotographerRole) {
                app(FtpCredentialService::class)->revoke($user);
            }

            if ($request->has('role_ids')) {
                $user->roles()->sync($roleIds ?? []);
            }
            if ($request->has('gallery_group_ids')) {
                $user->galleryGroups()->sync($request->input('gallery_group_ids', []));
            }
            if ($request->has('gallery_ids')) {
                $user->galleries()->sync($request->input('gallery_ids', []));
            }

            if ($updates !== []) {
                $user->fill($updates);
                $user->save();
            }
        });

        return response()->json(['success' => true]);
    }

    /**
     * Whether an incoming `role_ids` selection differs from the target's
     * current role set. Order and duplicates are irrelevant: `sync()` treats
     * the list as a set.
     *
     * @param  array<int, string>  $requestedRoleIds
     */
    private function roleSelectionChanged(array $requestedRoleIds, User $user): bool
    {
        $current = $user->roles()->pluck('roles.id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $requested = collect($requestedRoleIds)
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $current !== $requested;
    }

    /**
     * Whether any requested id lies outside the actor's delegation scope.
     *
     * @param  array<int, string>  $requestedIds
     * @param  array<int, string>  $scopeIds
     */
    private function outsideScope(mixed $requestedIds, array $scopeIds): bool
    {
        if (! is_array($requestedIds) || $requestedIds === []) {
            return false;
        }

        $requested = array_map(fn ($id): string => (string) $id, $requestedIds);
        $scope = array_map(fn ($id): string => (string) $id, $scopeIds);

        return array_diff($requested, $scope) !== [];
    }

    /**
     * Existing out-of-scope grants may be carried through a full edit payload,
     * but a newly submitted id must still be inside the actor's delegation
     * scope. Omitting an existing id from the submitted list removes it.
     *
     * @param  array<int, string>  $requestedIds
     * @param  array<int, string>  $existingIds
     * @return array<int, string>
     */
    private function newlyRequestedIds(array $requestedIds, array $existingIds): array
    {
        $requested = array_map(fn ($id): string => (string) $id, $requestedIds);
        $existing = array_map(fn ($id): string => (string) $id, $existingIds);

        return array_values(array_diff($requested, $existing));
    }

    public function destroy($id)
    {
        $svc = app(AuthorizationService::class);
        $currentUser = auth('api')->user();
        $user = User::findOrFail($id);

        if (! $svc->isAdmin($currentUser)) {
            if (! $svc->isOrgAdmin($currentUser)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            if ($user->org_id !== $currentUser->org_id) {
                return response()->json(['error' => 'Forbidden (Org Isolation)'], 403);
            }
        }

        // Brand isolation: a brand-bound actor may only delete users of their own brand.
        if ($this->isBrandMismatch($currentUser, $user)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        // §7.16: an account exists iff the status is `active`. Same order and
        // fail-closed rule as the role change — revoke first, delete second, in
        // one transaction, so an SFTPGo outage leaves the account in place
        // instead of deleting the row and orphaning a live credential.
        DB::transaction(function () use ($user): void {
            if ($user->ftp_account_status === FtpCredentialService::STATUS_ACTIVE) {
                app(FtpCredentialService::class)->revoke($user);
            }

            $user->delete();
        });

        return response()->json(['success' => true]);
    }
}
