<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\DepreciationCalculator;
use App\Exceptions\EurDocumentPositionException;
use App\Http\Controllers\Controller;
use App\Models\EurAssetGroup;
use App\Models\EurDocument;
use App\Services\Eur\EurAssetGroupSerializer;
use App\Services\Eur\EurDocumentPositionService;
use App\Support\BrandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EAR · Beleg-Positionen, Split und Merge (Portal #59, D-90).
 *
 * Alle Operationen sind brand-scoped: ein fremder Beleg, eine fremde Position
 * oder eine fremde Gruppe ist ein 404. Alle Beträge laufen als ganze Cent.
 */
class EurDocumentPositionController extends Controller
{
    public function __construct(
        private readonly EurDocumentPositionService $service,
        private readonly EurAssetGroupSerializer $groups,
    ) {}

    public function index(int $id): JsonResponse
    {
        $document = EurDocument::forCurrentBrand()->findOrFail($id);
        $data = $this->service->serializeDocument($document);

        return response()->json(['document' => $data, 'positions' => $data['positions']]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $document = EurDocument::forCurrentBrand()->findOrFail($id);
        $data = $request->validate([
            'total_cents' => 'nullable|integer|min:0',
            'positions' => 'required|array|min:1',
            'positions.*.name' => 'required|string|max:190',
            'positions.*.label' => 'nullable|string|max:190',
            'positions.*.amount_cents' => 'required|integer|min:1',
            'positions.*.business_share' => 'required|integer|min:0|max:100|multiple_of:5',
            'positions.*.useful_life_years' => 'required|integer|min:1|max:50',
            'positions.*.method' => ['required', Rule::in([
                DepreciationCalculator::METHOD_LINEAR,
                DepreciationCalculator::METHOD_DEGRESSIVE,
            ])],
            'positions.*.degressive_rate' => 'nullable|integer|min:100|max:3000',
            'positions.*.ordinal' => 'nullable|integer|min:0',
        ]);

        try {
            $this->service->createPositions(
                $document,
                $data['positions'],
                isset($data['total_cents']) ? (int) $data['total_cents'] : null,
            );
        } catch (EurDocumentPositionException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['document' => $this->service->serializeDocument($document->refresh())], 201);
    }

    public function destroy(int $positionId): JsonResponse
    {
        $this->service->deletePosition($positionId);

        return response()->json(['success' => true]);
    }

    public function assignGroup(Request $request, int $id): JsonResponse
    {
        $document = EurDocument::forCurrentBrand()->findOrFail($id);

        // Ein fremder Beleg ist ein 404; eine fremde Gruppe ebenfalls. Der
        // Vorab-Check läuft vor der Validierung, damit die brand-scoped
        // Rule::exists die fremde ID nicht als 422-Fehler maskiert.
        $rawGroupId = $request->input('group_id');
        if (is_int($rawGroupId) || (is_string($rawGroupId) && ctype_digit($rawGroupId))) {
            $candidate = (int) $rawGroupId;
            if (EurAssetGroup::whereKey($candidate)->where('brand', '!=', BrandRegistry::currentId())->exists()) {
                abort(404);
            }
        }

        $data = $request->validate([
            'group_id' => ['nullable', 'integer', Rule::exists('eur_asset_groups', 'id')->where('brand', BrandRegistry::currentId())],
        ]);
        $groupId = isset($data['group_id']) ? (int) $data['group_id'] : null;

        try {
            $this->service->assignGroup($document, $groupId);
        } catch (EurDocumentPositionException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $group = $groupId === null
            ? null
            : $this->groups->serialize(EurAssetGroup::forCurrentBrand()->findOrFail($groupId)->refresh());

        return response()->json([
            'group' => $group,
            'document' => $this->service->serializeDocument($document->refresh()),
        ]);
    }
}
