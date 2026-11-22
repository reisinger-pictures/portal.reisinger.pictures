<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Eur\Reference\ReferenceTables;
use App\Http\Controllers\Controller;
use App\Models\EurEntry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * EAR · Auswertungen (Portal #27).
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
     * EUE-119: Einnahmen-Art × Monat inkl. Summen und ✓✓-Abgleich gegen die
     * gebuchten Betriebseinnahmen.
     */
    public function byIncomeType(Request $request): JsonResponse
    {
        $year = $this->resolveYear($request);
        $entries = EurEntry::forCurrentBrand()->forYear($year)->get();

        return response()->json([
            'year' => $year,
            'kind' => 'income',
            ...$this->typeMatrix($entries, 'income'),
        ]);
    }

    /**
     * EUE-120: Ausgaben-Art × Monat mit A/AV/WEB/FR-Herkunft und ✓✓-Abgleich.
     */
    public function byExpenseType(Request $request): JsonResponse
    {
        $year = $this->resolveYear($request);
        $entries = EurEntry::forCurrentBrand()->forYear($year)->get();

        return response()->json([
            'year' => $year,
            'kind' => 'expense',
            ...$this->typeMatrix($entries, 'expense', true),
        ]);
    }

    /**
     * EUE-122: Zahlungsart × Monat — Startsaldo ± Zu-/Abflüsse = Endsaldo.
     */
    public function paymentReconciliation(Request $request): JsonResponse
    {
        $year = $this->resolveYear($request);
        $entries = EurEntry::forCurrentBrand()->forYear($year)->get();

        $rows = [];

        foreach (ReferenceTables::load()->paymentMethods() as $method) {
            $kind = $method['kind'];
            $opening = $method['opening_balance_cents'];
            $balance = $opening ?? 0;
            $months = [];
            $flows = 0;
            $negative = false;

            for ($month = 1; $month <= 12; $month++) {
                $ofMonth = $entries->where('month', $month)->where('payment_method', $kind);
                $in = (int) $ofMonth->where('direction', 'income')->sum('business_amount_cents');
                $out = (int) $ofMonth->where('direction', 'expense')->sum('business_amount_cents');
                $flows += $in + $out;
                $balance += $in - $out;
                $negative = $negative || $balance < 0;

                $months[] = [
                    'month' => $month,
                    'in_cents' => $in,
                    'out_cents' => $out,
                    'closing_cents' => $balance,
                ];
            }

            // ✓ = Buchungen vorhanden und kein negativer Saldo; ✓✓ = zusätzlich
            // ist der Startsaldo im gepinnten Artefakt ausgewiesen.
            $status = $flows === 0 || $negative
                ? ''
                : ($opening === null ? '✓' : '✓✓');

            $rows[] = [
                'method' => $kind,
                'opening_balance_cents' => $opening,
                'closing_balance_cents' => $balance,
                'status' => $status,
                'months' => $months,
            ];
        }

        return response()->json([
            'year' => $year,
            'methods' => $rows,
        ]);
    }

    /**
     * @param  Collection<int, EurEntry>  $entries
     * @return array<string, mixed>
     */
    private function typeMatrix(Collection $entries, string $direction, bool $withSources = false): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            if ($entry->direction !== $direction) {
                continue;
            }

            $name = trim((string) $entry->kind_type);

            if ($name === '') {
                $name = 'Ohne Art';
            }

            $groups[$name] ??= [
                'type' => $name,
                'months' => array_fill(1, 12, 0),
                'total_cents' => 0,
            ];

            if ($withSources) {
                $groups[$name]['sources'] = $groups[$name]['sources'] ?? ['A' => 0, 'AV' => 0, 'WEB' => 0, 'FR' => 0];
                $groups[$name]['sources'][$this->expenseSource((string) $entry->e1a_code)] += (int) $entry->business_amount_cents;
            }

            $amount = (int) $entry->business_amount_cents;
            $groups[$name]['months'][(int) $entry->month] += $amount;
            $groups[$name]['total_cents'] += $amount;
        }

        $types = array_values($groups);

        usort($types, static fn (array $a, array $b): int => $b['total_cents'] <=> $a['total_cents']
            ?: strcmp($a['type'], $b['type']));

        $monthTotals = array_fill(1, 12, 0);
        $total = 0;

        foreach ($types as $row) {
            foreach ($row['months'] as $month => $amount) {
                $monthTotals[$month] += $amount;
            }

            $total += $row['total_cents'];
        }

        $booked = (int) $entries->where('direction', $direction)->sum('business_amount_cents');

        return [
            'types' => $types,
            'month_totals' => $monthTotals,
            'total_cents' => $total,
            'booked_cents' => $booked,
            'delta_cents' => $total - $booked,
            'match' => $total === $booked,
        ];
    }

    /** A = sonstige Ausgaben, AV = AfA, WEB = Wareneingang, FR = Fahrt/Reise. */
    private function expenseSource(string $code): string
    {
        return match (true) {
            in_array($code, ['9130', '9134', '9135'], true) => 'AV',
            str_starts_with($code, '9100') => 'WEB',
            str_starts_with($code, '9160') => 'FR',
            default => 'A',
        };
    }

    private function resolveYear(Request $request): int
    {
        $data = $request->validate(['year' => $this->yearRule()]);

        return (int) ($data['year'] ?? EurYear::DEFAULT);
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
