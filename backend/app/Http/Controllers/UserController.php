<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Enums\UserRole;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
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
            if (!$user->is_customer_manager) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            $tenantIds = $user->tenants()->pluck('tenants.id')->toArray();
            if (empty($tenantIds)) {
                return response()->json(['data' => []]);
            }
            $query->whereHas('tenants', function($q) use ($tenantIds) {
                $q->whereIn('tenants.id', $tenantIds);
            });
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

        $validated = $request->validated();

        if (!$currentUser->is_admin) {
            if (!$currentUser->is_customer_manager) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            return response()->json(['error' => 'Not implemented for Customer Managers yet.'], 403);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => null
            ]);

            $token = Str::random(64);
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $frontendUrl = rtrim(config('app.frontend_url'), '/');
            $link = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);

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
            if (!$currentUser->is_customer_manager) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            $managerTenantIds = $currentUser->tenants()->pluck('tenants.id')->toArray();
            $targetUserTenantIds = $user->tenants()->pluck('tenants.id')->toArray();

            if (empty(array_intersect($managerTenantIds, $targetUserTenantIds))) {
                return response()->json(['error' => 'Forbidden (Tenant Isolation)'], 403);
            }
        }

        $superAdminRole = Role::where('name', UserRole::SUPER_ADMIN->value)->first();
        $wantsSuperAdmin = $superAdminRole && in_array($superAdminRole->id, $request->role_ids ?? []);

        if ($wantsSuperAdmin !== $user->is_super_admin && !$currentUser->is_super_admin) {
            return response()->json(['error' => 'Nur Super Admins können die Super Admin Rolle verwalten.'], 403);
        }

        $validated = $request->validated();

        $user->roles()->sync($request->role_ids ?? []);
        $user->galleryGroups()->sync($request->gallery_group_ids ?? []);
        $user->galleries()->sync($request->gallery_ids ?? []);

        if ($request->has('can_edit_metadata')) {
            $user->update(['can_edit_metadata' => $request->can_edit_metadata]);
        }
        if ($request->has('flatrate_level')) {
            $user->update(['flatrate_level' => $request->flatrate_level]);
        }

        return response()->json(['success' => true]);
    }

    public function destroy($id)
    {
        $currentUser = auth('api')->user();
        if (!$currentUser->is_admin) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['success' => true]);
    }
}
