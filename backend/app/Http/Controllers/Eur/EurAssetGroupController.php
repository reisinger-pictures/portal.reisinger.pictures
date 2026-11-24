<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Http\Controllers\Controller;
use App\Models\EurAsset;
use App\Models\EurAssetGroup;
use App\Models\EurAuditLog;
use App\Services\Eur\EurAssetGroupSerializer;
use App\Services\Eur\EurAssetGroupService;
use App\Support\BrandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * EAR · Anlagegruppen (Portal #57).
 *
 * Eine Gruppe bündelt mehrere Anlagegüter zu einer Abrechnungseinheit. Belege
 * bleiben an den einzelnen Gütern; die Gruppe trägt nur den Betriebsanteil und
 * erzeugt eine Entnahme bzw. einen Eigenbeleg je Jahr.
 */
class EurAssetGroupController extends Controller
{
    public function __construct(
        private readonly EurAssetGroupService $service,
        private readonly EurAssetGroupSerializer $serializer,
    ) {}

    public function index(): JsonResponse
    {
        $groups = EurAssetGroup::forCurrentBrand()
            ->with(['assets', 'years'])
            ->orderBy('name')
            ->get()
            ->map(fn (EurAssetGroup $group): array => $this->serializer->serialize($group));

        return response()->json(['groups' => $groups]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateGroup($request);

        $group = DB::transaction(function () use ($data): EurAssetGroup {
            $group = EurAssetGroup::create(array_merge($data, ['brand' => BrandRegistry::currentId()]));
            $this->service->sync($group);
            $this->audit('group_created', $group);

            return $group;
        });

        return response()->json(['group' => $this->serializer->serialize($group->refresh())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $group = EurAssetGroup::forCurrentBrand()->findOrFail($id);
        $data = $this->validateGroup($request, $group->id);

        DB::transaction(function () use ($group, $data): void {
            $group->update($data);
            $this->service->sync($group->refresh());
            $this->audit('group_updated', $group);
        });

        return response()->json(['group' => $this->serializer->serialize($group->refresh())]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $group = EurAssetGroup::forCurrentBrand()->findOrFail($id);
        $data = $request->validate([
            'asset_id' => ['required', 'integer', Rule::exists('eur_assets', 'id')->where('brand', BrandRegistry::currentId())],
        ]);
        $asset = EurAsset::forCurrentBrand()->findOrFail((int) $data['asset_id']);

        try {
            $this->service->assertAssignable($asset);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        DB::transaction(function () use ($group, $asset): void {
            if ($asset->eur_asset_group_id !== null && (int) $asset->eur_asset_group_id !== $group->id) {
                $old = EurAssetGroup::forCurrentBrand()->find($asset->eur_asset_group_id);
                $asset->update([
                    'eur_asset_group_id' => null,
                    'scope' => (int) $asset->business_share < 50 ? 'private' : 'business',
                ]);
                $this->service->restoreIndividualBookkeeping($asset);
                if ($old !== null) {
                    $this->service->sync($old->refresh());
                }
            }

            $this->service->removeIndividualBookkeeping($asset);
            // Portal #57: der Gruppenanteil überschreibt Anteil und Verzeichnis.
            $asset->update([
                'eur_asset_group_id' => $group->id,
                'scope' => (int) $group->business_share < 50 ? 'private' : 'business',
            ]);
            $this->service->sync($group->refresh());
            $this->audit('group_asset_assigned', $group, ['asset_id' => $asset->id]);
        });

        return response()->json(['group' => $this->serializer->serialize($group->refresh())]);
    }

    public function unassign(int $id, int $assetId): JsonResponse
    {
        $group = EurAssetGroup::forCurrentBrand()->findOrFail($id);
        $asset = EurAsset::forCurrentBrand()->where('eur_asset_group_id', $group->id)->findOrFail($assetId);

        DB::transaction(function () use ($group, $asset): void {
            $asset->update([
                'eur_asset_group_id' => null,
                'scope' => (int) $asset->business_share < 50 ? 'private' : 'business',
            ]);
            $this->service->restoreIndividualBookkeeping($asset);
            $this->service->sync($group->refresh());
            $this->audit('group_asset_unassigned', $group, ['asset_id' => $asset->id]);
        });

        return response()->json(['group' => $this->serializer->serialize($group->refresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $group = EurAssetGroup::forCurrentBrand()->findOrFail($id);

        DB::transaction(function () use ($group): void {
            foreach ($group->assets as $asset) {
                $asset->update([
                    'eur_asset_group_id' => null,
                    'scope' => (int) $asset->business_share < 50 ? 'private' : 'business',
                ]);
                $this->service->restoreIndividualBookkeeping($asset);
            }
            $this->audit('group_deleted', $group);
            $group->delete();
        });

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGroup(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:190',
                Rule::unique('eur_asset_groups', 'name')
                    ->where('brand', BrandRegistry::currentId())
                    ->ignore($id),
            ],
            'business_share' => ['required', 'integer', 'min:0', 'max:100', 'multiple_of:5'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $action, EurAssetGroup $group, array $extra = []): void
    {
        EurAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'eur_asset_group',
            'subject_id' => $group->id,
            'payload' => array_merge([
                'name' => $group->name,
                'business_share' => $group->business_share,
            ], $extra),
        ]);
    }
}
