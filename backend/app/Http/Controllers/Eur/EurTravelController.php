<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\TravelCalculator;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\EurYear;
use App\Eur\Reference\ReferenceTables;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EAR · Reisekosten (EUE-062 … EUE-081) — Berechnung einer Reisezeile.
 *
 * Sätze, Kontingente und Grenzen kommen aus dem gepinnten Regelkatalog, die
 * Tag-/Nächtigungssätze aus der Ländertabelle. Alle Antworten sind ganze Cent.
 */
class EurTravelController extends Controller
{
    public function calculate(Request $request): JsonResponse
    {
        $tables = ReferenceTables::load();
        $travel = $tables->travel();

        $data = $request->validate([
            'year' => ['required', 'integer', Rule::in(EurYear::SUPPORTED)],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'vehicle' => ['required', 'string', Rule::in(array_column($tables->vehicleTypes(), 'name'))],
            'kilometres' => 'required|integer|min:0|max:100000',
            'passengers' => 'nullable|integer|min:0|max:6',
            'country' => ['required', 'string', Rule::in($travel->countries())],
            'days' => 'required|integer|min:1|max:366',
            'meals' => 'required|integer|min:0|max:2',
            'with_receipts' => 'required|boolean',
            'business_share' => 'required|integer|min:0|max:100|multiple_of:5',
        ]);

        // EUE-013: nur die Ländertabelle des gepinnten Wirtschaftsjahres liegt
        // im Referenzartefakt vor. Ohne diesen Gate würde ein 2024/2025-Jahr
        // eine unkontrollierte InvalidArgumentException und damit HTTP 500
        // auslösen. Die km-Sätze selbst unterstützen 2024–2026 (YearTables).
        $year = (int) $data['year'];

        if ($year !== ReferenceTables::BUSINESS_YEAR) {
            return response()->json([
                'message' => sprintf(
                    'Für das Reisejahr %d ist keine Ländertabelle gepinnt; verfügbar ist %d.',
                    $year,
                    ReferenceTables::BUSINESS_YEAR,
                ),
            ], 422);
        }

        $calculator = new TravelCalculator(new RuleValues, $travel);

        $passengers = (int) ($data['passengers'] ?? 0);
        $kilometres = (int) $data['kilometres'];
        $days = (int) $data['days'];
        $meals = (int) $data['meals'];
        $withReceipts = (bool) $data['with_receipts'];
        $share = (int) $data['business_share'];

        $month = isset($data['month']) ? (int) $data['month'] : null;
        $drive = $calculator->kilometreAllowanceCents($data['vehicle'], $kilometres, $passengers, $year, $month);
        $daily = $calculator->dailyAllowancesCents($data['country'], $days, $meals, $withReceipts, $year);
        $total = $drive + $daily['day_cents'] + $daily['night_cents'];

        return response()->json([
            'year' => (int) $data['year'],
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
        ]);
    }
}
