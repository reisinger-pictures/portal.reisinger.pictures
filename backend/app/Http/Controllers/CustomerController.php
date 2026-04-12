<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->is_super_admin) return response()->json(['error' => 'Keine Berechtigung'], 403);
        
        $q = $request->query('q');
        if ($q && strlen($q) >= 2) {
            return response()->json(Customer::search($q)->orderBy('created_at', 'desc')->take(20)->get());
        }
        
        return response()->json(Customer::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->is_super_admin) return response()->json(['error' => 'Keine Berechtigung'], 403);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'street' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'uid' => 'nullable|string|max:100',
        ]);

        $customer = Customer::create($validated);
        return response()->json(['success' => true, 'customer' => $customer]);
    }

    public function update(Request $request, $id)
    {
        $user = auth('api')->user();
        if (!$user || !$user->is_super_admin) return response()->json(['error' => 'Keine Berechtigung'], 403);

        $customer = Customer::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'street' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'uid' => 'nullable|string|max:100',
        ]);

        $customer->update($validated);
        return response()->json(['success' => true, 'customer' => $customer]);
    }

    public function destroy($id)
    {
        $user = auth('api')->user();
        if (!$user || !$user->is_super_admin) return response()->json(['error' => 'Keine Berechtigung'], 403);

        Customer::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}
