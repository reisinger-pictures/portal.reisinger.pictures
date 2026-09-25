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
                // Numeric wire contract: `volume_presets.id` is a bigint primary
                // key and is delivered as a JSON number, not a string.
                'id' => (int) $preset->id,
                'name' => $preset->name,
                'is_default' => $preset->is_default,
                'tiers' => $preset->tiers->map(fn ($tier) => [
                    'position' => (int) $tier->position,
                    'min_quantity' => (int) $tier->min_quantity,
                    'price_cents' => (int) $tier->price_cents,
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

        $this->validateTierMonotonicity($data['tiers']);

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

        $this->validateTierMonotonicity($data['tiers']);

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

    /**
     * A volume preset must be monotonic: `min_quantity` strictly increases and
     * `price_cents` strictly decreases per tier. Non-monotonic or duplicate
     * tiers make the qualifying-tier price ambiguous and are rejected with 422.
     *
     * @param  array<int, array{min_quantity: int|string, price_cents: int|string}>  $tiers
     */
    private function validateTierMonotonicity(array $tiers): void
    {
        $sorted = collect($tiers)
            ->sortBy(fn ($tier) => (int) $tier['min_quantity'])
            ->values();

        $errors = [];
        $previousMin = null;
        $previousPrice = null;

        foreach ($sorted as $index => $tier) {
            $minQuantity = (int) $tier['min_quantity'];
            $priceCents = (int) $tier['price_cents'];

            if ($previousMin !== null && $minQuantity <= $previousMin) {
                $errors["tiers.{$index}.min_quantity"] = 'Die Mindestmenge muss pro Stufe streng ansteigen.';
            } elseif ($previousPrice !== null && $priceCents >= $previousPrice) {
                $errors["tiers.{$index}.price_cents"] = 'Der Preis muss mit steigender Menge streng sinken.';
            }

            $previousMin = $minQuantity;
            $previousPrice = $priceCents;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function serialize(VolumePreset $preset): array
    {
        $preset->load('tiers');
        return [
            // Numeric wire contract: see index().
            'id' => (int) $preset->id,
            'name' => $preset->name,
            'is_default' => $preset->is_default,
            'tiers' => $preset->tiers->map(fn ($tier) => [
                'position' => (int) $tier->position,
                'min_quantity' => (int) $tier->min_quantity,
                'price_cents' => (int) $tier->price_cents,
            ])->values(),
        ];
    }
}
