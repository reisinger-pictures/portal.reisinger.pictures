<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\IncomeTaxService;
use App\Eur\Reference\EurYear;
use App\Eur\Reference\IncomeTaxTariff;
use App\Http\Controllers\Controller;
use App\Services\Eur\EurOverviewService;
use App\Services\Eur\EurTaxStatementService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EAR · Einkommensteuer (EUE-089 … EUE-098) — Tarif und Grundfreibetrag.
 *
 * Der Endpunkt rechnet serverseitig mit ganzen Cent aus dem gepinnten
 * Referenzartefakt. Der Client rechnet nicht nach. Die Kennzahlen e1a (Einnahmen)
 * und e1b (Ausgaben) werden aus den übergebenen Buchungen abgeleitet; ohne
 * Buchungen sind sie Null. Der optionale Modus wählt die Berechnungsart, ohne
 * die bestehenden Antwortschlüssel zu verändern.
 */
class EurIncomeTaxController extends Controller
{
    public function calculate(
        Request $request,
        EurTaxStatementService $statementService,
        EurOverviewService $overview,
    ): JsonResponse {
        $data = $request->validate([
            'year' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    EurYear::assert((int) $value);
                } catch (InvalidArgumentException) {
                    $fail(EurYear::warning((int) $value)['warning']);
                }
            }],
            'profit_cents' => 'required|integer',
            'mode' => 'nullable|in:'.implode(',', EurTaxStatementService::MODES),
            'entries' => 'nullable|array',
            'entries.*' => 'array',
            'employment_income_cents' => 'nullable|integer|min:0',
            'wage_tax_cents' => 'nullable|integer|min:0',
            'rental_forecast_cents' => 'nullable|integer|min:0',
            // EUE-104: Prognose-Flag und Prognose des Betriebsergebnisses.
            'prognose' => 'nullable|boolean',
            'business_forecast_cents' => 'nullable|integer|min:0',
            'prepayment_cents' => 'nullable|integer|min:0',
            'arbeitsplatzpauschale_cents' => 'nullable|integer|min:0',
            'ergonomisches_mobiliar_cents' => 'nullable|integer|min:0',
            'arbeitszimmer_cents' => 'nullable|integer|min:0',
            // EUE-126: optionale Monatswerte (Bruttoergebnis, ganze Minuten).
            'months' => 'nullable|array',
            'months.*' => 'array',
            'months.*.month' => 'required|integer|min:1|max:12',
            'months.*.gross_profit_cents' => 'required|integer',
            'months.*.hours_minutes' => 'nullable|integer|min:0',
        ]);

        $year = (int) ($data['year'] ?? EurYear::DEFAULT);
        // EUE-089 … EUE-093: Tarif und Freibetrag aus dem gewählten Jahr,
        // nicht aus dem auf 2026 gepinnten Referenzartefakt.
        $tariff = IncomeTaxTariff::forYear($year);
        // Reine Domäne ohne Container-Binding: der Tarif ist bereits geladen.
        $incomeTaxService = new IncomeTaxService($tariff);
        $profit = (int) $data['profit_cents'];
        $allowance = $tariff->basicAllowanceCents($profit);
        $taxable = max(0, $profit - $allowance);

        [$e1a, $e1b] = $this->kennzahlen($data['entries'] ?? []);
        $mode = (string) ($data['mode'] ?? 'total');
        $extras = $statementService->build($year, $mode, $data['entries'] ?? [], $data, $allowance);

        // EUE-095/103: die Kennzahlen U10…U13 und U26/U28 liegen bereits in
        // der Steuerbilanz bzw. der KZ-Map; die Aufteilung ist reine Domäne.
        $businessIncome = (int) ($extras['steuerbilanz']['steuerlicher_gewinn_cents'] ?? 0);
        $employmentIncome = (int) ($extras['kz']['245'] ?? 0);
        $rentalResult = (int) ($extras['kz']['U12'] ?? 0);
        $rentalForecast = (int) ($extras['kz']['U13'] ?? 0);
        $prepayment = (int) ($extras['kz']['U26'] ?? 0);
        $wageTax = (int) ($extras['kz']['260'] ?? 0);

        $totalIncome = $incomeTaxService->totalIncomeCents(
            $mode,
            $businessIncome,
            $employmentIncome,
            $rentalResult,
            $rentalForecast,
        );
        $allocation = $incomeTaxService->allocate(
            $mode,
            $totalIncome,
            $employmentIncome,
            $rentalResult,
            $prepayment,
            $wageTax,
        );

        $bookedSv = 0;

        foreach ($data['entries'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            // EUE-115: das Journal kennt `code` und `e1a_code`; die 9225 darf
            // nicht je nach Feldname übersehen oder doppelt gezählt werden.
            foreach ([$entry['code'] ?? '', $entry['e1a_code'] ?? ''] as $code) {
                if (str_starts_with((string) $code, '9225')) {
                    $bookedSv += (int) ($entry['amount_cents'] ?? 0);
                    break;
                }
            }
        }

        $monthlyResults = $this->monthlyResults(
            $incomeTaxService,
            $overview,
            $year,
            $mode,
            $businessIncome,
            $bookedSv,
            $allocation,
            $data['months'] ?? [],
        );

        return response()->json([
            'year' => $year,
            'mode' => $mode,
            'profit_cents' => $profit,
            'basic_allowance_cents' => $allowance,
            'taxable_cents' => $taxable,
            'income_tax_cents' => $tariff->taxCents($taxable),
            'tax_free_threshold_cents' => $tariff->taxFreeThresholdCents(),
            'stages' => $tariff->stages(),
            'e1a' => $e1a,
            'e1b' => $e1b,
            'deductions_cents' => $e1b,
            'kz' => $extras['kz'],
            'steuerbilanz' => $extras['steuerbilanz'],
            'e1a_e1b' => $extras['e1a_e1b'],
            // Additive Felder: bestehende Antwortschlüssel bleiben unverändert.
            'est_allocation' => $allocation,
            'monthlyResults' => $monthlyResults,
        ]);
    }

    /**
     * EUE-126: Jahresbelastung und Mindest-Sockel aus der gepinnten SV-Engine
     * (EurOverviewService) ableiten und die Monatswerte an die reine Domäne
     * übergeben. Ohne Monatsdaten bleibt die Struktur vollständig, aber null.
     *
     * @param  array<string, int|null>  $allocation
     * @param  array<mixed>  $monthsInput
     * @return array<string, mixed>
     */
    private function monthlyResults(
        IncomeTaxService $incomeTaxService,
        EurOverviewService $overview,
        int $year,
        string $mode,
        int $businessIncome,
        int $bookedSv,
        array $allocation,
        array $monthsInput,
    ): array {
        $months = [];

        foreach ($monthsInput as $row) {
            if (! is_array($row) || ! isset($row['month']) || ! is_int($row['month'])) {
                continue;
            }

            $months[$row['month']] = [
                'gross_profit_cents' => (int) ($row['gross_profit_cents'] ?? 0),
                'hours_minutes' => (int) ($row['hours_minutes'] ?? 0),
            ];
        }

        if ($months === []) {
            $rows = [];

            for ($month = 1; $month <= 12; $month++) {
                $rows[] = [
                    'month' => $month,
                    'gross_profit_cents' => null,
                    'hours_minutes' => null,
                    'est_sv_cents' => null,
                    'net_profit_cents' => null,
                    'net_per_hour_cents' => null,
                    'basis_cents' => null,
                    'loss_basis_cents' => null,
                    'minimum_sv_cents' => null,
                ];
            }

            return ['year' => $year, 'mode' => $mode, 'months' => $rows, 'annual' => null];
        }

        // EUE-114: Jahres-SV (AA17) ist die Summe der zwölf Monatsbeiträge.
        $annualSv = 0;

        foreach ($overview->svMonthly($year, $businessIncome, $bookedSv, $mode) as $row) {
            $annualSv += (int) $row['kv_cents'] + (int) $row['pv_cents']
                + (int) $row['svs_cents'] + (int) $row['uv_cents'];
        }

        // EUE-125: I12 ist der Monats-Sockel bei leerer Beitragsgrundlage.
        $floor = $overview->svMonthly($year, 0, 0, $mode)[0] ?? [];
        $minimumSv = (int) ($floor['kv_cents'] ?? 0) + (int) ($floor['pv_cents'] ?? 0)
            + (int) ($floor['svs_cents'] ?? 0) + (int) ($floor['uv_cents'] ?? 0);

        $result = $incomeTaxService->monthlyResults($year, $mode, [
            'tax_cents' => (int) ($allocation['tax_cents'] ?? 0),
            'employment_tax_cents' => (int) ($allocation['employment_tax_cents'] ?? 0),
            'business_tax_cents' => (int) ($allocation['business_tax_cents'] ?? 0),
            'rental_tax_cents' => (int) ($allocation['rental_tax_cents'] ?? 0),
            'sv_cents' => $annualSv,
            'minimum_sv_cents' => $minimumSv,
        ], $months);

        return ['year' => $year, 'mode' => $mode] + $result;
    }

    /**
     * @param  array<mixed>  $entries
     * @return array{0: int, 1: int}
     */
    private function kennzahlen(array $entries): array
    {
        $e1a = 0;
        $e1b = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $amount = (int) ($entry['business_amount_cents'] ?? $entry['amount_cents'] ?? 0);

            if (($entry['direction'] ?? null) === 'income') {
                $e1a += $amount;
            } elseif (($entry['direction'] ?? null) === 'expense') {
                $e1b += $amount;
            }
        }

        return [$e1a, $e1b];
    }
}
