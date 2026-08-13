<?php
namespace App\Http\Controllers;

use App\Models\VolumePreset;
use App\Services\VolumePresetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VolumePresetController extends Controller
{
    public function __construct(
        private VolumePresetService $presetService,
    ) {}

    /**
     * List presets (with tiers) for the current brand.
     */
    public function index()
    {
        $presets = VolumePreset::forCurrentBrand()
            ->with('tiers')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'presets' => $presets->map(fn (VolumePreset $preset) => [
                'id' => $preset->id,
                'name' => $preset->name,
                'is_default' => $preset->is_default,
                'tiers' => $preset->tiers->map(fn ($tier) => [
                    'position' => $tier->position,
                    'min_quantity' => $tier->min_quantity,
                    'price_cents' => $tier->price_cents,
                ])->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-catalog');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'tiers' => 'required|array|min:1',
            'tiers.*.min_quantity' => 'required|integer|min:0',
            'tiers.*.price_cents' => 'required|integer|min:0',
        ]);

        $preset = $this->presetService->create($data['name'], $data['tiers']);
        return response()->json($this->serialize($preset));
    }

    public function update(Request $request, $id)
    {
        Gate::authorize('manage-catalog');

        $preset = VolumePreset::forCurrentBrand()->findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'tiers' => 'required|array|min:1',
            'tiers.*.min_quantity' => 'required|integer|min:0',
            'tiers.*.price_cents' => 'required|integer|min:0',
        ]);

        $preset = $this->presetService->update($preset, $data['name'], $data['tiers']);
        return response()->json($this->serialize($preset));
    }

    public function destroy($id)
    {
        Gate::authorize('manage-catalog');

        $preset = VolumePreset::forCurrentBrand()->findOrFail($id);

        try {
            $this->presetService->delete($preset);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['preset' => $e->getMessage()]);
        }

        return response()->json(['success' => true]);
    }

    public function setDefault($id)
    {
        Gate::authorize('manage-catalog');

        $preset = VolumePreset::forCurrentBrand()->findOrFail($id);
        $preset = $this->presetService->setDefault($preset);

        return response()->json($this->serialize($preset));
    }

    private function serialize(VolumePreset $preset): array
    {
        $preset->load('tiers');
        return [
            'id' => $preset->id,
            'name' => $preset->name,
            'is_default' => $preset->is_default,
            'tiers' => $preset->tiers->map(fn ($tier) => [
                'position' => $tier->position,
                'min_quantity' => $tier->min_quantity,
                'price_cents' => $tier->price_cents,
            ])->values(),
        ];
    }
}
