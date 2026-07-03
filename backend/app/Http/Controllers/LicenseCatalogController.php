<?php
namespace App\Http\Controllers;
use App\Models\LicenseUseCase;
use App\Models\LicenseModifier;
use App\Http\Resources\LicenseUseCaseResource;
use App\Http\Resources\LicenseModifierResource;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LicenseCatalogController extends Controller {
    public function index() {
        $ucs = LicenseUseCase::forCurrentBrand()->orderBy('sort_order')->orderBy('name')->get();
        $mods = LicenseModifier::forCurrentBrand()->orderBy('sort_order')->orderBy('name')->get();
        return response()->json([
            'use_cases' => $ucs->map(fn($uc) => new LicenseUseCaseResource($uc))->values(),
            'modifiers' => $mods->map(fn($mod) => new LicenseModifierResource($mod))->values()
        ]);
    }
    public function storeUseCase(Request $request) {
        Gate::authorize('manage-catalog');
        $data = $request->validate(['name' => 'required|string', 'description' => 'nullable|string', 'base_price' => 'required|integer', 'flatrate_tier' => 'nullable|string', 'sort_order' => 'integer', 'is_commercial' => 'boolean']);
        $data['brand'] = BrandRegistry::currentOrDefault()->value;
        return response()->json(new LicenseUseCaseResource(LicenseUseCase::create($data)));
    }
    public function updateUseCase(Request $request, $id) {
        Gate::authorize('manage-catalog');
        $data = $request->validate(['name' => 'required|string', 'description' => 'nullable|string', 'base_price' => 'required|integer', 'flatrate_tier' => 'nullable|string', 'sort_order' => 'integer', 'is_commercial' => 'boolean']);
        $uc = LicenseUseCase::forCurrentBrand()->findOrFail($id); $uc->update($data); return response()->json(new LicenseUseCaseResource($uc));
    }
    public function destroyUseCase($id) {
        Gate::authorize('manage-catalog');
        LicenseUseCase::forCurrentBrand()->findOrFail($id); LicenseUseCase::destroy($id); return response()->json(['success' => true]);
    }
    public function storeModifier(Request $request) {
        Gate::authorize('manage-catalog');
        $data = $request->validate(['name' => 'required|string', 'description' => 'nullable|string', 'percent_surcharge' => 'required|numeric', 'is_included_in_flatrate' => 'boolean', 'sort_order' => 'integer']);
        $data['brand'] = BrandRegistry::currentOrDefault()->value;
        return response()->json(new LicenseModifierResource(LicenseModifier::create($data)));
    }
    public function updateModifier(Request $request, $id) {
        Gate::authorize('manage-catalog');
        $data = $request->validate(['name' => 'required|string', 'description' => 'nullable|string', 'percent_surcharge' => 'required|numeric', 'is_included_in_flatrate' => 'boolean', 'sort_order' => 'integer']);
        $mod = LicenseModifier::forCurrentBrand()->findOrFail($id); $mod->update($data); return response()->json(new LicenseModifierResource($mod));
    }
    public function destroyModifier($id) {
        Gate::authorize('manage-catalog');
        LicenseModifier::forCurrentBrand()->findOrFail($id); LicenseModifier::destroy($id); return response()->json(['success' => true]);
    }
}
