<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Support\BrandRegistry;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $brand = BrandRegistry::currentOrDefault()->value;

        $q = $request->query('q');
        if ($q && strlen($q) >= 2) {
            return response()->json(
                Customer::search($q)
                    ->query(fn($query) => $query->where('brand', $brand))
                    ->orderBy('created_at', 'desc')
                    ->take(20)
                    ->get()
            );
        }

        return response()->json(Customer::where('brand', $brand)->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
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

        $validated['brand'] = BrandRegistry::currentOrDefault()->value;
        $customer = Customer::create($validated);
        return response()->json(['success' => true, 'customer' => $customer]);
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::forCurrentBrand()->findOrFail($id);

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
        Customer::forCurrentBrand()->findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}
