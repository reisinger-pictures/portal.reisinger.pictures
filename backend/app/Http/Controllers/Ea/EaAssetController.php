<?php

namespace App\Http\Controllers\Ea;

use App\Ea\Domain\AssetPlausibility;
use App\Ea\Domain\DepreciationCalculator;
use App\Ea\Domain\EaAssetCarryOver;
use App\Ea\Parity\RuleValues;
use App\Ea\Reference\DegressiveRules;
use App\Ea\Reference\EaYear;
use App\Ea\Reference\ReferenceTables;
use App\Ea\Support\Share;
use App\Http\Controllers\Controller;
use App\Models\EaAsset;
use App\Models\EaAssetDocument;
use App\Models\EaAssetGroup;
use App\Models\EaAssetYear;
use App\Models\EaAuditLog;
use App\Models\EaDocument;
use App\Models\EaEntry;
use App\Services\Ea\EaAssetCreationService;
use App\Services\Ea\EaAssetEigenbelegService;
use App\Services\Ea\EaAssetGroupService;
use App\Services\Ea\EaEntnahmeService;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * EAR · Anlagegüter und AfA (EUE-049 … EUE-061, DEG-001 … DEG-015).
 *
 * Der Jahresplan wird serverseitig aus dem gepinnten Regelkatalog berechnet
 * und als `ea_asset_years` festgehalten. Ein Wechsel zur linearen AfA ist
 * einmalig, nur ab dem zweiten Jahr und nur mit Begründung zulässig.
 */
