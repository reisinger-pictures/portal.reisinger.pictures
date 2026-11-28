<?php

namespace App\Http\Controllers\Ea;

use App\Ea\Domain\TravelCalculator;
use App\Ea\Parity\RuleValues;
use App\Ea\Reference\EaYear;
use App\Ea\Reference\ReferenceTables;
use App\Http\Controllers\Controller;
use App\Models\EaAuditLog;
use App\Models\EaTravelTrip;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * EAR · Reisekosten (EUE-062 … EUE-081, D-83a).
 *
 * Sätze, Kontingente und Grenzen kommen aus dem gepinnten Regelkatalog, die
 * Tag-/Nächtigungssätze aus der Ländertabelle. Alle Antworten sind ganze Cent.
 * `calc` bleibt die reine Berechnung; `store` speichert dieselbe serverseitig
 * berechnete Reisezeile, damit die Domain-Einheiten einen Produktpfad haben.
 */
class EaTravelController extends Controller
{
    /** EUE-073: die ODS verwendet genau diese Bezeichnung der Veranlassung. */
    private const PURPOSE_PRIVATE = 'Privat';

    private const PURPOSE_BUSINESS = 'Geschäftlich';

    /** EUE-081: Reisekennzahl im Ausgabenjournal. */
    private const TRAVEL_E1A_CODE = '9160';

    /** EUE-073: gespeicherte Veranlassung privat → nicht abziehbar. */
    private static function isPrivatePurpose(?string $purpose): bool
    {
        return $purpose !== null && mb_strtolower(trim($purpose), 'UTF-8') === 'privat';
    }

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
            'year' => $this->yearRule(),
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $query = EaTravelTrip::forCurrentBrand();

        if (isset($data['year'])) {
            $query->where('year', (int) $data['year']);
        }

        if (isset($data['month'])) {
            $query->where('month', (int) $data['month']);
        }

        $trips = $query->orderBy('year')->orderBy('month')->orderBy('id')->get();

