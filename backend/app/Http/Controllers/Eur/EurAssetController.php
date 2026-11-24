<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\AssetPlausibility;
use App\Eur\Domain\DepreciationCalculator;
use App\Eur\Domain\EurAssetCarryOver;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\DegressiveRules;
use App\Eur\Reference\EurYear;
use App\Eur\Reference\ReferenceTables;
use App\Eur\Support\Share;
use App\Http\Controllers\Controller;
use App\Models\EurAsset;
use App\Models\EurAssetDocument;
use App\Models\EurAssetYear;
use App\Models\EurAuditLog;
use App\Models\EurDocument;
use App\Models\EurEntry;
use App\Services\Eur\EurAssetEigenbelegService;
use App\Support\BrandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * EAR · Anlagegüter und AfA (EUE-049 … EUE-061, DEG-001 … DEG-015).
 *
 * Der Jahresplan wird serverseitig aus dem gepinnten Regelkatalog berechnet
 * und als `eur_asset_years` festgehalten. Ein Wechsel zur linearen AfA ist
 * einmalig, nur ab dem zweiten Jahr und nur mit Begründung zulässig.
 */
class EurAssetController extends Controller
{
    public function __construct(private readonly EurAssetEigenbelegService $eigenbelegService) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'private' => 'nullable|boolean',
            'scope' => 'nullable|in:business,private',
        ]);

        $query = EurAsset::forCurrentBrand()->with(['years', 'assetDocuments']);

        if (($data['private'] ?? false) === true || ($data['private'] ?? null) === '1') {
            $query->where('is_private_investment', true);
        }

        if (isset($data['scope'])) {
            $query->where('scope', $data['scope']);
        }

        $assets = $query->orderBy('name')->get();

        return response()->json([
            'assets' => $assets->map(fn (EurAsset $asset): array => $this->serialize($asset))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAsset($request);
        $data['brand'] = BrandRegistry::currentId();
        // D-85: die Einordnung ist serverseitig aus dem ganze-Prozent-Anteil
        // abgeleitet; ein Client kann `scope` nicht setzen (prohibited).
        $data['scope'] = (int) $data['business_share'] < 50 ? 'private' : 'business';

        $calculator = new DepreciationCalculator(new RuleValues);
        $month = (int) Carbon::parse($data['purchase_date'])->format('n');
        $data['category'] ??= $this->suggestedCategory((string) $data['name'])['type'];

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
        $asset = EurAsset::forCurrentBrand()->with(['years', 'assetDocuments'])->findOrFail($id);

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
            'business_share' => 'nullable|integer|min:0|max:100|multiple_of:5',
            'name' => 'nullable|string|max:190',
        ]);

        $calculator = new DepreciationCalculator(new RuleValues);
        $cost = (int) $data['cost_cents'];
        $life = (int) $data['useful_life_years'];
        $rate = (int) $data['degressive_rate'];
        $month = (int) $data['acquisition_month'];
        $share = (int) ($data['business_share'] ?? 100);

        $linear = $calculator->linearPlan($cost, $life, $month);
        $degressive = $calculator->degressivePlan($cost, $life, $rate, $month);
        $switchYear = $calculator->recommendedSwitchYear($cost, $life, $rate, $month);

        return response()->json([
            'linear' => $linear,
            'degressive' => $degressive,
            'first_year' => [
                'linear_cents' => $linear[0]['depreciation_cents'],
                'degressive_cents' => $degressive[0]['depreciation_cents'],
                'linear_business_cents' => $this->businessCents($linear[0]['depreciation_cents'], $share),
                'degressive_business_cents' => $this->businessCents($degressive[0]['depreciation_cents'], $share),
            ],
            'suggested_category' => $this->suggestedCategory((string) ($data['name'] ?? '')),
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

        // DEG-013/014: nach dem Wechsel gibt es keinen Schreibpfad zurück auf
        // degressiv — weder über diesen Endpunkt noch über einen Methoden-Edit.
        if ($asset->switched_to_linear_at !== null
            || $asset->years()->where('method', DepreciationCalculator::METHOD_LINEAR)->exists()) {
            return response()->json(['error' => 'Der Wechsel zur linearen AfA wurde bereits vollzogen. Ein Rückwechsel ist nicht möglich.'], 422);
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

        // DEG-013/014: gebuchte Jahre sind final und werden nie umgeschrieben.
        $bookedYears = $asset->years()
            ->where(static function ($query): void {
                $query->whereNotNull('eur_entry_id')->orWhere('status', 'booked');
            })
            ->orderBy('year')
            ->get();
        $lastBookedIndex = $bookedYears->isEmpty()
            ? 0
            : max($bookedYears->map(fn (EurAssetYear $year): int => (int) $year->year - $purchaseYear + 1)->all());

        if ($relativeYear <= $lastBookedIndex) {
            return response()->json([
                'error' => 'Das Wechseljahr liegt in einem bereits gebuchten Jahr. Gebuchte AfA-Jahre bleiben unverändert.',
            ], 422);
        }

        $calculator = new DepreciationCalculator(new RuleValues);

        DB::transaction(function () use ($asset, $calculator, $relativeYear, $data, $bookedYears, $lastBookedIndex): void {
            if ($bookedYears->isEmpty()) {
                $asset->years()->delete();
                $this->writePlan($asset, $calculator, (int) $asset->purchase_date->format('n'), $relativeYear);
            } else {
                $lastBooked = $bookedYears->last();
                $remainingYears = (int) $asset->useful_life_years - $lastBookedIndex;
                $asset->years()->whereNull('eur_entry_id')->where('status', '!=', 'booked')->delete();
                $this->writeRemainingLinearPlan($asset, $calculator, (int) $lastBooked->book_value_cents, $remainingYears, $lastBookedIndex);
            }

            $asset->forceFill([
                'method' => DepreciationCalculator::METHOD_LINEAR,
                'switched_to_linear_at' => now(),
                'switch_reason' => $data['reason'],
            ])->save();

            $this->audit('switched_to_linear', $asset, ['switch_year' => $data['year'], 'reason' => $data['reason']]);
        });

        return response()->json(['asset' => $this->serialize($asset->refresh())]);
    }

    /**
     * #34: Jahresabschlussbuchung der betrieblichen AfA eines Anlageguts.
     * Idempotent über die Verknüpfung im AfA-Jahr.
     */
    public function bookDepreciation(Request $request, int $id): JsonResponse
    {
        $asset = EurAsset::forCurrentBrand()->findOrFail($id);
        $data = $request->validate(['year' => ['required', 'integer']]);

        try {
            EurYear::assert((int) $data['year']);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $year = (int) $data['year'];
        $plan = $asset->years()->where('year', $year)->firstOrFail();

        // D-85: das private Verzeichnis dokumentiert die fiktive AfA in einem
        // Eigenbeleg. Es entsteht keine Buchung und kein Aufwand, der den
        // Gewinn mindert.
        if ($asset->scope === 'private') {
            return $this->bookPrivateEigenbeleg($asset, $plan, $year);
        }

        if ($plan->eur_entry_id !== null) {
            return response()->json([
                'entry' => $this->serializeEntry(EurEntry::forCurrentBrand()->findOrFail($plan->eur_entry_id)),
                'already_booked' => true,
            ]);
        }

        if ($plan->business_use_cents <= 0) {
            return response()->json(['error' => 'Für dieses Jahr ist keine betriebliche Abschreibung vorhanden.'], 422);
        }

        $entry = DB::transaction(function () use ($asset, $plan, $year): EurEntry {
            $code = $this->categoryCode((string) $asset->category);

            // Der Betrag ist bereits der Betriebsanteil (private_use wurde im
            // Plan abgezogen), daher wird er nicht erneut gekürzt.
            $entry = EurEntry::create([
                'brand' => $asset->brand,
                'year' => $year,
                'month' => 12,
                'day' => 31,
                'direction' => 'expense',
                'kind_type' => 'AfA',
                'category' => $asset->category,
                'description' => 'Jahresabschluss '.$asset->name,
                'e1a_code' => $code,
                'business_share' => 100,
                'amount_cents' => (int) $plan->business_use_cents,
                'business_amount_cents' => (int) $plan->business_use_cents,
                'receipt_no' => 'AV-'.$asset->id.'-'.$year,
                'note' => 'Automatische AfA-Buchung aus dem Anlagegut '.$asset->name,
            ]);

            $plan->forceFill(['eur_entry_id' => $entry->id, 'booked_at' => now(), 'status' => 'booked'])->save();
            $this->audit('booked_depreciation', $asset, ['year' => $year, 'entry_id' => $entry->id]);

            return $entry;
        });

        return response()->json(['entry' => $this->serializeEntry($entry), 'already_booked' => false], 201);
    }

    /**
     * D-85: Dokumentation der fiktiven AfA im privaten Verzeichnis. Es wird
     * ausschließlich die Jahreszeile ergänzt — keine EurEntry, kein Aufwand,
     * keine Auswirkung auf den Gewinn. Idempotent über `eigenbeleg_created_at`.
     */
    private function bookPrivateEigenbeleg(EurAsset $asset, EurAssetYear $plan, int $year): JsonResponse
    {
        $result = $this->eigenbelegService->createForYear($asset, $plan);

        if (! $result['already_created']) {
            $this->audit('eigenbeleg_created', $asset, [
                'year' => $year,
                'eigenbeleg_no' => $plan->eigenbeleg_no,
                'eigenbeleg_cents' => $plan->eigenbeleg_cents,
            ]);
        }

        return response()->json($result, $result['already_created'] ? 200 : 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $asset = EurAsset::forCurrentBrand()->findOrFail($id);
        $asset->delete();
        $this->audit('deleted', $asset);

        return response()->json(['success' => true]);
    }

    /**
     * D-85: idempotenter Jahreswechsel — nur fehlende Jahreszeilen anlegen.
     */
    public function carryOver(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', Rule::in(EurYear::SUPPORTED)],
        ]);
        $year = (int) $data['year'];
        $created = (new EurAssetCarryOver)->carryOver($year, BrandRegistry::currentId());

        EurAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => 'carry_over',
            'subject_type' => 'eur_asset',
            'subject_id' => null,
            'payload' => ['year' => $year, 'created' => $created],
        ]);

        return response()->json(['year' => $year, 'created' => $created], $created > 0 ? 201 : 200);
    }

    /**
     * D-85: Beleg einmal am Stamm verknüpfen, nie pro Jahr kopieren.
     */
    public function attachDocument(Request $request, int $id): JsonResponse
    {
        $asset = EurAsset::forCurrentBrand()->findOrFail($id);
        $data = $request->validate(['document_id' => 'required|integer']);
        $document = EurDocument::forCurrentBrand()->findOrFail((int) $data['document_id']);

        $pivot = EurAssetDocument::firstOrCreate([
            'brand' => $asset->brand,
            'eur_asset_id' => $asset->id,
            'eur_document_id' => $document->id,
        ]);

        if ($pivot->wasRecentlyCreated) {
            $this->audit('document_attached', $asset, ['document_id' => $document->id]);
        }

        return response()->json([
            'document' => $this->serializeDocument($document),
            'already_linked' => ! $pivot->wasRecentlyCreated,
        ], $pivot->wasRecentlyCreated ? 201 : 200);
    }

    public function detachDocument(int $id, int $documentId): JsonResponse
    {
        $asset = EurAsset::forCurrentBrand()->findOrFail($id);
        $document = EurDocument::forCurrentBrand()->findOrFail($documentId);

        $deleted = EurAssetDocument::forCurrentBrand()
            ->where('eur_asset_id', $asset->id)
            ->where('eur_document_id', $document->id)
            ->delete();

        if ($deleted > 0) {
            $this->audit('document_detached', $asset, ['document_id' => $document->id]);
        }

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
            'business_share' => 'required|integer|min:0|max:100|multiple_of:5',
            'scope' => 'prohibited',
            'method' => ['required', Rule::in([DepreciationCalculator::METHOD_LINEAR, DepreciationCalculator::METHOD_DEGRESSIVE])],
            'degressive_rate' => 'nullable|integer|min:100|max:3000',
            'category' => 'nullable|string|max:190',
            'is_private_investment' => 'nullable|boolean',
            'is_used' => 'nullable|boolean',
            'is_immaterial' => 'nullable|boolean',
            'immaterial_exception' => ['nullable', 'string', Rule::in((new DegressiveRules)->allowedImmaterialExceptions())],
            'special_depreciation' => 'nullable|boolean',
            'is_electric_vehicle' => 'nullable|boolean',
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

        // DEG-006/007/008: die Ausschlüsse kommen aus den gepinnten Regeln;
        // die zulässigen immateriellen Ausnahmen aus dem DEG-Artefakt.
        if ($this->flag($data, 'is_used')) {
            abort(422, 'Degressive AfA ist für gebrauchte Wirtschaftsgüter nicht zulässig.');
        }

        if ($this->flag($data, 'is_immaterial')
            && ! (new DegressiveRules)->isAllowedImmaterialException($data['immaterial_exception'] ?? null)) {
            abort(422, 'Degressive AfA ist für immaterielle Wirtschaftsgüter nur mit einer dokumentierten Ausnahme zulässig.');
        }

        if ($this->flag($data, 'special_depreciation') && ! $this->flag($data, 'is_electric_vehicle')) {
            abort(422, 'Degressive AfA ist bei gesetzlicher Sonder-AfA nicht zulässig.');
        }
    }

    /**
     * HTML-Formulare liefern "1"/"0", JSON liefert echte Booleans.
     */
    private function flag(array $data, string $key): bool
    {
        return filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * DEG-013/014: nach gebuchten Jahren wird nur der unveränderte Restbuchwert
     * auf die verbleibenden Jahre verteilt. Gebuchte Zeilen bleiben unberührt.
     */
    private function writeRemainingLinearPlan(
        EurAsset $asset,
        DepreciationCalculator $calculator,
        int $carryingCents,
        int $remainingYears,
        int $lastBookedIndex,
    ): void {
        if ($remainingYears <= 0) {
            return;
        }

        $purchaseYear = (int) $asset->purchase_date->format('Y');
        $privatePercent = 100 - (int) $asset->business_share;

        foreach ($calculator->linearPlan($carryingCents, $remainingYears, 1) as $row) {
            $privateCents = Share::apply($row['depreciation_cents'], $privatePercent);

            EurAssetYear::create([
                'brand' => $asset->brand,
                'eur_asset_id' => $asset->id,
                'year' => $purchaseYear + $lastBookedIndex + $row['year'] - 1,
                'scope' => $asset->scope,
                'method' => DepreciationCalculator::METHOD_LINEAR,
                'depreciation_cents' => $row['depreciation_cents'],
                'book_value_cents' => $row['book_value_cents'],
                'private_use_percent' => $privatePercent,
                'private_use_cents' => $privateCents,
                'business_use_cents' => $row['depreciation_cents'] - $privateCents,
                'status' => 'planned',
            ]);
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

            $yearRow = EurAssetYear::create([
                'brand' => $asset->brand,
                'eur_asset_id' => $asset->id,
                'year' => $purchaseYear + $row['year'] - 1,
                'scope' => $asset->scope,
                'method' => $row['method'],
                'depreciation_cents' => $row['depreciation_cents'],
                'book_value_cents' => $row['book_value_cents'],
                'private_use_percent' => $privatePercent,
                'private_use_cents' => $privateCents,
                'business_use_cents' => $row['depreciation_cents'] - $privateCents,
                'status' => 'planned',
            ]);

            // D-85/EUE-055: im privaten Verzeichnis entsteht der Eigenbeleg
            // automatisch mit dem Plan — ohne Benutzeraktion und ohne Buchung.
            if ($asset->scope === 'private') {
                $this->eigenbelegService->createForYear($asset, $yearRow);
            }
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

    private function categoryCode(string $category): ?string
    {
        foreach (ReferenceTables::load()->expenseKinds() as $kind) {
            if (($kind['type'] ?? '') === $category
                && preg_match('/\A\d+/', (string) ($kind['e1a'] ?? ''), $matches) === 1) {
                return $matches[0];
            }
        }

        return preg_match('/\A\d+/', $category, $matches) === 1 ? $matches[0] : null;
    }

    private function businessCents(int $cents, int $share): int
    {
        return Share::apply($cents, $share);
    }

    /**
     * Kategorievorschlag aus dem gepinnten Referenzkatalog — nie hartcodiert.
     *
     * @return array{type: string, e1a: string|null, share_percent: int|null}
     */
    private function suggestedCategory(string $name): array
    {
        $categories = array_values(array_filter(
            ReferenceTables::load()->expenseKinds(),
            static fn (array $kind): bool => str_contains((string) ($kind['e1a'] ?? ''), '9130'),
        ));

        if ($categories === []) {
            return ['type' => 'Anlagegüter', 'e1a' => '9130', 'share_percent' => null];
        }

        $keywords = ['laptop' => 'Laptop', 'drucker' => 'Drucker', 'website' => 'Firmenwebsite', 'geringwertig' => 'Geringwertig'];
        $normalized = mb_strtolower($name);

        foreach ($keywords as $needle => $type) {
            if (str_contains($normalized, $needle)) {
                foreach ($categories as $category) {
                    if (str_contains(mb_strtolower((string) $category['type']), mb_strtolower($type))) {
                        return $category;
                    }
                }
            }
        }

        foreach ($categories as $category) {
            if (str_contains(mb_strtolower((string) $category['type']), 'andere anlagegüter')) {
                return $category;
            }
        }

        return $categories[0];
    }

    /**
     * Belegmetadaten ohne Dateipfad — `storage_path` und `signed_storage_path`
     * verlassen die API nie.
     *
     * @return array<string, mixed>
     */
    private function serializeDocument(EurDocument $document): array
    {
        return [
            'id' => (int) $document->id,
            'receipt_no' => $document->receipt_no,
            'original_name' => $document->original_name,
            'sha256' => $document->sha256,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEntry(EurEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'year' => (int) $entry->year,
            'month' => (int) $entry->month,
            'kind_type' => $entry->kind_type,
            'category' => $entry->category,
            'e1a_code' => $entry->e1a_code,
            'amount_cents' => (int) $entry->amount_cents,
            'business_amount_cents' => (int) $entry->business_amount_cents,
            'receipt_no' => $entry->receipt_no,
        ];
    }

    /**
     * EUE-053/058: die beiden AV-Hinweise aus dem gepinnten Regeltext.
     *
     * Die ODS zeigt ein ⚠ an, blockiert die Eingabe aber nicht — deshalb sind
     * die Hinweise Antwortfelder und keine 422-Fehler.
     *
     * @return list<array{code: string, text: string}>
     */
    private function warnings(EurAsset $asset): array
    {
        $warnings = [];
        $calculator = new DepreciationCalculator(new RuleValues);

        if ((int) $asset->useful_life_years > 1
            && ! in_array($this->categoryCode((string) $asset->category), $calculator->afaCodes(), true)) {
            $warnings[] = [
                'code' => 'EUE-053',
                'text' => '⚠ Ausgaben-Art muss zu einer AfA-Kennzahl zugeordnet sein',
            ];
        }

        if ($asset->purchase_date !== null && (int) $asset->purchase_date->format('Y') > EurYear::UPPER) {
            $warnings[] = [
                'code' => 'EUE-058',
                'text' => '⚠ Jahr liegt in der Zukunft',
            ];
        }

        // EUE-056/057: Vorjahres-AfA und Restbuchwert gegen den gepinnten Plan
        // prüfen. Die Toleranz kommt aus dem Regelkatalog, nicht aus dem Code.
        if ($asset->purchase_date !== null) {
            $rules = new RuleValues;
            $plausibility = new AssetPlausibility(
                $calculator,
                $rules->parameterFor('EUE-056', 'Toleranz AfA', EurYear::DEFAULT, 'cents'),
            );
            $purchaseYear = (int) $asset->purchase_date->format('Y');
            $years = $asset->relationLoaded('years') ? $asset->years : $asset->years()->get();

            array_push($warnings, ...$plausibility->warnings([
                'current_year' => EurYear::DEFAULT,
                'acquisition_year' => $purchaseYear,
                'acquisition_month' => (int) $asset->purchase_date->format('n'),
                'cost_cents' => (int) $asset->cost_cents,
                'useful_life_years' => (int) $asset->useful_life_years,
                'method' => (string) $asset->method,
                // DEG-009/014: ein vollzogener oder in den Jahreszeilen sichtbarer
                // Wechsel entzieht dem rekonstruierten Plan die Gültigkeit.
                'switched' => $asset->switched_to_linear_at !== null
                    || $years->pluck('method')->filter(static fn (mixed $method): bool => in_array($method, [
                        DepreciationCalculator::METHOD_LINEAR,
                        DepreciationCalculator::METHOD_DEGRESSIVE,
                    ], true))->unique()->count() > 1,
                'degressive_rate_per_10000' => (int) ($asset->degressive_rate ?? 0),
                // Ohne vollzogenen Wechsel gibt es kein Wechseljahr im Plan.
                'switch_year' => null,
                'years' => $years->map(static fn (EurAssetYear $year): array => [
                    'year' => (int) $year->year,
                    'depreciation_cents' => (int) $year->depreciation_cents,
                    'book_value_cents' => $year->book_value_cents === null ? null : (int) $year->book_value_cents,
                ])->all(),
            ]));
        }

        return $warnings;
    }

    /**
     * DEG-013: nicht blockierende Wechsel-Erinnerung. Der Hinweis kommt aus
     * demselben Calculator wie der Vergleichsrechner (DEG-011/012) und wird
     * aus den gespeicherten Werten abgeleitet — keine zweite Regelquelle.
     * `year` ist das absolute Kalenderjahr, weil `/assets/{id}/switch-to-linear`
     * dieses Format erwartet. Nach einem vollzogenen Wechsel verschwindet der
     * Hinweis; er ist ausdrücklich kein Fehler und blockiert keine Buchung.
     *
     * @return array{year: int, linear_cents: int, degressive_cents: int, advantage_cents: int}|null
     */
    private function switchHint(EurAsset $asset): ?array
    {
        if ($asset->method !== DepreciationCalculator::METHOD_DEGRESSIVE
            || $asset->switched_to_linear_at !== null
            || $asset->purchase_date === null) {
            return null;
        }

        $years = $asset->relationLoaded('years')
            ? $asset->years
            : $asset->years()->get();

        if ($years->contains(static fn (EurAssetYear $year): bool => $year->method === DepreciationCalculator::METHOD_LINEAR)) {
            return null;
        }

        $rate = (int) ($asset->degressive_rate ?? 0);

        if ($rate <= 0) {
            return null;
        }

        $calculator = new DepreciationCalculator(new RuleValues);
        $cost = (int) $asset->cost_cents;
        $life = (int) $asset->useful_life_years;
        $month = (int) $asset->purchase_date->format('n');
        $relativeYear = $calculator->recommendedSwitchYear($cost, $life, $rate, $month);

        if ($relativeYear === null) {
            return null;
        }

        $advantage = $calculator->switchAdvantageCents($cost, $life, $rate, $month, $relativeYear);

        if ($advantage <= 0) {
            return null;
        }

        $degressive = $calculator->degressivePlan($cost, $life, $rate, $month);
        $withSwitch = $calculator->degressivePlan($cost, $life, $rate, $month, $relativeYear);
        $index = $relativeYear - 1;

        return [
            'year' => (int) $asset->purchase_date->format('Y') + $relativeYear - 1,
            'linear_cents' => $withSwitch[$index]['depreciation_cents'] ?? 0,
            'degressive_cents' => $degressive[$index]['depreciation_cents'] ?? 0,
            'advantage_cents' => $advantage,
        ];
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
            'scope' => $asset->scope,
            'method' => $asset->method,
            'degressive_rate' => $asset->degressive_rate === null ? null : (int) $asset->degressive_rate,
            'category' => $asset->category,
            'is_private_investment' => (bool) $asset->is_private_investment,
            // DEG-006/007/008: Ausschlussmerkmale und die gespeicherte Ausnahme
            // werden angezeigt; die Prüfung selbst läuft nur beim Anlegen.
            'is_used' => (bool) $asset->is_used,
            'is_immaterial' => (bool) $asset->is_immaterial,
            'immaterial_exception' => $asset->immaterial_exception,
            'special_depreciation' => (bool) $asset->special_depreciation,
            'is_electric_vehicle' => (bool) $asset->is_electric_vehicle,
            'switched_to_linear_at' => $asset->switched_to_linear_at?->toIso8601String(),
            'switch_reason' => $asset->switch_reason,
            'status' => $asset->status,
            'warnings' => $this->warnings($asset),
            'switch_hint' => $this->switchHint($asset),
            'years' => $asset->years->map(fn (EurAssetYear $year): array => [
                'year' => (int) $year->year,
                'scope' => $year->scope,
                'carry_over_from_year' => $year->carry_over_from_year === null
                    ? null : (int) $year->carry_over_from_year,
                'method' => $year->method,
                'depreciation_cents' => (int) $year->depreciation_cents,
                'book_value_cents' => (int) $year->book_value_cents,
                'private_use_percent' => (int) $year->private_use_percent,
                'private_use_cents' => (int) $year->private_use_cents,
                'business_use_cents' => (int) $year->business_use_cents,
                'status' => $year->status,
                'eur_entry_id' => $year->eur_entry_id === null ? null : (int) $year->eur_entry_id,
                'booked_at' => $year->booked_at?->toIso8601String(),
                'eigenbeleg_no' => $year->eigenbeleg_no,
                'theoretical_depreciation_cents' => $year->theoretical_depreciation_cents === null
                    ? null : (int) $year->theoretical_depreciation_cents,
                'usage_percent' => $year->usage_percent === null ? null : (int) $year->usage_percent,
                'eigenbeleg_cents' => $year->eigenbeleg_cents === null ? null : (int) $year->eigenbeleg_cents,
                'eigenbeleg_created_at' => $year->eigenbeleg_created_at?->toIso8601String(),
            ])->values(),
            'documents' => $asset->assetDocuments
                ->map(fn (EurDocument $document): array => $this->serializeDocument($document))
                ->values(),
        ];
    }
}
