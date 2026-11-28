<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use App\Models\EurEntry;
use App\Services\Eur\EurInvoiceCorrectionService;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EAR · Rechnungskorrektur (Portal #71): eine extern ausgeführte Teilrückzahlung
 * als negative Einnahme dokumentieren. Es wird keine Erstattung ausgelöst.
 */
class EurInvoiceCorrectionController extends Controller
{
    public function __construct(private readonly EurInvoiceCorrectionService $corrections) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_number' => 'required|string|max:64',
            'refund_cents' => 'required|integer|min:1',
            'reason' => 'required|string|max:190',
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
            'day' => 'required|integer|min:1|max:31',
        ]);

        try {
            $entry = $this->corrections->correct(
                $data['invoice_number'],
                (int) $data['refund_cents'],
                (int) $data['year'],
                (int) $data['month'],
                (int) $data['day'],
                $data['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        } catch (ModelNotFoundException) {
            abort(404, 'Rechnung nicht gefunden.');
        }

        return response()->json(['entry' => $this->serialize($entry)], 201);
    }

    /**
     * @return array<int, mixed>
     */
    private function yearRule(): array
    {
        return [
            'required',
            'integer',
            function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    EurYear::assertBookable((int) $value);
                } catch (InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EurEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'year' => (int) $entry->year,
            'month' => (int) $entry->month,
            'day' => $entry->day === null ? null : (int) $entry->day,
            'direction' => $entry->direction,
            'amount_cents' => (int) $entry->amount_cents,
            'business_amount_cents' => (int) $entry->business_amount_cents,
            'e1a_code' => $entry->e1a_code,
            'category' => $entry->category,
            'receipt_no' => $entry->receipt_no,
            'description' => $entry->description,
            'external_id' => $entry->external_id,
        ];
    }
}
