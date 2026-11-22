<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\VatCalculator;
use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EÜR · Umsatzsteuer (EUE-082 … EUE-088) — Monatsauswertung.
 *
 * Der Endpunkt rechnet ausschließlich mit den übergebenen ganzen Cent und
 * liefert die acht Kennzahlen plus Zahllast. Leere Eingaben bleiben leer.
 */
class EurVatController extends Controller
{
    public function calculate(Request $request, VatCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', Rule::in(EurYear::SUPPORTED)],
            'month' => 'required|integer|min:1|max:12',
            'small_business_income_cents' => 'nullable|integer|min:0',
            'reverse_charge_cents' => 'nullable|integer|min:0',
            'ig_bases_cents' => 'nullable|array',
            'ig_bases_cents.20' => 'nullable|integer|min:0',
            'ig_bases_cents.10' => 'nullable|integer|min:0',
            'ig_bases_cents.13' => 'nullable|integer|min:0',
            'ig_bases_cents.49' => 'nullable|integer|min:0',
        ]);

        $bases = [];

        foreach ([20, 10, 13, 49] as $rate) {
            $value = $data['ig_bases_cents'][(string) $rate] ?? null;
            $bases[$rate] = $value === null ? null : (int) $value;
        }

        $result = $calculator->forMonth([
            'small_business_income_cents' => isset($data['small_business_income_cents'])
                ? (int) $data['small_business_income_cents']
                : null,
            'reverse_charge_cents' => isset($data['reverse_charge_cents'])
                ? (int) $data['reverse_charge_cents']
                : null,
            'ig_bases_cents' => $bases,
        ]);

        return response()->json([
            'year' => (int) $data['year'],
            'month' => (int) $data['month'],
            'kz' => $result,
        ]);
    }
}
