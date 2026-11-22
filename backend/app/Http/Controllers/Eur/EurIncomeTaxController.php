<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Eur\Reference\ReferenceTables;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EÜR · Einkommensteuer (EUE-089 … EUE-098) — Tarif und Grundfreibetrag.
 *
 * Der Endpunkt rechnet serverseitig mit ganzen Cent aus dem gepinnten
 * Referenzartefakt. Der Client rechnet nicht nach. Die Kennzahlen e1a (Einnahmen)
 * und e1b (Ausgaben) werden aus den übergebenen Buchungen abgeleitet; ohne
 * Buchungen sind sie Null. Der optionale Modus wählt die Berechnungsart, ohne
 * die bestehenden Antwortschlüssel zu verändern.
 */
class EurIncomeTaxController extends Controller
{
    public function calculate(Request $request): JsonResponse
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
            'mode' => 'nullable|in:total,business,rental,forecast',
            'entries' => 'nullable|array',
            'entries.*' => 'array',
        ]);

        $year = (int) ($data['year'] ?? EurYear::DEFAULT);
        $tariff = ReferenceTables::load()->incomeTax();
        $profit = (int) $data['profit_cents'];
        $allowance = $tariff->basicAllowanceCents($profit);
        $taxable = max(0, $profit - $allowance);

        [$e1a, $e1b] = $this->kennzahlen($data['entries'] ?? []);

        return response()->json([
            'year' => $year,
            'mode' => $data['mode'] ?? 'total',
            'profit_cents' => $profit,
            'basic_allowance_cents' => $allowance,
            'taxable_cents' => $taxable,
            'income_tax_cents' => $tariff->taxCents($taxable),
            'tax_free_threshold_cents' => $tariff->taxFreeThresholdCents(),
            'stages' => $tariff->stages(),
            'e1a' => $e1a,
            'e1b' => $e1b,
            'deductions_cents' => $e1b,
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
