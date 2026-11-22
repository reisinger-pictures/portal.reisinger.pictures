<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\DepreciationCalculator;
use App\Eur\Parity\RuleValues;
use App\Eur\Support\Share;
use App\Http\Controllers\Controller;
use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Models\EurAuditLog;
use App\Support\BrandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * EÜR · Anlagegüter und AfA (EUE-049 … EUE-061, DEG-001 … DEG-015).
 *
 * Der Jahresplan wird serverseitig aus dem gepinnten Regelkatalog berechnet
 * und als `eur_asset_years` festgehalten. Ein Wechsel zur linearen AfA ist
 * einmalig, nur ab dem zweiten Jahr und nur mit Begründung zulässig.
 */
class EurAssetController extends Controller
{
    public function index(): JsonResponse
    {
        $assets = EurAsset::forCurrentBrand()->with('years')->orderBy('name')->get();

        return response()->json([
            'assets' => $assets->map(fn (EurAsset $asset): array => $this->serialize($asset))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAsset($request);
        $data['brand'] = BrandRegistry::currentId();

        $calculator = new DepreciationCalculator(new RuleValues);
        $month = (int) Carbon::parse($data['purchase_date'])->format('n');

        if ($data['method'] === DepreciationCalculator::METHOD_DEGRESSIVE) {
            $this->assertDegressiveAllowed($data, $calculator);
        }

        $asset = DB::transaction(function () use ($data, $calculator, $month): EurAsset {
            $asset = EurAsset::create($data);
            $this->writePlan($asset, $calculator, $month);

            return $asset;
        });

        $this->audit('created', $asset);

        return response()->json(['asset' => $this->serialize($asset->refresh())], 201);
    }

    public function plan(int $id): JsonResponse
    {
        $asset = EurAsset::forCurrentBrand()->with('years')->findOrFail($id);

        return response()->json(['asset' => $this->serialize($asset)]);
    }

    /**
     * DEG-012: Vergleichsrechner linear vs. degressiv, auch ohne gespeichertes Anlagegut.
     */
    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cost_cents' => 'required|integer|min:1',
            'useful_life_years' => 'required|integer|min:2|max:99',
            'degressive_rate' => 'required|integer|min:100|max:3000',
            'acquisition_month' => 'required|integer|min:1|max:12',
        ]);

        $calculator = new DepreciationCalculator(new RuleValues);
        $cost = (int) $data['cost_cents'];
        $life = (int) $data['useful_life_years'];
        $rate = (int) $data['degressive_rate'];
        $month = (int) $data['acquisition_month'];

        $switchYear = $calculator->recommendedSwitchYear($cost, $life, $rate, $month);

