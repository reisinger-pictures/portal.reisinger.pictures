<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ea;

use App\Ea\Domain\JournalRules;
use App\Ea\Domain\MonthStatement;
use App\Ea\Reference\EaYear;
use App\Http\Controllers\Controller;
use App\Models\EaAuditLog;
use App\Models\EaEntry;
use App\Models\EaHour;
use App\Models\EaMonthlyReport;
use App\Models\EaSignature;
use App\Models\EaWarehouseEntry;
use App\Services\Ea\EaSvBookingReminder;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EA Rechnung · Monatsansicht und Monatsabschluss (Portal #27).
 *
 * Die reine Domäne `MonthStatement` baut die Antwort aus den übergebenen
 * Arrays; der Controller liefert ausschließlich Daten und kümmert sich um
 * Brand-Scoping, Validierung und Audit. Solange das Domänenmodell noch nicht
 * auf der Plattform liegt, erzeugt ein lokaler Fallback denselben Vertrag.
 */
class EaMonthController extends Controller
{
    public function __construct(private readonly EaSvBookingReminder $svReminder) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
        ]);

        $year = (int) ($data['year'] ?? EaYear::currentOrLast());
        $month = (int) $data['month'];

        $entries = EaEntry::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $quarter = intdiv($month - 1, 3) + 1;
        $quarterBookedSv = (int) EaEntry::forCurrentBrand()
            ->where('year', $year)
            ->whereBetween('month', [($quarter - 1) * 3 + 1, $quarter * 3])
            ->where('e1a_code', 'like', '9225%')
            ->sum('business_amount_cents');
        $warehouse = EaWarehouseEntry::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $hours = EaHour::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $report = EaMonthlyReport::forCurrentBrand()->where('year', $year)->where('month', $month)->first();
        $signature = EaSignature::forCurrentBrand()->where('year', $year)->where('month', $month)->first();

        if (class_exists(MonthStatement::class)) {
            $payload = MonthStatement::build(
                $year,
                $month,
                $entries->map(function (EaEntry $entry): array {
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

        // Portal #58: Quartalserinnerung nur in den Quartalsmonaten, rein
        // hinweisend — der Monatsabschluss bleibt unverändert möglich.
        $quarterCheck = $this->svReminder->quarterEndCheck($year, $month, $quarterBookedSv);
        if ($quarterCheck !== null) {
            $payload['checks'][] = $quarterCheck;
        }

        return response()->json(array_replace($this->defaults($year, $month), $payload));
    }

    /**
     * Idempotent Anlage eines einzelnen Monats (Portal #31 / P7).
     *
     * Ein bereits vorhandener, ggf. abgeschlossener Monat bleibt unberührt:
     * `updateOrCreate` findet ihn anhand des Brand-/Jahr-/Monat-Schlüssels und
     * überschreibt weder Status noch Note noch Abschlusszeit.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(true),
            'month' => 'required|integer|min:1|max:12',
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $report = EaMonthlyReport::updateOrCreate($this->reportKeys($year, $month), []);
        $created = $report->wasRecentlyCreated;

        if ($created) {
            // Datenbank-Defaults (status='open') stehen erst nach dem Refresh
            // im Modell — die Antwort soll denselben Vertrag wie close() haben.
            $report->refresh();
        }

        return response()->json([
            'year' => $year,
            'month' => $month,
            'created' => $created,
            'report' => $this->serializeReport($report),
        ]);
    }

    /** Idempotent Anlage aller zwölf Monate eines Wirtschaftsjahres. */
    public function createYear(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(true),
        ]);

        $year = (int) $data['year'];
        $created = 0;
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $report = EaMonthlyReport::updateOrCreate($this->reportKeys($year, $month), []);

            if ($report->wasRecentlyCreated) {
                $created++;
                $report->refresh();
            }

            $months[] = $this->serializeReport($report);
        }

        return response()->json([
            'year' => $year,
            'created' => $created,
            'months' => $months,
        ]);
    }

    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
            'note' => 'nullable|string|max:2000',
        ]);

        $year = (int) ($data['year'] ?? EaYear::currentOrLast());
        $month = (int) $data['month'];

        $report = EaMonthlyReport::updateOrCreate(
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
            EaAuditLog::create([
                'brand' => BrandRegistry::currentId(),
                'user_id' => auth('api')->id(),
                'action' => 'month_closed',
                'subject_type' => 'ea_monthly_report',
                'subject_id' => $report->id,
                'payload' => [
                    'year' => $report->year,
                    'month' => $report->month,
                    'status' => $report->status,
                ],
            ]);
        }

        return response()->json([
            'report' => $this->serializeReport($report),
        ]);
    }

    /**
     * @return array{brand: string, year: int, month: int}
     */
    private function reportKeys(int $year, int $month): array
    {
        return [
            'brand' => BrandRegistry::currentId(),
            'year' => $year,
            'month' => $month,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReport(EaMonthlyReport $report): array
    {
        return [
            'id' => (int) $report->id,
            'year' => (int) $report->year,
            'month' => (int) $report->month,
            'status' => $report->status,
            'note' => $report->note,
            'closed_at' => $report->closed_at?->toIso8601String(),
        ];
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
     * @param  list<EaEntry>  $entries
     * @param  list<EaWarehouseEntry>  $warehouse
     * @param  list<EaHour>  $hours
     * @return array<string, mixed>
     */
    private function buildFallback(array $entries, array $warehouse, array $hours, ?EaMonthlyReport $report, ?EaSignature $signature, int $year, int $month): array
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
                // Wie in MonthStatement: der Vertrag liefert Strings, keine
                // Check-Objekte. Die Filter (Fehler bzw. vorhandener Hinweis)
                // und die von JournalRules::check() gesetzten Marker bleiben.
                'warnings' => array_values(array_map(
                    static fn (array $check): string => $check['message'],
                    array_filter($checks, static fn (array $check): bool => $check['ok'] === false),
                )),
                'hints' => array_values(array_map(
                    static fn (array $check): string => $check['hint'],
                    array_filter($checks, static fn (array $check): bool => ! empty($check['hint'])),
                )),
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

        $warehouseCents = (int) array_sum(array_map(static fn (EaWarehouseEntry $item): int => (int) $item->amount_cents, $warehouse));

        return [
            'year' => $year,
            'month' => $month,
            'lines' => $lines,
            'totals' => [
                'income_cents' => $income,
                'expense_cents' => $expense,
                'warehouse_cents' => $warehouseCents,
                'vat_cents' => 0,
                'hours_minutes' => (int) array_sum(array_map(static fn (EaHour $hour): int => (int) $hour->minutes, $hours)),
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
    private function yearRule(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
            try {
                EaYear::assertBookable((int) $value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        }];
    }
}
