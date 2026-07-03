<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantController extends Controller
{
    public function index()
    {
        $user = auth('api')->user();

        if ($user->is_admin) {
            return response()->json(Tenant::withCount(['users', 'galleryGroups'])->orderBy('name')->get());
        } elseif ($user->is_org_admin) {
            $tenant = Tenant::withCount(['users', 'galleryGroups'])->find($user->tenant_id);
            return response()->json($tenant ? [$tenant] : []);
        } elseif ($user->is_photographer) {
            return response()->json(Tenant::withCount(['users', 'galleryGroups'])->orderBy('name')->get());
        }

        return response()->json(['error' => 'Forbidden'], 403);
    }

    public function show($id)
    {
        $user = auth('api')->user();
        $tenant = Tenant::with(['users:id,name,email,tenant_id', 'galleryGroups:id,name,parent_id'])->findOrFail($id);

        if (!$user->is_admin && $user->tenant_id !== $id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $openDeliveryNotesCount = \App\Models\Order::whereIn('user_id', $tenant->users()->pluck('id'))
            ->where('status', 'delivery_note')
            ->count();
        $tenant->setAttribute('open_delivery_notes_count', $openDeliveryNotesCount);

        return response()->json($tenant);
    }

    public function store(Request $request)
    {
        if (!auth('api')->user()->is_admin) return response()->json(['error' => 'Forbidden'], 403);

        $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:tenants,domain',
            'invoice_frequency' => 'required|in:immediate,monthly,quarterly',
            'default_flatrate_level' => 'nullable|in:none,web,print,original',
            'shared_flatrate_cents' => 'nullable|integer|min:0',
            'can_purchase_upgrades' => 'boolean',
            'auto_join_policy' => 'in:immediate,requires_invite,disabled',
        ]);

        $data = $request->only(['name', 'domain', 'invoice_frequency', 'default_flatrate_level', 'shared_flatrate_cents', 'can_purchase_upgrades', 'auto_join_policy']);
        $data['brand'] = BrandRegistry::currentOrDefault();
        $tenant = Tenant::create($data);
        return response()->json(['success' => true, 'tenant' => $tenant]);
    }

    public function update(Request $request, $id)
    {
        $user = auth('api')->user();
        if (!$user->is_admin && !($user->is_org_admin && $user->tenant_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $tenant = Tenant::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:tenants,domain,' . $id,
            'invoice_frequency' => 'required|in:immediate,monthly,quarterly',
            'default_flatrate_level' => 'nullable|in:none,web,print,original',
            'shared_flatrate_cents' => 'nullable|integer|min:0',
            'can_purchase_upgrades' => 'boolean',
            'auto_join_policy' => 'in:immediate,requires_invite,disabled',
        ]);

        $tenant->update($request->only(['name', 'domain', 'invoice_frequency', 'default_flatrate_level', 'shared_flatrate_cents', 'can_purchase_upgrades', 'auto_join_policy']));
        return response()->json(['success' => true, 'tenant' => $tenant]);
    }

    public function destroy($id)
    {
        if (!auth('api')->user()->is_admin) return response()->json(['error' => 'Forbidden'], 403);

        $tenant = Tenant::with('users')->findOrFail($id);

        // Revoke org-derived role + flatrate from all users before deleting
        foreach ($tenant->users as $user) {
            if ($tenant->default_role_id && $user->roles->contains($tenant->default_role_id)) {
                $user->roles()->detach($tenant->default_role_id);
            }
            if ($tenant->default_flatrate_level && $user->flatrate_level === $tenant->default_flatrate_level) {
                $user->flatrate_level = 'none';
            }
            if ($tenant->can_purchase_upgrades && $user->can_purchase_upgrades) {
                $user->can_purchase_upgrades = false;
            }
            $user->save();
        }

        $tenant->delete();
        return response()->json(['success' => true]);
    }

    public function syncUsers(Request $request, $id)
    {
        $user = auth('api')->user();
        if (!$user->is_admin && !($user->is_org_admin && $user->tenant_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'user_ids' => 'array',
            'user_ids.*' => 'exists:users,id'
        ]);

        $superAdmins = \App\Models\User::whereIn('id', $request->user_ids ?? [])
            ->whereHas('roles', function($q) { $q->where('name', \App\Enums\UserRole::SUPER_ADMIN->value); })
            ->exists();

        if ($superAdmins) {
            return response()->json(['error' => 'Super-Admins können keiner Organisation zugewiesen werden.'], 422);
        }

        $tenant = Tenant::findOrFail($id);

        // Get currently assigned user IDs before syncing
        $oldUserIds = $tenant->users()->pluck('id')->toArray();
        $newUserIds = $request->user_ids ?? [];
        $removedUserIds = array_diff($oldUserIds, $newUserIds);

        // Set tenant_id on newly assigned users
        \App\Models\User::whereIn('id', $newUserIds)->update(['tenant_id' => $id]);

        // Revoke organization-derived role + flatrate from removed users
        if (!empty($removedUserIds)) {
            $removedUsers = \App\Models\User::whereIn('id', $removedUserIds)->get();
            foreach ($removedUsers as $removedUser) {
                // Only revoke if the user's role matches the org's default role
                if ($tenant->default_role_id && $removedUser->roles->contains($tenant->default_role_id)) {
                    $removedUser->roles()->detach($tenant->default_role_id);
                }
                // Reset flatrate to 'none' if it matches the org's default
                if ($tenant->default_flatrate_level && $removedUser->flatrate_level === $tenant->default_flatrate_level) {
                    $removedUser->flatrate_level = 'none';
                }
                // Reset can_purchase_upgrades if it was inherited from org
                if ($tenant->can_purchase_upgrades && $removedUser->can_purchase_upgrades) {
                    $removedUser->can_purchase_upgrades = false;
                }
                $removedUser->tenant_id = null;
                $removedUser->save();
            }
        }

        return response()->json(['success' => true]);
    }

    public function syncGroups(Request $request, $id)
    {
        $user = auth('api')->user();
        if (!$user->is_admin && !($user->is_org_admin && $user->tenant_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        
        $request->validate(['group_ids' => 'array']);
        $tenant = Tenant::findOrFail($id);
        $tenant->galleryGroups()->sync($request->group_ids ?? []);
        
        return response()->json(['success' => true]);
    }


    public function generateCollectiveInvoice($id, \App\Services\InvoiceService $invoiceService)
    {
        $user = auth('api')->user();
        if (!$user->is_admin && !($user->is_org_admin && $user->tenant_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $tenant = Tenant::findOrFail($id);
        $result = $invoiceService->generateForTenant($tenant, $user);

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 400);
        }

        return response()->json($result);
    }
}