        return response()->json([
            'linear' => $calculator->linearPlan($cost, $life, $month),
            'degressive' => $calculator->degressivePlan($cost, $life, $rate, $month),
            'switch_recommended_year' => $switchYear,
            'switch_advantage_cents' => $calculator->switchAdvantageCents($cost, $life, $rate, $month),
        ]);
    }

    /**
     * DEG-009/DEG-014: einmaliger, begründeter Wechsel degressiv → linear.
     */
    public function switchToLinear(Request $request, int $id): JsonResponse
    {
        $asset = EurAsset::forCurrentBrand()->findOrFail($id);

        if ($asset->method !== DepreciationCalculator::METHOD_DEGRESSIVE) {
            return response()->json(['error' => 'Nur ein degressives Anlagegut kann zur linearen AfA wechseln.'], 422);
        }

        if ($asset->years()->where('method', DepreciationCalculator::METHOD_LINEAR)->exists()) {
            return response()->json(['error' => 'Der Wechsel zur linearen AfA wurde bereits vollzogen.'], 422);
        }

        $data = $request->validate([
            'year' => 'required|integer|min:2',
            'reason' => 'required|string|min:5|max:1000',
        ]);

        $purchaseYear = (int) $asset->purchase_date->format('Y');
        $switchYear = (int) $data['year'];
        $relativeYear = $switchYear - $purchaseYear + 1;

        if ($relativeYear < 2 || $relativeYear > (int) $asset->useful_life_years) {
            return response()->json(['error' => 'Das Wechseljahr liegt außerhalb der Nutzungsdauer.'], 422);
        }

        $calculator = new DepreciationCalculator(new RuleValues);

        DB::transaction(function () use ($asset, $calculator, $relativeYear, $data): void {
            $asset->years()->delete();
            $this->writePlan($asset, $calculator, (int) $asset->purchase_date->format('n'), $relativeYear);
            $asset->forceFill(['method' => DepreciationCalculator::METHOD_LINEAR])->save();

            $this->audit('switched_to_linear', $asset, ['switch_year' => $data['year'], 'reason' => $data['reason']]);
        });

        return response()->json(['asset' => $this->serialize($asset->refresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $asset = EurAsset::forCurrentBrand()->findOrFail($id);
        $asset->delete();
        $this->audit('deleted', $asset);

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAsset(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:190',
            'invoice_no' => 'required|string|max:64',
            'supplier' => 'required|string|max:190',
            'purchase_date' => 'required|date',
            'cost_cents' => 'required|integer|min:1',
            'useful_life_years' => 'required|integer|min:2|max:99',
            'business_share' => 'required|integer|min:50|max:100|multiple_of:5',
            'method' => ['required', Rule::in([DepreciationCalculator::METHOD_LINEAR, DepreciationCalculator::METHOD_DEGRESSIVE])],
            'degressive_rate' => 'nullable|integer|min:100|max:3000',
            'notes' => 'nullable|string|max:2000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertDegressiveAllowed(array $data, DepreciationCalculator $calculator): void
    {
        if (empty($data['degressive_rate']) || ! $calculator->isDegressiveRateValid((int) $data['degressive_rate'])) {
            abort(422, 'Für die degressive AfA ist ein Satz zwischen 1 % und 30 % erforderlich.');
        }

        if (! $calculator->isBusinessShareValid((int) $data['business_share'])) {
            abort(422, 'Der Betriebsanteil muss zwischen 50 % und 100 % in 5-%-Schritten liegen.');
        }
    }

    private function writePlan(EurAsset $asset, DepreciationCalculator $calculator, int $month, ?int $switchYear = null): void
    {
        $plan = $asset->method === DepreciationCalculator::METHOD_DEGRESSIVE
            ? $calculator->degressivePlan((int) $asset->cost_cents, (int) $asset->useful_life_years, (int) $asset->degressive_rate, $month, $switchYear)
            : $calculator->linearPlan((int) $asset->cost_cents, (int) $asset->useful_life_years, $month);

        $purchaseYear = (int) $asset->purchase_date->format('Y');
        $privatePercent = 100 - (int) $asset->business_share;

        foreach ($plan as $row) {
            $privateCents = Share::apply($row['depreciation_cents'], $privatePercent);

            EurAssetYear::create([
                'brand' => $asset->brand,
                'eur_asset_id' => $asset->id,
                'year' => $purchaseYear + $row['year'] - 1,
                'method' => $row['method'],
                'depreciation_cents' => $row['depreciation_cents'],
                'book_value_cents' => $row['book_value_cents'],
                'private_use_percent' => $privatePercent,
                'private_use_cents' => $privateCents,
                'business_use_cents' => $row['depreciation_cents'] - $privateCents,
                'status' => 'planned',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $action, EurAsset $asset, array $extra = []): void
    {
        EurAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'eur_asset',
            'subject_id' => $asset->id,
            'payload' => array_merge([
                'cost_cents' => $asset->cost_cents,
                'method' => $asset->method,
                'business_share' => $asset->business_share,
            ], $extra),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EurAsset $asset): array
    {
        return [
            'id' => (int) $asset->id,
            'name' => $asset->name,
            'invoice_no' => $asset->invoice_no,
            'supplier' => $asset->supplier,
            'purchase_date' => $asset->purchase_date?->format('Y-m-d'),
            'cost_cents' => (int) $asset->cost_cents,
            'useful_life_years' => (int) $asset->useful_life_years,
            'business_share' => (int) $asset->business_share,
            'method' => $asset->method,
            'degressive_rate' => $asset->degressive_rate === null ? null : (int) $asset->degressive_rate,
            'status' => $asset->status,
            'years' => $asset->years->map(fn (EurAssetYear $year): array => [
                'year' => (int) $year->year,
                'method' => $year->method,
                'depreciation_cents' => (int) $year->depreciation_cents,
                'book_value_cents' => (int) $year->book_value_cents,
                'private_use_percent' => (int) $year->private_use_percent,
                'private_use_cents' => (int) $year->private_use_cents,
                'business_use_cents' => (int) $year->business_use_cents,
                'status' => $year->status,
            ])->values(),
        ];
    }
}
