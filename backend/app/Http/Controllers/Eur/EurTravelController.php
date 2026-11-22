<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\TravelCalculator;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\ReferenceTables;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EÜR · Reisekosten (EUE-062 … EUE-081) — Berechnung einer Reisezeile.
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
            'year' => 'required|integer|in:2026',
            'vehicle' => ['required', 'string', Rule::in(array_column($tables->vehicleTypes(), 'name'))],
            'kilometres' => 'required|integer|min:0|max:100000',
            'passengers' => 'nullable|integer|min:0|max:6',
            'country' => ['required', 'string', Rule::in($travel->countries())],
            'days' => 'required|integer|min:1|max:366',
            'meals' => 'required|integer|min:0|max:2',
            'with_receipts' => 'required|boolean',
            'business_share' => 'required|integer|min:0|max:100|multiple_of:5',
        ]);

        $calculator = new TravelCalculator(new RuleValues, $travel);

        $passengers = (int) ($data['passengers'] ?? 0);
        $kilometres = (int) $data['kilometres'];
        $days = (int) $data['days'];
        $meals = (int) $data['meals'];
        $withReceipts = (bool) $data['with_receipts'];
        $share = (int) $data['business_share'];

        $drive = $calculator->kilometreAllowanceCents($data['vehicle'], $kilometres, $passengers);
        $daily = $calculator->dailyAllowancesCents($data['country'], $days, $meals, $withReceipts);
        $total = $drive + $daily['day_cents'] + $daily['night_cents'];

        return response()->json([
            'year' => 2026,
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
