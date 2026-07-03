<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Http\Resources\ProductResource;
use App\Support\BrandRegistry;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::forCurrentBrand();

        if ($request->query('type')) {
            $query->whereIn('type', explode(',', $request->query('type')));
        }

        $q = $request->query('q');
        if ($q && strlen($q) >= 2) {
            $query->where('name', 'like', '%' . $q . '%');
            $products = $query->take(20)->get();
            return response()->json($products->map(fn($p) => new ProductResource($p))->values());
        }

        $products = $query->orderBy('name', 'asc')->get();
        return response()->json($products->map(fn($p) => new ProductResource($p))->values());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:item,discount_fixed,discount_percent',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|integer|min:0',
        ]);

        $validated['brand'] = BrandRegistry::currentOrDefault()->value;
        $product = Product::create($validated);
        return response()->json(['success' => true, 'product' => new ProductResource($product)]);
    }

    public function update(Request $request, $id)
    {
        $product = Product::forCurrentBrand()->findOrFail($id);

        $validated = $request->validate([
            'type' => 'required|string|in:item,discount_fixed,discount_percent',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|integer|min:0',
        ]);

        $product->update($validated);
        return response()->json(['success' => true, 'product' => new ProductResource($product)]);
    }

    public function destroy($id)
    {
        Product::forCurrentBrand()->findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}
