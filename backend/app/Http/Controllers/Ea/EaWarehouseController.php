<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ea;

use App\Ea\Reference\EaYear;
use App\Ea\Reference\ReferenceTables;
use App\Http\Controllers\Controller;
use App\Models\EaAuditLog;
use App\Models\EaWarehouseEntry;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * EAR · Wareneingangsbuch (Portal #27, D-83b).
 *
 * Beträge sind ausschließlich ganze Cent. Der Wareneingang ist eine eigene
 * Summe im Monatsabschluss und wird nicht in die Buchungen gemischt. Die
 * WEB-Pflichtfelder und die Ausgaben-Art-Validierung folgen EUE-047; der
 * betriebliche Anteil kommt aus der gepinnten Ausgabenarten-Zuordnung (EUE-045)
 * und wird nie vom Client übernommen.
 */
class EaWarehouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $query = EaWarehouseEntry::forCurrentBrand();

        if (isset($data['year'])) {
            $query->where('year', (int) $data['year']);
        }

        if (isset($data['month'])) {
            $query->where('month', (int) $data['month']);
        }

        $entries = $query->orderBy('year')->orderBy('month')->orderBy('day')->orderBy('id')->get();

        return response()->json([
            'entries' => $entries->map(fn (EaWarehouseEntry $entry): array => $this->serialize($entry))->values(),
            'total_cents' => (int) $entries->sum('amount_cents'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $kinds = ReferenceTables::load()->expenseKinds();
        $receiptNo = $request->input('receipt_no');
        $isWeReceipt = is_string($receiptNo) && str_starts_with(strtoupper($receiptNo), 'WE');

        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
            'day' => 'required|integer|min:1|max:31',
            'description' => 'required|string|max:190',
            'supplier' => 'required|string|max:190',
            'amount_cents' => 'required|integer|min:0',
            'receipt_no' => 'required|string|max:64',
            'expense_kind' => [
                $isWeReceipt ? 'required' : 'nullable',
                'string',
                'max:190',
                Rule::in(array_column($kinds, 'type')),
            ],
            // Nie eine Eingabe: der Anteil wird aus dem Katalog abgeleitet.
            'business_share' => 'prohibited',
        ]);

        $share = $this->businessShare((string) ($data['expense_kind'] ?? ''), $kinds);
        $entry = EaWarehouseEntry::create([
            'brand' => BrandRegistry::currentId(),
            'year' => (int) ($data['year'] ?? EaYear::currentOrLast()),
            'month' => (int) $data['month'],
            'day' => (int) $data['day'],
            'description' => $data['description'],
            'supplier' => $data['supplier'],
            'expense_kind' => $data['expense_kind'] ?? null,
            'amount_cents' => (int) $data['amount_cents'],
            'business_share' => $share,
            'receipt_no' => $data['receipt_no'],
        ]);

        EaAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => 'created',
            'subject_type' => 'ea_warehouse_entry',
            'subject_id' => $entry->id,
            'payload' => [
                'year' => $entry->year,
                'month' => $entry->month,
                'amount_cents' => $entry->amount_cents,
                'business_share' => $share,
                'expense_kind' => $entry->expense_kind,
            ],
        ]);

        return response()->json(['entry' => $this->serialize($entry)], 201);
    }

    /**
     * EUE-045: der Anteil kommt aus der Ausgabenarten-Zuordnung. Ohne
     * hinterlegten Anteil gilt der volle Betrag (ODS: `IF(P="",1,P)`).
     *
     * @param  list<array{type: string, e1a: string|null, share_percent: int|null}>  $kinds
     */
    private function businessShare(string $kind, array $kinds): int
    {
        if ($kind === '') {
            return 100;
        }

        foreach ($kinds as $candidate) {
            if (($candidate['type'] ?? '') === $kind) {
                return $candidate['share_percent'] ?? 100;
            }
        }

        return 100;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EaWarehouseEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'year' => (int) $entry->year,
            'month' => (int) $entry->month,
            'day' => $entry->day === null ? null : (int) $entry->day,
            'description' => $entry->description,
            'supplier' => $entry->supplier,
            'expense_kind' => $entry->expense_kind,
            'business_share' => $entry->business_share === null ? null : (int) $entry->business_share,
            'amount_cents' => (int) $entry->amount_cents,
            'receipt_no' => $entry->receipt_no,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function yearRule(): array
    {
        return ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
            try {
                EaYear::assertBookable((int) $value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        }];
    }
}
