<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use App\Models\EurAuditLog;
use App\Models\EurWarehouseEntry;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EÜR · Wareneingang (Portal #27).
 *
 * Beträge sind ausschließlich ganze Cent. Der Wareneingang ist eine eigene
 * Summe im Monatsabschluss und wird nicht in die Buchungen gemischt.
 */
class EurWarehouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $query = EurWarehouseEntry::forCurrentBrand();

        if (isset($data['year'])) {
            $query->where('year', (int) $data['year']);
        }

        if (isset($data['month'])) {
            $query->where('month', (int) $data['month']);
        }

        $entries = $query->orderBy('year')->orderBy('month')->orderBy('day')->orderBy('id')->get();

        return response()->json([
            'entries' => $entries->map(fn (EurWarehouseEntry $entry): array => $this->serialize($entry))->values(),
            'total_cents' => (int) $entries->sum('amount_cents'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
            'day' => 'nullable|integer|min:1|max:31',
            'description' => 'required|string|max:190',
            'amount_cents' => 'required|integer|min:0',
            'receipt_no' => 'nullable|string|max:64',
        ]);

        $entry = EurWarehouseEntry::create([
            'brand' => BrandRegistry::currentId(),
            'year' => (int) ($data['year'] ?? EurYear::DEFAULT),
            'month' => (int) $data['month'],
            'day' => isset($data['day']) ? (int) $data['day'] : null,
            'description' => $data['description'],
            'amount_cents' => (int) $data['amount_cents'],
            'receipt_no' => $data['receipt_no'] ?? null,
        ]);

        EurAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => 'created',
            'subject_type' => 'eur_warehouse_entry',
            'subject_id' => $entry->id,
            'payload' => [
                'year' => $entry->year,
                'month' => $entry->month,
                'amount_cents' => $entry->amount_cents,
            ],
        ]);

        return response()->json(['entry' => $this->serialize($entry)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EurWarehouseEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'year' => (int) $entry->year,
            'month' => (int) $entry->month,
            'day' => $entry->day === null ? null : (int) $entry->day,
            'description' => $entry->description,
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
                EurYear::assert((int) $value);
            } catch (InvalidArgumentException) {
                $fail(EurYear::warning((int) $value)['warning']);
            }
        }];
    }
}
