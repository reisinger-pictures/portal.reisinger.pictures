<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\Holidays;
use App\Eur\Reference\ReferenceTables;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * EÜR · Referenzdaten (EUE-004 … EUE-022) für die Oberfläche.
 *
 * Die Antwort ist die einzige Sicht auf die gepinnten Tabellen: Geld in
 * ganzen Cent, Anteile in ganzen Prozent, Sätze in Basispunkten. Es gibt
 * keine hart codierten Länder-, SV- oder Tarifwerte im Controller.
 */
class EurReferenceController extends Controller
{
    public function show(): JsonResponse
    {
        $tables = ReferenceTables::load();
        $travel = $tables->travel();
        $tariff = $tables->incomeTax();
        $social = $tables->socialInsurance();

        return response()->json([
            'business_year' => $tables->businessYear(),
            'units' => [
                'money' => 'cents',
                'time' => 'minutes',
                'share' => 'percent',
                'rate' => 'per-10000',
            ],
            'expense_kinds' => $tables->expenseKinds(),
            'income_kinds' => $tables->incomeKinds(),
            'vat_kinds' => $tables->vatKinds(),
            'vehicle_types' => $tables->vehicleTypes(),
            'payment_methods' => $tables->paymentMethods(),
            'projects' => $tables->projects(),
            'travel' => [
                'countries' => array_map(
                    static fn (string $country): array => $travel->for($country, $tables->businessYear()),
                    $travel->countries(),
                ),
                'domestic' => $tables->domesticFlatRates(),
            ],
            'holidays' => Holidays::forYear($tables->businessYear()),
            'income_tax' => [
                'stages' => $tariff->stages(),
                'tax_free_threshold_cents' => $tariff->taxFreeThresholdCents(),
                'basic_allowance' => $tariff->basicAllowance(),
            ],
            'social_insurance' => [
                'amounts_cents' => $social->amountsCents(),
                'rates_per_10000' => $social->ratesPer10000(),
            ],
        ]);
    }
}
