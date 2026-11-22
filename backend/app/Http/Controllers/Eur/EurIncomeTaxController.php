<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Eur\Reference\IncomeTaxTariff;
use App\Http\Controllers\Controller;
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
    public function calculate(Request $request, EurTaxStatementService $statementService): JsonResponse
    {
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
            'prepayment_cents' => 'nullable|integer|min:0',
            'arbeitsplatzpauschale_cents' => 'nullable|integer|min:0',
            'ergonomisches_mobiliar_cents' => 'nullable|integer|min:0',
            'arbeitszimmer_cents' => 'nullable|integer|min:0',
        ]);

        $year = (int) ($data['year'] ?? EurYear::DEFAULT);
        // EUE-089 … EUE-093: Tarif und Freibetrag aus dem gewählten Jahr,
        // nicht aus dem auf 2026 gepinnten Referenzartefakt.
        $tariff = IncomeTaxTariff::forYear($year);
        $profit = (int) $data['profit_cents'];
        $allowance = $tariff->basicAllowanceCents($profit);
        $taxable = max(0, $profit - $allowance);

        [$e1a, $e1b] = $this->kennzahlen($data['entries'] ?? []);
        $mode = (string) ($data['mode'] ?? 'total');
        $extras = $statementService->build($year, $mode, $data['entries'] ?? [], $data, $allowance);

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
        ]);
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
