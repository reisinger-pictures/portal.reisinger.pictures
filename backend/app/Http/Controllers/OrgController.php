<?php

namespace App\Http\Controllers;

use App\Models\Org;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrgController extends Controller
{
    public function index()
    {
        $user = auth('api')->user();

        $query = Org::withCount(['users', 'galleryGroups'])->orderBy('name');

        if ($user->is_org_admin) {
            $query->where('id', $user->org_id);
        }

        $query->when($user->brand !== null, fn($q) => $q->where('brand', $user->brand));

        if ($user->is_admin || $user->is_photographer) {
            return response()->json($query->get());
        } elseif ($user->is_org_admin) {
            $org = $query->first();
            return response()->json($org ? [$org] : []);
        }

        return response()->json(['error' => 'Forbidden'], 403);
    }

    public function show($id)
    {
        $user = auth('api')->user();
        $org = Org::with(['users:id,name,email,org_id', 'galleryGroups:id,name,parent_id'])->findOrFail($id);

        if (!$user->is_admin && $user->org_id !== $id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($user->brand !== null && $org->brand !== null && $user->brand !== $org->brand) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $openDeliveryNotesCount = \App\Models\Order::whereIn('user_id', $org->users()->pluck('id'))
            ->where('status', 'delivery_note')
            ->count();
        $org->setAttribute('open_delivery_notes_count', $openDeliveryNotesCount);

        return response()->json($org);
    }

    public function store(Request $request)
    {
        if (!auth('api')->user()->is_admin) return response()->json(['error' => 'Forbidden'], 403);

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
        $user = auth('api')->user();
        if (!$user->is_admin && !($user->is_org_admin && $user->org_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $org = Org::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:orgs,domain,' . $id,
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
        if (!auth('api')->user()->is_admin) return response()->json(['error' => 'Forbidden'], 403);

        $org = Org::findOrFail($id);

        DB::transaction(function () use ($org) {
            $userIds = $org->users()->pluck('users.id');

            $users = \App\Models\User::whereIn('id', $userIds)->get();
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
        $user = auth('api')->user();
        if (!$user->is_admin && !($user->is_org_admin && $user->org_id === $id)) {
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

        $org = Org::findOrFail($id);

        if ($org->brand !== null) {
            $conflictingUsers = \App\Models\User::whereIn('id', $request->user_ids ?? [])
                ->whereNotNull('brand')
                ->where('brand', '!=', $org->brand)
                ->exists();
            if ($conflictingUsers) {
                return response()->json(['error' => 'Ein oder mehrere Benutzer sind für eine andere Marke registriert und können dieser Organisation nicht zugewiesen werden.'], 422);
            }
        }

        // Get currently assigned user IDs before syncing
        $oldUserIds = $org->users()->pluck('id')->toArray();
        $newUserIds = $request->user_ids ?? [];
        $removedUserIds = array_diff($oldUserIds, $newUserIds);

        // Set org_id on newly assigned users
        \App\Models\User::whereIn('id', $newUserIds)->update(['org_id' => $id]);

        // Revoke organization-derived role + flatrate from removed users
        if (!empty($removedUserIds)) {
            $removedUsers = \App\Models\User::whereIn('id', $removedUserIds)->get();
            foreach ($removedUsers as $removedUser) {
                // Only revoke if the user's role matches the org's default role
                if ($org->default_role_id && $removedUser->roles->contains($org->default_role_id)) {
                    $removedUser->roles()->detach($org->default_role_id);
                }
                // Reset flatrate to 'none' if it matches the org's default
                if ($org->default_flatrate_level && $removedUser->flatrate_level === $org->default_flatrate_level) {
                    $removedUser->flatrate_level = 'none';
                }
                // Reset can_purchase_upgrades if it was inherited from org
                if ($org->can_purchase_upgrades && $removedUser->can_purchase_upgrades) {
                    $removedUser->can_purchase_upgrades = false;
                }
                $removedUser->org_id = null;
                $removedUser->save();
            }
        }

        return response()->json(['success' => true]);
    }

    public function syncGroups(Request $request, $id)
    {
        $user = auth('api')->user();
        if (!$user->is_admin && !($user->is_org_admin && $user->org_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate(['group_ids' => 'array']);
        $org = Org::findOrFail($id);
        $org->galleryGroups()->sync($request->group_ids ?? []);

        return response()->json(['success' => true]);
    }


    public function generateCollectiveInvoice($id, \App\Services\InvoiceService $invoiceService)
    {
        $user = auth('api')->user();
        if (!$user->is_admin && !($user->is_org_admin && $user->org_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $org = Org::findOrFail($id);
        $result = $invoiceService->generateForOrg($org, $user);

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 400);
        }

        return response()->json($result);
    }
}
