<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\VatCalculator;
use App\Eur\Domain\VatReturnService;
use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use App\Services\Eur\EurVatDeclarationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EAR · Umsatzsteuer (EUE-082 … EUE-088) — Monatsauswertung.
 *
 * Der Endpunkt rechnet ausschließlich mit den übergebenen ganzen Cent und
 * liefert die acht Kennzahlen plus Zahllast. Leere Eingaben bleiben leer.
 * Ergänzend kommen die Quartals-/Jahresgruppen und die beiden Erklärungs-
 * blöcke aus EUE-088 hinzu; alle bisherigen Antwortschlüssel bleiben
 * unverändert.
 */
class EurVatController extends Controller
{
    public function calculate(
        Request $request,
        VatCalculator $calculator,
        EurVatDeclarationService $declarationService,
    ): JsonResponse {
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

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $groups = VatReturnService::groupMonth($month, $result);

        return response()->json([
            'year' => $year,
            'month' => $month,
            'kz' => $result,
            'zahllast_cents' => $result['zahllast'],
            'quarters' => $groups['quarters'],
            'year_totals' => $groups['year_totals'],
            'erklaerung' => $this->declarations($declarationService, $year, $result, $groups['year_totals']),
        ]);
    }

    /**
     * EUE-088: beide Blöcke haben dieselbe Kennzahlenstruktur, aber
     * unterschiedliche Werte — die Voranmeldung den Monat, die Erklärung
     * die Jahressummen.
     *
     * @param  array<string, int|null>  $result
     * @param  array<string, int|null>  $yearTotals
     * @return array<string, array<string, mixed>>
     */
    private function declarations(
        EurVatDeclarationService $service,
        int $year,
        array $result,
        array $yearTotals,
    ): array {
        $blocks = $service->blocks($year);

        $blocks['voranmeldung']['kz'] = $result;
        $blocks['voranmeldung']['zahllast_cents'] = $result['zahllast'] ?? null;
        $blocks['erklaerung']['kz'] = $yearTotals;
        $blocks['erklaerung']['zahllast_cents'] = $yearTotals['zahllast'] ?? null;

        return $blocks;
    }
}
