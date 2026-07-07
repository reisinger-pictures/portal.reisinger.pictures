<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Enums\UserRole;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index()
    {
        $user = auth('api')->user();
        $query = User::with(['roles', 'galleryGroups', 'galleries', 'photographerGalleries', 'photographerGalleryGroups']);

        if (!$user->is_admin) {
            if (!$user->is_org_admin) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            if ($user->org_id === null) {
                return response()->json(['data' => []]);
            }
            $query->where('org_id', $user->org_id);
        }

        return \App\Http\Resources\UserResource::collection($query->get());
    }

    public function roles()
    {
        $user = auth('api')->user();
        $query = Role::query();
        if (!$user || !$user->is_super_admin) {
            $query->where('name', '!=', UserRole::SUPER_ADMIN->value);
        }
        return $query->get();
    }

    public function store(StoreUserRequest $request)
    {
        $currentUser = auth('api')->user();

        // Org Admin: scope to their org
        $managerOrg = null;
        if ($currentUser && $currentUser->is_org_admin) {
            $managerOrg = \App\Models\Org::find($currentUser->org_id);
            if (!$managerOrg) {
                return response()->json(['error' => 'Customer Manager hat keine Organisation.'], 422);
            }
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $managerOrg) {
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
                if (!$roleId) {
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
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $link = BrandRegistry::frontendUrl() . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);

            \Illuminate\Support\Facades\Mail::to($user->email)->send(
                new \App\Mail\ActivateAccountMail(
                    $user->name,
                    'Es wurde ein Account für dich angelegt. Klicke hier, um ein Passwort zu vergeben:',
                    $link,
                    'Account aktivieren',
                    'Dein neuer Account'
                )
            );

            return response()->json(['success' => true, 'user' => new \App\Http\Resources\UserResource($user)]);
        });
    }

    public function update(UpdateUserRequest $request, $id)
    {
        $currentUser = auth('api')->user();
        $user = User::findOrFail($id);

        if (!$currentUser->is_admin) {
            if (!$currentUser->is_org_admin) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            if ($user->org_id !== $currentUser->org_id) {
                return response()->json(['error' => 'Forbidden (Org Isolation)'], 403);
            }
        }

        $superAdminRole = Role::where('name', UserRole::SUPER_ADMIN->value)->first();
        $wantsSuperAdmin = $superAdminRole && in_array($superAdminRole->id, $request->role_ids ?? []);

        if ($wantsSuperAdmin !== $user->is_super_admin && !$currentUser->is_super_admin) {
            return response()->json(['error' => 'Nur Super Admins können die Super Admin Rolle verwalten.'], 403);
        }

        $validated = $request->validated();

        if ($request->has('role_ids')) {
            $user->roles()->sync($request->role_ids);
        }
        if ($request->has('gallery_group_ids')) {
            $user->galleryGroups()->sync($request->gallery_group_ids);
        }
        if ($request->has('gallery_ids')) {
            $user->galleries()->sync($request->gallery_ids);
        }

        if ($request->has('can_edit_metadata')) {
            $user->update(['can_edit_metadata' => $request->can_edit_metadata]);
        }
        if ($request->has('flatrate_level')) {
            $user->update(['flatrate_level' => $request->flatrate_level]);
        }
        if ($request->has('can_purchase_upgrades')) {
            $user->update(['can_purchase_upgrades' => $request->can_purchase_upgrades]);
        }
        if ($request->has('brand')) {
            // U-02: Staff is brand-bound (reversal of Policy A). Only Super-Admin keeps
            // brand=null (cross-brand). All other roles (admin, photographer, etc.) are
            // brand-bound to 'rp' or 'srp'.
            // If role_ids is provided, use the request roles; otherwise check the user's
            // current roles (e.g. when only the brand field is being updated).
            $selectedRoleNames = $request->has('role_ids')
                ? Role::whereIn('id', $request->role_ids ?? [])->pluck('name')->all()
                : $user->roles()->pluck('name')->all();
            $isSuperAdmin = in_array(\App\Enums\UserRole::SUPER_ADMIN->value, $selectedRoleNames, true);

            $user->update(['brand' => $isSuperAdmin ? null : $request->brand]);
        }

        return response()->json(['success' => true]);
    }

    public function destroy($id)
    {
        $currentUser = auth('api')->user();
        $user = User::findOrFail($id);

        if (!$currentUser->is_admin) {
            if (!$currentUser->is_org_admin) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            if ($user->org_id !== $currentUser->org_id) {
                return response()->json(['error' => 'Forbidden (Org Isolation)'], 403);
            }
        }

        $user->delete();

        return response()->json(['success' => true]);
    }
}
