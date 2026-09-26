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
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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

            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

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

        $superAdminRole = Role::where('name', UserRole::SUPER_ADMIN->value)->first();
        $wantsSuperAdmin = $superAdminRole && in_array($superAdminRole->id, $request->role_ids ?? []);

        if ($wantsSuperAdmin !== $svc->isSuperAdmin($user) && ! $svc->isSuperAdmin($currentUser)) {
            return response()->json(['error' => 'Nur Super Admins können die Super Admin Rolle verwalten.'], 403);
        }

        // Privilege escalation guard: a non-super-admin may only assign roles at or
        // below their own privilege level (covers self-escalation and cross-role
        // escalation, e.g. an org_admin assigning `admin`).
        if (! $svc->isSuperAdmin($currentUser) && $request->has('role_ids')) {
            $callerRank = $this->highestRoleRank($currentUser);
            $requestedRoleNames = Role::whereIn('id', $request->role_ids ?? [])->pluck('name')->all();

            foreach ($requestedRoleNames as $requestedRoleName) {
                if ($this->roleRank($requestedRoleName) > $callerRank) {
                    return response()->json(['error' => 'Es dürfen nur Rollen auf oder unterhalb der eigenen Berechtigungsstufe vergeben werden.'], 403);
                }
            }
        }

        $validated = $request->validated();
        $roleIds = $request->input('role_ids');
        $selectedRoleNames = $request->has('role_ids')
            ? Role::whereIn('id', $roleIds ?? [])->pluck('name')->all()
            : $user->roles()->pluck('name')->all();
        $isSuperAdminSelection = in_array(UserRole::SUPER_ADMIN->value, $selectedRoleNames, true);

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

        DB::transaction(function () use ($request, $user, $roleIds, $updates) {
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
     * Privilege rank for a role name. Unknown roles rank highest so they are only
     * assignable by a Super Admin (which skips the rank check entirely).
     */
    private function roleRank(string $roleName): int
    {
        return match ($roleName) {
            UserRole::SUPER_ADMIN->value => 100,
            UserRole::ADMIN->value => 80,
            UserRole::ORG_ADMIN->value => 60,
            UserRole::PHOTOGRAPHER->value => 50,
            UserRole::POWER_USER->value => 40,
            UserRole::CLIENT->value => 10,
            default => PHP_INT_MAX,
        };
    }

    /**
     * Highest privilege rank across all roles of the given user.
     */
    private function highestRoleRank(User $user): int
    {
        return $user->roles->pluck('name')
            ->map(fn ($name) => $this->roleRank($name))
            ->max() ?? 0;
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

        $user->delete();

        return response()->json(['success' => true]);
    }
}
