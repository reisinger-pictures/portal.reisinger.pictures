<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\DepreciationCalculator;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\E1aHelpTexts;
use App\Eur\Reference\Holidays;
use App\Eur\Reference\ReferenceTables;
use App\Http\Controllers\Controller;
use App\Models\EurEntry;
use App\Models\EurProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EAR · Referenzdaten (EUE-004 … EUE-022) für die Oberfläche.
 *
 * Die Antwort ist die einzige Sicht auf die gepinnten Tabellen: Geld in
 * ganzen Cent, Anteile in ganzen Prozent, Sätze in Basispunkten. Es gibt
 * keine hart codierten Länder-, SV- oder Tarifwerte im Controller.
 */
class EurReferenceController extends Controller
{
    /**
     * Ausfüllhilfen aus dem gepinnten Artefakt — ohne Zellnachweis, ohne
     * Ableitung einer Berechnung. KZ 9225 erhält einen Portalseitigen
     * Doppelzählungs-Hinweis aus EUE-023/EUE-115.
     */
    public function helpTexts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kz' => 'nullable|string|regex:/^\d{4}$/',
        ]);

        $rows = E1aHelpTexts::all();

        if (isset($data['kz'])) {
            $row = E1aHelpTexts::forCode($data['kz']);

            return response()->json([
                'help' => $row === null ? null : $this->withPortalNote($row),
            ]);
        }

        return response()->json([
            'help' => array_map(fn (array $row): array => $this->withPortalNote($row), $rows),
        ]);
    }

    /**
     * @param  array{kz: string, bezeichnung: string, hilfetext: string}  $row
     * @return array{kz: string, bezeichnung: string, hilfetext: string, portal_note: string|null}
     */
    private function withPortalNote(array $row): array
    {
        return $row + [
            'portal_note' => $row['kz'] === '9225'
                ? 'Bei berechneter Sozialversicherung wird KZ 9225 nicht zusätzlich erfasst (EUE-023, EUE-115): keine Doppelzählung.'
                : null,
        ];
    }

    public function show(): JsonResponse
    {
        $tables = ReferenceTables::load();
        $travel = $tables->travel();
        $tariff = $tables->incomeTax();
        $social = $tables->socialInsurance();

        // EUE-029: Verwendungszähler je Art aus den Journalen des Brands.
        // Die Katalogliste bleibt unverändert; gezählt werden echte Buchungen.
        $usageCounts = ['expense' => [], 'income' => []];
        foreach (EurEntry::forCurrentBrand()
            ->selectRaw('direction, kind_type, COUNT(*) as usage_count')
            ->groupBy('direction', 'kind_type')
            ->get() as $row) {
            $direction = $row->direction === 'income' ? 'income' : 'expense';
            $usageCounts[$direction][(string) $row->kind_type] = (int) $row->usage_count;
        }

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
            'usage_counts' => $usageCounts,
            'projects' => $tables->projects(),
            // EUE-006: die statische Katalogliste `projects` bleibt für die
            // Anzeige erhalten; für die Buchung werden echte, brand-scope
            // Projekt-IDs benötigt (EurEntry::eur_project_id).
            'eur_projects' => EurProject::forCurrentBrand()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (EurProject $project): array => [
                    'id' => (int) $project->id,
                    'name' => (string) $project->name,
                ])
                ->all(),
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
            // EUE-051: die GWG-Grenze kommt aus dem gepinnten Katalog, damit
            // die UI nicht einen eigenen, irgendwann veralteten Wert pflegt.
            'assets' => [
                'gwg_limit_cents' => (new DepreciationCalculator(new RuleValues))->lowValueLimitCents(),
            ],
        ]);
    }
}
