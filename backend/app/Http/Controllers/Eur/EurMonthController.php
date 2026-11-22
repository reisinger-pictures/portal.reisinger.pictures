<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\JournalRules;
use App\Eur\Domain\MonthStatement;
use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use App\Models\EurAuditLog;
use App\Models\EurEntry;
use App\Models\EurHour;
use App\Models\EurMonthlyReport;
use App\Models\EurSignature;
use App\Models\EurWarehouseEntry;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EÜR · Monatsansicht und Monatsabschluss (Portal #27).
 *
 * Die reine Domäne `MonthStatement` baut die Antwort aus den übergebenen
 * Arrays; der Controller liefert ausschließlich Daten und kümmert sich um
 * Brand-Scoping, Validierung und Audit. Solange das Domänenmodell noch nicht
 * auf der Plattform liegt, erzeugt ein lokaler Fallback denselben Vertrag.
 */
class EurMonthController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
        ]);

        $year = (int) ($data['year'] ?? EurYear::DEFAULT);
        $month = (int) $data['month'];

        $entries = EurEntry::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $warehouse = EurWarehouseEntry::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $hours = EurHour::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $report = EurMonthlyReport::forCurrentBrand()->where('year', $year)->where('month', $month)->first();
        $signature = EurSignature::forCurrentBrand()->where('year', $year)->where('month', $month)->first();

        if (class_exists(MonthStatement::class)) {
            $payload = MonthStatement::build(
                $year,
                $month,
                $entries->map(function (EurEntry $entry): array {
                    $row = $entry->toArray();
                    // Die Domäne liest die Richtung aus `kind`/`type`, das
                    // Modell speichert sie als `direction`.
                    $row['kind'] = $entry->direction;

                    return $row;
                })->all(),
                $warehouse->toArray(),
                $hours->toArray(),
                [
                    'report_status' => $report?->status,
                    'closed_at' => $report?->closed_at?->toIso8601String(),
                    'signature_status' => $signature?->status,
                    'signature_provider' => $signature?->provider,
                ],
            );
        } else {
            $payload = $this->buildFallback(
                $entries->all(),
                $warehouse->all(),
                $hours->all(),
                $report,
                $signature,
                $year,
                $month,
            );
        }

        return response()->json(array_replace($this->defaults($year, $month), $payload));
    }

    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
            'note' => 'nullable|string|max:2000',
        ]);

        $year = (int) ($data['year'] ?? EurYear::DEFAULT);
        $month = (int) $data['month'];

        $report = EurMonthlyReport::updateOrCreate(
            [
                'brand' => BrandRegistry::currentId(),
                'year' => $year,
                'month' => $month,
            ],
            [
                'status' => 'closed',
                'note' => $data['note'] ?? null,
                'closed_at' => now(),
            ],
        );

        if ($report->wasRecentlyCreated || $report->wasChanged('status')) {
            EurAuditLog::create([
                'brand' => BrandRegistry::currentId(),
                'user_id' => auth('api')->id(),
                'action' => 'month_closed',
                'subject_type' => 'eur_monthly_report',
                'subject_id' => $report->id,
                'payload' => [
                    'year' => $report->year,
                    'month' => $report->month,
                    'status' => $report->status,
                ],
            ]);
        }

        return response()->json([
            'report' => [
                'id' => (int) $report->id,
                'year' => (int) $report->year,
                'month' => (int) $report->month,
                'status' => $report->status,
                'note' => $report->note,
                'closed_at' => $report->closed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(int $year, int $month): array
    {
        return [
            'year' => $year,
            'month' => $month,
            'lines' => [],
            'totals' => [
                'income_cents' => 0,
                'expense_cents' => 0,
                'warehouse_cents' => 0,
                'vat_cents' => 0,
                'hours_minutes' => 0,
            ],
            'checks' => [],
            'social_insurance' => ['amounts_cents' => [], 'rates_per_10000' => []],
            'income_tax' => ['profit_cents' => 0, 'tax_cents' => 0],
            'report' => ['status' => 'open', 'closed_at' => null],
            'signature' => ['status' => 'none', 'provider' => null, 'is_test' => false],
        ];
    }

    /**
     * @param  list<EurEntry>  $entries
     * @param  list<EurWarehouseEntry>  $warehouse
     * @param  list<EurHour>  $hours
     * @return array<string, mixed>
     */
    private function buildFallback(array $entries, array $warehouse, array $hours, ?EurMonthlyReport $report, ?EurSignature $signature, int $year, int $month): array
    {
        $lines = [];
        $income = 0;
        $expense = 0;

        foreach ($entries as $entry) {
            $amount = (int) $entry->business_amount_cents;
            $checks = class_exists(JournalRules::class)
                ? JournalRules::checks($entry->toArray())
                : [];

            $lines[] = [
                'type' => $entry->direction === 'income' ? 'E' : 'A',
                'day' => $entry->day === null ? null : (int) $entry->day,
                'description' => $entry->description ?? $entry->kind_type,
                'amount_cents' => (int) $entry->amount_cents,
                'business_amount_cents' => $amount,
                'vat_kind' => $entry->vat_kind,
                'receipt_no' => $entry->receipt_no,
                'warnings' => array_values(array_filter($checks, static fn (array $check): bool => $check['ok'] === false)),
                'hints' => array_values(array_filter($checks, static fn (array $check): bool => ! empty($check['hint']))),
            ];

            if ($entry->direction === 'income') {
                $income += $amount;
            } else {
                $expense += $amount;
            }
        }

        foreach ($warehouse as $item) {
            $lines[] = [
                'type' => 'WEB',
                'day' => $item->day === null ? null : (int) $item->day,
                'description' => $item->description,
                'amount_cents' => (int) $item->amount_cents,
                'business_amount_cents' => (int) $item->amount_cents,
                'vat_kind' => null,
                'receipt_no' => $item->receipt_no,
                'warnings' => [],
                'hints' => [],
            ];
        }

        $warehouseCents = (int) array_sum(array_map(static fn (EurWarehouseEntry $item): int => (int) $item->amount_cents, $warehouse));

        return [
            'year' => $year,
            'month' => $month,
            'lines' => $lines,
            'totals' => [
                'income_cents' => $income,
                'expense_cents' => $expense,
                'warehouse_cents' => $warehouseCents,
                'vat_cents' => 0,
                'hours_minutes' => (int) array_sum(array_map(static fn (EurHour $hour): int => (int) $hour->minutes, $hours)),
            ],
            'checks' => [],
            'social_insurance' => [
                'amounts_cents' => [],
                'rates_per_10000' => [],
            ],
            'income_tax' => [
                'profit_cents' => $income - $expense - $warehouseCents,
                'tax_cents' => 0,
            ],
            'report' => [
                'status' => $report?->status ?? 'open',
                'closed_at' => $report?->closed_at?->toIso8601String(),
            ],
            'signature' => [
                'status' => $signature?->status ?? 'none',
                'provider' => $signature?->provider,
                'is_test' => $signature !== null && str_contains(mb_strtolower($signature->provider), 'test'),
            ],
        ];
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
