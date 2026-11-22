<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\ReferenceTables;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EÜR · Einkommensteuer (EUE-089 … EUE-098) — Tarif und Grundfreibetrag.
 *
 * Der Endpunkt rechnet serverseitig mit ganzen Cent aus dem gepinnten
 * Referenzartefakt. Der Client rechnet nicht nach.
 */
class EurIncomeTaxController extends Controller
{
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|in:2026',
            'profit_cents' => 'required|integer',
        ]);

        $tariff = ReferenceTables::load()->incomeTax();
        $profit = (int) $data['profit_cents'];
        $allowance = $tariff->basicAllowanceCents($profit);
        $taxable = max(0, $profit - $allowance);

        return response()->json([
            'year' => 2026,
            'profit_cents' => $profit,
            'basic_allowance_cents' => $allowance,
            'taxable_cents' => $taxable,
            'income_tax_cents' => $tariff->taxCents($taxable),
            'tax_free_threshold_cents' => $tariff->taxFreeThresholdCents(),
            'stages' => $tariff->stages(),
        ]);
    }
}