        return response()->json([
            'trips' => $trips->map(fn (EaTravelTrip $trip): array => $this->serialize($trip))->values(),
            'total_cents' => (int) $trips->sum('total_cents'),
            'business_cents' => (int) $trips->sum('business_cents'),
        ]);
    }

    /** Speichert eine serverseitig berechnete Reisezeile (D-83a). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge($this->travelRules(), [
            'receipt_no' => 'required|string|max:64',
            'note' => 'nullable|string|max:500',
        ]));
        $gate = $this->countryTableGate((int) $data['year']);

        if ($gate !== null) {
            return $gate;
        }

        // EUE-077: eine Belegnummer gehört zu genau einer Reise des Brands.
        if (EaTravelTrip::forCurrentBrand()->where('receipt_no', trim((string) $data['receipt_no']))->exists()) {
            return response()->json([
                'message' => 'Diese Belegnummer ist bereits für eine andere Reise vergeben.',
            ], 422);
        }

        $result = $this->compute($data);
        $trip = EaTravelTrip::create([
            'brand' => BrandRegistry::currentId(),
            'year' => $result['year'],
            'month' => isset($data['month']) ? (int) $data['month'] : null,
            'receipt_no' => trim((string) $data['receipt_no']),
            'start_km' => isset($data['start_km']) ? (int) $data['start_km'] : null,
            'end_km' => isset($data['end_km']) ? (int) $data['end_km'] : null,
            'purpose' => $data['purpose'] ?? null,
            'e1a_code' => self::TRAVEL_E1A_CODE,
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
        $trip = EaTravelTrip::forCurrentBrand()->findOrFail($id);
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
            'year' => $this->yearRule(true),
            'month' => 'nullable|integer|min:1|max:12',
            'vehicle' => ['required', 'string', Rule::in(array_column($tables->vehicleTypes(), 'name'))],
            // EUE-067: entweder die Distanz oder Start-/Endstand, nie beides.
            'kilometres' => ['nullable', 'integer', 'min:0', 'max:1000000', 'required_without:start_km', 'prohibits:start_km,end_km'],
            'start_km' => ['nullable', 'integer', 'min:0', 'prohibits:kilometres', 'required_with:end_km'],
            'end_km' => ['nullable', 'integer', 'min:0', 'prohibits:kilometres', 'required_with:start_km', 'gt:start_km'],
            'passengers' => 'nullable|integer|min:0|max:6',
            'country' => ['required', 'string', Rule::in($travel->countries())],
            'purpose' => ['nullable', 'string', Rule::in([self::PURPOSE_BUSINESS, self::PURPOSE_PRIVATE])],
            'receipt_no' => 'nullable|string|max:64',
            'days' => 'required|integer|min:1|max:366',
            'meals' => 'required|integer|min:0|max:2',
            'with_receipts' => 'required|boolean',
            'business_share' => 'required|integer|min:0|max:100|multiple_of:5',
        ];
    }

    /**
     * Buchungsjahr: ab 2026, nach oben offen (Fallback auf das letzte
     * Regeljahr). 2024 und 2025 sind reine Regeljahre und kein Buchungsjahr.
     *
     * @return array<int, mixed>
     */
    private function yearRule(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
            try {
                EaYear::assertBookable((int) $value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        }];
    }

    /**
     * EUE-013: nur die Ländertabelle des gepinnten Wirtschaftsjahres liegt im
     * Referenzartefakt vor. Ein späteres Buchungsjahr liest sie über den
     * Fallback (`EaYear::effective()`); 2024/2025 sind kein Buchungsjahr und
     * scheitern bereits an der Jahresregel.
     */
    private function countryTableGate(int $year): ?JsonResponse
    {
        if (EaYear::effective($year) === ReferenceTables::BUSINESS_YEAR) {
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
     * @return array<string, int|bool|string|null>
     */
    private function compute(array $data): array
    {
        $calculator = new TravelCalculator(new RuleValues, ReferenceTables::load()->travel());

        $passengers = (int) ($data['passengers'] ?? 0);
        $kilometres = isset($data['start_km'], $data['end_km'])
            ? (int) $data['end_km'] - (int) $data['start_km']
            : (int) $data['kilometres'];
        $purpose = isset($data['purpose']) ? (string) $data['purpose'] : null;
        $isPrivate = self::isPrivatePurpose($purpose);
        $days = (int) $data['days'];
        $meals = (int) $data['meals'];
        $withReceipts = (bool) $data['with_receipts'];
        $share = (int) $data['business_share'];
        $year = (int) $data['year'];
        $month = isset($data['month']) ? (int) $data['month'] : null;

        $drive = $calculator->kilometreAllowanceCents($data['vehicle'], $kilometres, $passengers, $year, $month);
        $daily = $calculator->dailyAllowancesCents($data['country'], $days, $meals, $withReceipts, $year);
        $total = $drive + $daily['day_cents'] + $daily['night_cents'];

        // EUE-073: bei privater Veranlassung bleibt die Reise in der Liste
        // sichtbar, der betriebliche (abziehbare) Anteil wird aber auf 0
        // gesetzt — genau wie FR!BA3/AT3 der ODS.
        $businessCents = $isPrivate
            ? 0
            : $calculator->businessTravelCostsCents(
                $data['vehicle'],
                $kilometres,
                $passengers,
                $data['country'],
                $days,
                $meals,
                $withReceipts,
                $share,
            );

        return [
            'year' => $year,
            'kilometres' => $kilometres,
            'kilometre_cents' => $drive,
            'day_cents' => $daily['day_cents'],
            'night_cents' => $daily['night_cents'],
            'total_cents' => $total,
            'business_cents' => $businessCents,
            'deductible' => ! $isPrivate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EaTravelTrip $trip): array
    {
        return [
            'id' => (int) $trip->id,
            'year' => (int) $trip->year,
            'month' => $trip->month === null ? null : (int) $trip->month,
            'receipt_no' => $trip->receipt_no,
            'vehicle' => (string) $trip->vehicle,
            'kilometres' => (int) $trip->kilometres,
            'start_km' => $trip->start_km === null ? null : (int) $trip->start_km,
            'end_km' => $trip->end_km === null ? null : (int) $trip->end_km,
            'passengers' => (int) $trip->passengers,
            'country' => (string) $trip->country,
            'purpose' => $trip->purpose,
            'e1a_code' => $trip->e1a_code,
            'deductible' => ! self::isPrivatePurpose($trip->purpose),
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

    private function audit(string $action, EaTravelTrip $trip): void
    {
        EaAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'ea_travel_trip',
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
