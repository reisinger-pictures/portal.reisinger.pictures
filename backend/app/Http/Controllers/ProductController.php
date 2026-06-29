<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();

        if ($request->query('type')) {
            $query->whereIn('type', explode(',', $request->query('type')));
        }

        $q = $request->query('q');
        if ($q && strlen($q) >= 2) {
            $query->where('name', 'like', '%' . $q . '%');
            return response()->json($query->take(20)->get());
        }

        return response()->json($query->orderBy('name', 'asc')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:item,discount_fixed,discount_percent',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|integer|min:0',
        ]);

        $product = Product::create($validated);
        return response()->json(['success' => true, 'product' => $product]);
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'type' => 'required|string|in:item,discount_fixed,discount_percent',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|integer|min:0',
        ]);

        $product->update($validated);
        return response()->json(['success' => true, 'product' => $product]);
    }

    public function destroy($id)
    {
        Product::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}
