<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\TravelCalculator;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\EurYear;
use App\Eur\Reference\ReferenceTables;
use App\Http\Controllers\Controller;
use App\Models\EurAuditLog;
use App\Models\EurTravelTrip;
use App\Support\BrandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EAR · Reisekosten (EUE-062 … EUE-081, D-83a).
 *
 * Sätze, Kontingente und Grenzen kommen aus dem gepinnten Regelkatalog, die
 * Tag-/Nächtigungssätze aus der Ländertabelle. Alle Antworten sind ganze Cent.
 * `calc` bleibt die reine Berechnung; `store` speichert dieselbe serverseitig
 * berechnete Reisezeile, damit die Domain-Einheiten einen Produktpfad haben.
 */
class EurTravelController extends Controller
{
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate($this->travelRules());
        $gate = $this->countryTableGate((int) $data['year']);

        return $gate ?? response()->json($this->compute($data));
    }

    /** Brand-scoped Liste gespeicherter Reisen (D-83a). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', Rule::in(EurYear::SUPPORTED)],
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $query = EurTravelTrip::forCurrentBrand();

        if (isset($data['year'])) {
            $query->where('year', (int) $data['year']);
        }

        if (isset($data['month'])) {
            $query->where('month', (int) $data['month']);
        }

        $trips = $query->orderBy('year')->orderBy('month')->orderBy('id')->get();

        return response()->json([
            'trips' => $trips->map(fn (EurTravelTrip $trip): array => $this->serialize($trip))->values(),
            'total_cents' => (int) $trips->sum('total_cents'),
            'business_cents' => (int) $trips->sum('business_cents'),
        ]);
    }

    /** Speichert eine serverseitig berechnete Reisezeile (D-83a). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->travelRules() + ['note' => 'nullable|string|max:500']);
        $gate = $this->countryTableGate((int) $data['year']);

        if ($gate !== null) {
            return $gate;
        }

        $result = $this->compute($data);
        $trip = EurTravelTrip::create([
            'brand' => BrandRegistry::currentId(),
            'year' => $result['year'],
            'month' => isset($data['month']) ? (int) $data['month'] : null,
            'vehicle' => (string) $data['vehicle'],
            'kilometres' => $result['kilometres'],
            'passengers' => (int) ($data['passengers'] ?? 0),
            'country' => (string) $data['country'],
            'days' => (int) $data['days'],
            'meals' => (int) $data['meals'],
            'with_receipts' => (bool) $data['with_receipts'],
            'business_share' => (int) $data['business_share'],
            'kilometre_cents' => $result['kilometre_cents'],
            'day_cents' => $result['day_cents'],
            'night_cents' => $result['night_cents'],
            'total_cents' => $result['total_cents'],
            'business_cents' => $result['business_cents'],
            'note' => $data['note'] ?? null,
        ]);

        $this->audit('created', $trip);

        return response()->json(['trip' => $this->serialize($trip)], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $trip = EurTravelTrip::forCurrentBrand()->findOrFail($id);
        $trip->delete();
        $this->audit('deleted', $trip);

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function travelRules(): array
    {
        $tables = ReferenceTables::load();
        $travel = $tables->travel();

        return [
            'year' => ['required', 'integer', Rule::in(EurYear::SUPPORTED)],
            'month' => 'nullable|integer|min:1|max:12',
            'vehicle' => ['required', 'string', Rule::in(array_column($tables->vehicleTypes(), 'name'))],
            'kilometres' => 'required|integer|min:0|max:100000',
            'passengers' => 'nullable|integer|min:0|max:6',
            'country' => ['required', 'string', Rule::in($travel->countries())],
            'days' => 'required|integer|min:1|max:366',
            'meals' => 'required|integer|min:0|max:2',
            'with_receipts' => 'required|boolean',
            'business_share' => 'required|integer|min:0|max:100|multiple_of:5',
        ];
    }

    /**
     * EUE-013: nur die Ländertabelle des gepinnten Wirtschaftsjahres liegt im
     * Referenzartefakt vor. Ohne diesen Gate würde ein 2024/2025-Jahr eine
     * unkontrollierte InvalidArgumentException und damit HTTP 500 auslösen.
     * Die km-Sätze selbst unterstützen 2024–2026 (YearTables).
     */
    private function countryTableGate(int $year): ?JsonResponse
    {
        if ($year === ReferenceTables::BUSINESS_YEAR) {
            return null;
        }

        return response()->json([
            'message' => sprintf(
                'Für das Reisejahr %d ist keine Ländertabelle gepinnt; verfügbar ist %d.',
                $year,
                ReferenceTables::BUSINESS_YEAR,
            ),
        ], 422);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    private function compute(array $data): array
    {
        $calculator = new TravelCalculator(new RuleValues, ReferenceTables::load()->travel());

        $passengers = (int) ($data['passengers'] ?? 0);
        $kilometres = (int) $data['kilometres'];
        $days = (int) $data['days'];
        $meals = (int) $data['meals'];
        $withReceipts = (bool) $data['with_receipts'];
        $share = (int) $data['business_share'];
        $year = (int) $data['year'];
        $month = isset($data['month']) ? (int) $data['month'] : null;

        $drive = $calculator->kilometreAllowanceCents($data['vehicle'], $kilometres, $passengers, $year, $month);
        $daily = $calculator->dailyAllowancesCents($data['country'], $days, $meals, $withReceipts, $year);
        $total = $drive + $daily['day_cents'] + $daily['night_cents'];

        return [
            'year' => $year,
            'kilometres' => $kilometres,
            'kilometre_cents' => $drive,
            'day_cents' => $daily['day_cents'],
            'night_cents' => $daily['night_cents'],
            'total_cents' => $total,
            'business_cents' => $calculator->businessTravelCostsCents(
                $data['vehicle'],
                $kilometres,
                $passengers,
                $data['country'],
                $days,
                $meals,
                $withReceipts,
                $share,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EurTravelTrip $trip): array
    {
        return [
            'id' => (int) $trip->id,
            'year' => (int) $trip->year,
            'month' => $trip->month === null ? null : (int) $trip->month,
            'vehicle' => (string) $trip->vehicle,
            'kilometres' => (int) $trip->kilometres,
            'passengers' => (int) $trip->passengers,
            'country' => (string) $trip->country,
            'days' => (int) $trip->days,
            'meals' => (int) $trip->meals,
            'with_receipts' => (bool) $trip->with_receipts,
            'business_share' => (int) $trip->business_share,
            'kilometre_cents' => (int) $trip->kilometre_cents,
            'day_cents' => (int) $trip->day_cents,
            'night_cents' => (int) $trip->night_cents,
            'total_cents' => (int) $trip->total_cents,
            'business_cents' => (int) $trip->business_cents,
            'note' => $trip->note,
        ];
    }

    private function audit(string $action, EurTravelTrip $trip): void
    {
        EurAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'eur_travel_trip',
            'subject_id' => $trip->id,
            'payload' => [
                'year' => (int) $trip->year,
                'month' => $trip->month === null ? null : (int) $trip->month,
                'total_cents' => (int) $trip->total_cents,
                'business_cents' => (int) $trip->business_cents,
            ],
        ]);
    }
}