class EaAssetController extends Controller
{
    public function __construct(
        private readonly EaAssetEigenbelegService $eigenbelegService,
        private readonly EaEntnahmeService $entnahmeService,
        private readonly EaAssetGroupService $groupService,
        private readonly EaAssetCreationService $creationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'private' => 'nullable|boolean',
            'scope' => 'nullable|in:business,private',
        ]);

        $query = EaAsset::forCurrentBrand()->with(['years', 'assetDocuments']);

        if (($data['private'] ?? false) === true || ($data['private'] ?? null) === '1') {
            $query->where('is_private_investment', true);
        }

        if (isset($data['scope'])) {
            $query->where('scope', $data['scope']);
        }

        $assets = $query->orderBy('name')->get();

        return response()->json([
            'assets' => $assets->map(fn (EaAsset $asset): array => $this->serialize($asset))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAsset($request);
        $data['brand'] = BrandRegistry::currentId();
        // D-85: die Einordnung ist serverseitig aus dem ganze-Prozent-Anteil
        // abgeleitet; ein Client kann `scope` nicht setzen (prohibited).
        // Portal #57: in einer Gruppe überschreibt der Gruppenanteil den des Guts.
        $groupId = isset($data['ea_asset_group_id']) ? (int) $data['ea_asset_group_id'] : null;
        $group = $groupId === null ? null : EaAssetGroup::forCurrentBrand()->findOrFail($groupId);
        // D-89 fail closed: auch das Anlegen eines Guts in eine Gruppe ändert
        // deren Mitgliedschaft und ist bei gebuchten Jahren unzulässig.
        if ($group !== null && $this->groupService->hasBookedYear($group)) {
            return response()->json(['error' => EaAssetGroupService::BOOKED_GROUP_MESSAGE], 422);
        }
        $effectiveShare = $group === null ? (int) $data['business_share'] : (int) $group->business_share;
        $data['scope'] = $effectiveShare < 50 ? 'private' : 'business';

        // Portal #59: derselbe Anlagepfad wie beim Beleg-Split (AfA-Plan,
        // Entnahme, Eigenbelege, Gruppenbuchung) — nur eine Regelquelle.
        $asset = $this->creationService->create($data, $group);

        $this->audit('created', $asset);

        return response()->json(['asset' => $this->serialize($asset->refresh())], 201);
    }

    public function plan(int $id): JsonResponse
    {
        $asset = EaAsset::forCurrentBrand()->with(['years', 'assetDocuments'])->findOrFail($id);

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
            'suggested_category' => $this->creationService->suggestedCategory((string) ($data['name'] ?? '')),
            'switch_recommended_year' => $switchYear,
            'switch_advantage_cents' => $calculator->switchAdvantageCents($cost, $life, $rate, $month),
        ]);
    }

    /**
     * DEG-009/DEG-014: einmaliger, begründeter Wechsel degressiv → linear.
     */
    public function switchToLinear(Request $request, int $id): JsonResponse
    {
        $asset = EaAsset::forCurrentBrand()->findOrFail($id);

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
                $query->whereNotNull('ea_entry_id')->orWhere('status', 'booked');
            })
            ->orderBy('year')
            ->get();
        $lastBookedIndex = $bookedYears->isEmpty()
            ? 0
            : max($bookedYears->map(fn (EaAssetYear $year): int => (int) $year->year - $purchaseYear + 1)->all());

        if ($relativeYear <= $lastBookedIndex) {
            return response()->json([
                'error' => 'Das Wechseljahr liegt in einem bereits gebuchten Jahr. Gebuchte AfA-Jahre bleiben unverändert.',
            ], 422);
        }

        $calculator = new DepreciationCalculator(new RuleValues);

        DB::transaction(function () use ($asset, $calculator, $relativeYear, $data, $bookedYears, $lastBookedIndex): void {
            if ($bookedYears->isEmpty()) {
                $asset->years()->delete();
                $this->creationService->writePlan($asset, $calculator, (int) $asset->purchase_date->format('n'), $relativeYear);
            } else {
                $lastBooked = $bookedYears->last();
                $remainingYears = (int) $asset->useful_life_years - $lastBookedIndex;
                $asset->years()->whereNull('ea_entry_id')->where('status', '!=', 'booked')->delete();
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
        $asset = EaAsset::forCurrentBrand()->findOrFail($id);
        $data = $request->validate(['year' => ['required', 'integer']]);

        try {
            EaYear::assertBookable((int) $data['year']);
        } catch (InvalidArgumentException $exception) {
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

        if ($plan->ea_entry_id !== null) {
            return response()->json([
                'entry' => $this->serializeEntry(EaEntry::forCurrentBrand()->findOrFail($plan->ea_entry_id)),
                'already_booked' => true,
            ]);
        }

        if ($plan->business_use_cents <= 0) {
            return response()->json(['error' => 'Für dieses Jahr ist keine betriebliche Abschreibung vorhanden.'], 422);
        }

        $entry = DB::transaction(function () use ($asset, $plan, $year): EaEntry {
            $code = $this->categoryCode((string) $asset->category);

            // Der Betrag ist bereits der Betriebsanteil (private_use wurde im
            // Plan abgezogen), daher wird er nicht erneut gekürzt.
            $entry = EaEntry::create([
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

            $plan->forceFill(['ea_entry_id' => $entry->id, 'booked_at' => now(), 'status' => 'booked'])->save();
            $this->audit('booked_depreciation', $asset, ['year' => $year, 'entry_id' => $entry->id]);

            return $entry;
        });

        return response()->json(['entry' => $this->serializeEntry($entry), 'already_booked' => false], 201);
    }

    /**
     * D-85: Dokumentation der fiktiven AfA im privaten Verzeichnis. Es wird
     * ausschließlich die Jahreszeile ergänzt — keine EaEntry, kein Aufwand,
     * keine Auswirkung auf den Gewinn. Idempotent über `eigenbeleg_created_at`.
     */
    private function bookPrivateEigenbeleg(EaAsset $asset, EaAssetYear $plan, int $year): JsonResponse
    {
        // Portal #57: in einer Gruppe entsteht der Eigenbeleg nur einmal für
        // die gesamte Gruppe, nicht je Mitglied.
        if ($asset->ea_asset_group_id !== null) {
            return response()->json(['error' => 'Der Eigenbeleg wird für die Anlagegruppe erstellt.'], 422);
        }

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
        $asset = EaAsset::forCurrentBrand()->findOrFail($id);
        $asset->delete();
        $this->audit('deleted', $asset);

        return response()->json(['success' => true]);
    }

    /**
     * D-85: idempotenter Jahreswechsel — nur fehlende Jahreszeilen anlegen.
     *
     * Der Jahreswechsel schreibt Jahreszeilen, keine Buchungen. Deshalb nimmt
     * er neben den Regeljahren 2024/2025 (bestehende Pläne, Vorjahresvergleiche)
     * auch jedes Buchungsjahr ab {@see EaYear::BOOKING_FROM} — 2027 ebenso wie
     * das laufende Jahr. `EaAssetCarryOver::planRow()` ist zieljahresunabhängig
     * (Planindex `targetYear - purchaseYear`, degressiver Satz am Anlagegut).
     */
    public function carryOver(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    EaYear::assert((int) $value);
                } catch (InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            }],
        ]);
        $year = (int) $data['year'];
        $created = (new EaAssetCarryOver)->carryOver($year, BrandRegistry::currentId());

        // Portal #57: private Gruppen bekommen genau EINEN Eigenbeleg je Jahr,
        // unabhängig von der Zahl ihrer Mitglieder. Der Horizont ist das
        // Zieljahr, damit die Gruppe ihre Zieljahres-Zeile erhält.
        foreach (EaAssetGroup::forCurrentBrand()->get() as $group) {
            $this->groupService->sync($group, $year);
        }

        EaAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => 'carry_over',
            'subject_type' => 'ea_asset',
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
        $asset = EaAsset::forCurrentBrand()->findOrFail($id);
        $data = $request->validate(['document_id' => 'required|integer']);
        $document = EaDocument::forCurrentBrand()->findOrFail((int) $data['document_id']);

        $pivot = EaAssetDocument::firstOrCreate([
            'brand' => $asset->brand,
            'ea_asset_id' => $asset->id,
            'ea_document_id' => $document->id,
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
        $asset = EaAsset::forCurrentBrand()->findOrFail($id);
        $document = EaDocument::forCurrentBrand()->findOrFail($documentId);

        $deleted = EaAssetDocument::forCurrentBrand()
            ->where('ea_asset_id', $asset->id)
            ->where('ea_document_id', $document->id)
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
            'ea_asset_group_id' => ['nullable', 'integer', Rule::exists('ea_asset_groups', 'id')->where('brand', BrandRegistry::currentId())],
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
     * DEG-013/014: nach gebuchten Jahren wird nur der unveränderte Restbuchwert
     * auf die verbleibenden Jahre verteilt. Gebuchte Zeilen bleiben unberührt.
     */
    private function writeRemainingLinearPlan(
        EaAsset $asset,
        DepreciationCalculator $calculator,
        int $carryingCents,
        int $remainingYears,
        int $lastBookedIndex,
    ): void {
        if ($remainingYears <= 0) {
            return;
        }

        $purchaseYear = (int) $asset->purchase_date->format('Y');
        // D-89: auch der nach einem Methodenwechsel neu geschriebene Restplan
        // folgt dem effektiven (ggf. vom Gruppenstamm kommenden) Anteil.
        $privatePercent = 100 - $asset->effectiveBusinessShare();

        foreach ($calculator->linearPlan($carryingCents, $remainingYears, 1) as $row) {
            $privateCents = Share::apply($row['depreciation_cents'], $privatePercent);

            EaAssetYear::create([
                'brand' => $asset->brand,
                'ea_asset_id' => $asset->id,
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

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $action, EaAsset $asset, array $extra = []): void
    {
        EaAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'ea_asset',
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
     * Belegmetadaten ohne Dateipfad — `storage_path` und `signed_storage_path`
     * verlassen die API nie.
     *
     * @return array<string, mixed>
     */
    private function serializeDocument(EaDocument $document): array
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
    private function serializeEntry(EaEntry $entry): array
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
    private function warnings(EaAsset $asset): array
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

        if ($asset->purchase_date !== null && (int) $asset->purchase_date->format('Y') > EaYear::currentOrLast()) {
            $warnings[] = [
                'code' => 'EUE-058',
                'text' => '⚠ Jahr liegt in der Zukunft',
            ];
        }

        // EUE-055: bei Privatanteil muss die Entnahme unter derselben
        // Belegnummer gebucht sein. Neue Anlagegüter bekommen sie automatisch;
        // der Hinweis bleibt für bestehende Güter ohne Entnahme.
        if ($asset->ea_asset_group_id === null
            && (int) $asset->business_share < 100
            && ! $this->entnahmeService->status($asset)['done']) {
            $warnings[] = [
                'code' => 'EUE-055',
                'text' => '⚠ Entnahme bei Anschaffung fehlt',
            ];
        }

        // EUE-056/057: Vorjahres-AfA und Restbuchwert gegen den gepinnten Plan
        // prüfen. Die Toleranz kommt aus dem Regelkatalog, nicht aus dem Code.
        if ($asset->purchase_date !== null) {
            $currentYear = EaYear::currentOrLast();
            $rules = new RuleValues;
            $plausibility = new AssetPlausibility(
                $calculator,
                $rules->parameterFor('EUE-056', 'Toleranz AfA', EaYear::LAST_PINNED, 'cents'),
            );
            $purchaseYear = (int) $asset->purchase_date->format('Y');
            $years = $asset->relationLoaded('years') ? $asset->years : $asset->years()->get();

            array_push($warnings, ...$plausibility->warnings([
                'current_year' => $currentYear,
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
                'years' => $years->map(static fn (EaAssetYear $year): array => [
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
    private function switchHint(EaAsset $asset): ?array
    {
        if ($asset->method !== DepreciationCalculator::METHOD_DEGRESSIVE
            || $asset->switched_to_linear_at !== null
            || $asset->purchase_date === null) {
            return null;
        }

        $years = $asset->relationLoaded('years')
            ? $asset->years
            : $asset->years()->get();

        if ($years->contains(static fn (EaAssetYear $year): bool => $year->method === DepreciationCalculator::METHOD_LINEAR)) {
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
    private function serialize(EaAsset $asset): array
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
            // Portal #57: die Abrechnungseinheit (Gruppe oder Einzelgut).
            'ea_asset_group_id' => $asset->ea_asset_group_id === null ? null : (int) $asset->ea_asset_group_id,
            // EUE-055: Entnahme bei Anschaffung — erledigt/nicht erledigt.
            'entnahme' => $asset->ea_asset_group_id === null
                ? $this->entnahmeService->status($asset)
                : ['done' => true, 'entry_id' => null, 'amount_cents' => null, 'group' => true],
            'switch_hint' => $this->switchHint($asset),
            'years' => $asset->years->map(fn (EaAssetYear $year): array => [
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
                'ea_entry_id' => $year->ea_entry_id === null ? null : (int) $year->ea_entry_id,
                'booked_at' => $year->booked_at?->toIso8601String(),
                'eigenbeleg_no' => $year->eigenbeleg_no,
                'theoretical_depreciation_cents' => $year->theoretical_depreciation_cents === null
                    ? null : (int) $year->theoretical_depreciation_cents,
                'usage_percent' => $year->usage_percent === null ? null : (int) $year->usage_percent,
                'eigenbeleg_cents' => $year->eigenbeleg_cents === null ? null : (int) $year->eigenbeleg_cents,
                'eigenbeleg_created_at' => $year->eigenbeleg_created_at?->toIso8601String(),
            ])->values(),
            'documents' => $asset->assetDocuments
                ->map(fn (EaDocument $document): array => $this->serializeDocument($document))
                ->values(),
        ];
    }
}
