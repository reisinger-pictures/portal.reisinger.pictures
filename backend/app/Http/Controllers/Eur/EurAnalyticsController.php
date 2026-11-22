<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use App\Models\EurEntry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * EÜR · Auswertungen (Portal #27).
 *
 * Alle Summen sind ganze Cent und entstehen aus dem betrieblichen Betrag, der
 * bereits an der Schreibgrenze berechnet wurde. Es gibt keine Float-Summe und
 * keine Nachrechnung im Client.
 */
class EurAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
        ]);

        $year = (int) ($data['year'] ?? EurYear::DEFAULT);

        $entries = EurEntry::forCurrentBrand()
            ->forYear($year)
            ->with(['customer', 'project'])
            ->get();

        return response()->json([
            'year' => $year,
            'by_customer' => $this->breakdown($entries, 'customer'),
            'by_project' => $this->breakdown($entries, 'project'),
            'by_month' => $this->byMonth($entries),
        ]);
    }

    /**
     * @param  Collection<int, EurEntry>  $entries
     * @return list<array{name: string, revenue_cents: int, cost_cents: int, margin_cents: int}>
     */
    private function breakdown($entries, string $relation): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $name = $entry->{$relation}?->name ?: ($relation === 'customer' ? 'Ohne Kunde' : 'Ohne Projekt');
            $amount = (int) $entry->business_amount_cents;

            $groups[$name] ??= ['name' => $name, 'revenue_cents' => 0, 'cost_cents' => 0];

            if ($entry->direction === 'income') {
                $groups[$name]['revenue_cents'] += $amount;
            } else {
                $groups[$name]['cost_cents'] += $amount;
            }
        }

        $rows = array_values($groups);

        foreach ($rows as &$row) {
            $row['margin_cents'] = $row['revenue_cents'] - $row['cost_cents'];
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => $b['revenue_cents'] <=> $a['revenue_cents'] ?: strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @param  Collection<int, EurEntry>  $entries
     * @return list<array{month: int, income_cents: int, expense_cents: int, profit_cents: int}>
     */
    private function byMonth($entries): array
    {
        $rows = [];

        for ($month = 1; $month <= 12; $month++) {
            $ofMonth = $entries->where('month', $month);
            $income = (int) $ofMonth->where('direction', 'income')->sum('business_amount_cents');
            $expense = (int) $ofMonth->where('direction', 'expense')->sum('business_amount_cents');

            $rows[] = [
                'month' => $month,
                'income_cents' => $income,
                'expense_cents' => $expense,
                'profit_cents' => $income - $expense,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, mixed>
     */
    private function yearRule(): array
    {
        return ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
            try {
                EurYear::assert((int) $value);
            } catch (InvalidArgumentException) {
                $fail(EurYear::warning((int) $value)['warning']);
            }
        }];
    }
}
