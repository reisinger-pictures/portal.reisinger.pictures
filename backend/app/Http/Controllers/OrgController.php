<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\EnforcesBrandIsolation;
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
        $user = auth('api')->user();
        if (! $svc->isAdmin($user) && ! ($svc->isOrgAdmin($user) && $user->org_id === $id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $request->validate([
            'user_ids' => 'array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $org = Org::findOrFail($id);

        if ($this->brandValue($org) === null || $this->isBrandMismatch($user, $org)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        $superAdmins = User::whereIn('id', $request->user_ids ?? [])
            ->whereHas('roles', function ($q) {
                $q->where('name', UserRole::SUPER_ADMIN->value);
            })
            ->exists();

        if ($superAdmins) {
            return response()->json(['error' => 'Super-Admins können keiner Organisation zugewiesen werden.'], 422);
        }

        // Brand consistency: assigned users must share the org's brand. A legacy
        // null-brand account is invalid unless it is a trusted Super-Admin;
        // Super-Admins were rejected above, so reject the remaining null rows
        // instead of linking them into a concrete organization.
        $orgBrand = $this->brandValue($org);
        $legacyNullUsers = User::whereIn('id', $request->user_ids ?? [])
            ->whereNull('brand')
            ->exists();
        if ($legacyNullUsers) {
            return response()->json(['error' => 'Benutzer ohne Brand-Zuweisung können keiner Organisation zugewiesen werden.'], 422);
        }

        $conflictingUsers = User::whereIn('id', $request->user_ids ?? [])
            ->whereNotNull('brand')
            ->when($orgBrand !== null, fn ($query) => $query->where('brand', '!=', $orgBrand))
            ->exists();
        if ($conflictingUsers) {
            return response()->json(['error' => 'Ein oder mehrere Benutzer sind für eine andere Marke registriert und können dieser Organisation nicht zugewiesen werden.'], 422);
        }

        // Get currently assigned user IDs before syncing
        $oldUserIds = $org->users()->pluck('id')->toArray();
        $newUserIds = $request->user_ids ?? [];
        $removedUserIds = array_diff($oldUserIds, $newUserIds);

        // Set org_id on newly assigned users
        User::whereIn('id', $newUserIds)->update(['org_id' => $id]);

        // Revoke organization-derived role + flatrate from removed users
        if (! empty($removedUserIds)) {
            $removedUsers = User::whereIn('id', $removedUserIds)->get();
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
}
