<?php
namespace App\Http\Controllers;
use App\Models\LicenseUseCase;
use App\Models\LicenseModifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LicenseCatalogController extends Controller {
    public function index() {
        return response()->json([
            'use_cases' => LicenseUseCase::orderBy('sort_order')->orderBy('name')->get(),
            'modifiers' => LicenseModifier::orderBy('sort_order')->orderBy('name')->get()
        ]);
    }
    public function storeUseCase(Request $request) {
        Gate::authorize('manage-catalog');
        $data = $request->validate(['name' => 'required|string', 'description' => 'nullable|string', 'base_price' => 'required|integer', 'flatrate_tier' => 'nullable|string', 'sort_order' => 'integer']);
        return response()->json(LicenseUseCase::create($data));
    }
    public function updateUseCase(Request $request, $id) {
        Gate::authorize('manage-catalog');
        $data = $request->validate(['name' => 'required|string', 'description' => 'nullable|string', 'base_price' => 'required|integer', 'flatrate_tier' => 'nullable|string', 'sort_order' => 'integer']);
        $uc = LicenseUseCase::findOrFail($id); $uc->update($data); return response()->json($uc);
    }
    public function destroyUseCase($id) {
        Gate::authorize('manage-catalog');
        LicenseUseCase::destroy($id); return response()->json(['success' => true]);
    }
    public function storeModifier(Request $request) {
        Gate::authorize('manage-catalog');
        $data = $request->validate(['name' => 'required|string', 'description' => 'nullable|string', 'percent_surcharge' => 'required|numeric', 'is_included_in_flatrate' => 'boolean', 'sort_order' => 'integer']);
        return response()->json(LicenseModifier::create($data));
    }
    public function updateModifier(Request $request, $id) {
        Gate::authorize('manage-catalog');
        $data = $request->validate(['name' => 'required|string', 'description' => 'nullable|string', 'percent_surcharge' => 'required|numeric', 'is_included_in_flatrate' => 'boolean', 'sort_order' => 'integer']);
        $mod = LicenseModifier::findOrFail($id); $mod->update($data); return response()->json($mod);
    }
    public function destroyModifier($id) {
        Gate::authorize('manage-catalog');
        LicenseModifier::destroy($id); return response()->json(['success' => true]);
    }
}
