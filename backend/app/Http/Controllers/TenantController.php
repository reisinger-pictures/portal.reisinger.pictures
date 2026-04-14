<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantController extends Controller
{
    public function index()
    {
        $user = auth('api')->user();

        if ($user->is_admin) {
            return response()->json(Tenant::withCount(['users', 'galleryGroups'])->orderBy('name')->get());
        } elseif ($user->is_customer_manager) {
            return response()->json($user->tenants()->withCount(['users', 'galleryGroups'])->orderBy('name')->get());
        }

        return response()->json(['error' => 'Forbidden'], 403);
    }

    public function show($id)
    {
        $user = auth('api')->user();
        $tenant = Tenant::with(['users:id,name,email', 'galleryGroups:id,name,parent_id'])->findOrFail($id);

        if (!$user->is_admin && !$user->tenants->contains($id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $openDeliveryNotesCount = \App\Models\Order::whereIn('user_id', $tenant->users->pluck('id'))
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
            'invoice_frequency' => 'required|in:immediate,monthly,quarterly'
        ]);

        $tenant = Tenant::create($request->only(['name', 'domain', 'invoice_frequency']));
        return response()->json(['success' => true, 'tenant' => $tenant]);
    }

    public function update(Request $request, $id)
    {
        if (!auth('api')->user()->is_admin) return response()->json(['error' => 'Forbidden'], 403);

        $tenant = Tenant::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255|unique:tenants,domain,' . $id,
            'invoice_frequency' => 'required|in:immediate,monthly,quarterly'
        ]);

        $tenant->update($request->only(['name', 'domain', 'invoice_frequency']));
        return response()->json(['success' => true, 'tenant' => $tenant]);
    }

    public function destroy($id)
    {
        if (!auth('api')->user()->is_admin) return response()->json(['error' => 'Forbidden'], 403);
        Tenant::destroy($id);
        return response()->json(['success' => true]);
    }

    public function syncUsers(Request $request, $id)
    {
        if (!auth('api')->user()->is_admin) return response()->json(['error' => 'Forbidden'], 403);
        
        $request->validate([
            'user_ids' => 'array',
            'user_ids.*' => 'exists:users,id'
        ]);
        
        $superAdmins = \App\Models\User::whereIn('id', $request->user_ids ?? [])
            ->whereHas('roles', function($q) { $q->where('name', 'super_admin'); })
            ->exists();
            
        if ($superAdmins) {
            return response()->json(['error' => 'Super-Admins können keinem Mandanten zugewiesen werden.'], 422);
        }

        $tenant = Tenant::findOrFail($id);
        $tenant->users()->sync($request->user_ids ?? []);
        
        return response()->json(['success' => true]);
    }

    public function syncGroups(Request $request, $id)
    {
        if (!auth('api')->user()->is_admin) return response()->json(['error' => 'Forbidden'], 403);
        
        $request->validate(['group_ids' => 'array']);
        $tenant = Tenant::findOrFail($id);
        $tenant->galleryGroups()->sync($request->group_ids ?? []);
        
        return response()->json(['success' => true]);
    }


    public function generateCollectiveInvoice($id, \App\Services\InvoiceService $invoiceService)
    {
        $user = auth('api')->user();
        if (!$user->is_admin && !($user->is_customer_manager && $user->tenants->contains($id))) {
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
