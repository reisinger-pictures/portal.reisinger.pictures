<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ea;

use App\Ea\Domain\JournalRules;
use App\Ea\Reference\EaYear;
use App\Ea\Support\Share;
use App\Http\Controllers\Controller;
use App\Models\EaAuditLog;
use App\Models\EaEntry;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EA Rechnung · Eigenbeleg (Portal #31).
 *
 * Ein Eigenbeleg ist immer eine Ausgabe. Wie in `EaEntryController` bleibt
 * Geld ganzzahlig in Cent, der Betriebsanteil ein 5-%-Schritt und der
 * betriebliche Betrag wird einmal an der Schreibgrenze berechnet. Ohne
 * zugeordnete Kennzahl gilt der Journal-Fallback 9230; bei 0 % Betriebsanteil
 * vergibt die Journalregel bewusst keine Kennzahl.
 */
class EaEigenbelegController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
            'day' => 'required|integer|min:1|max:31',
            'description' => 'required|string|max:190',
            'amount_cents' => 'required|integer|min:1',
            'business_share' => 'nullable|integer|min:0|max:100|multiple_of:5',
            'kind_type' => 'nullable|string|max:190',
            'category' => 'nullable|string|max:190',
            'e1a_code' => 'nullable|string|max:8',
            'vat_kind' => 'nullable|string|max:64',
            'payment_method' => 'nullable|string|max:64',
            'receipt_no' => 'nullable|string|max:64',
            'note' => 'nullable|string|max:2000',
        ]);

        $share = isset($data['business_share']) ? (int) $data['business_share'] : 100;
        $kind = $data['kind_type'] ?? 'Eigenbeleg';
        $mapped = isset($data['e1a_code']) && $data['e1a_code'] !== '' ? $data['e1a_code'] : null;
        $code = JournalRules::expenseCode($kind, $share, $mapped);

        $entry = EaEntry::create([
            'brand' => BrandRegistry::currentId(),
            'year' => (int) $data['year'],
            'month' => (int) $data['month'],
            'day' => (int) $data['day'],
            'direction' => 'expense',
            'kind_type' => $kind,
            'category' => $data['category'] ?? 'Eigenbeleg',
            'description' => $data['description'],
            'e1a_code' => $code !== '' ? $code : null,
            'business_share' => $share,
            'amount_cents' => (int) $data['amount_cents'],
            'business_amount_cents' => Share::apply((int) $data['amount_cents'], $share),
            'vat_kind' => $data['vat_kind'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'receipt_no' => $data['receipt_no'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        EaAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => 'created',
            'subject_type' => 'ea_entry',
            'subject_id' => $entry->id,
            'payload' => [
                'year' => $entry->year,
                'month' => $entry->month,
                'direction' => $entry->direction,
                'amount_cents' => $entry->amount_cents,
                'business_share' => $entry->business_share,
                'business_amount_cents' => $entry->business_amount_cents,
                'eigenbeleg' => true,
            ],
        ]);

        return response()->json(['entry' => $this->serialize($entry)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EaEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'year' => (int) $entry->year,
            'month' => (int) $entry->month,
            'day' => $entry->day === null ? null : (int) $entry->day,
            'direction' => $entry->direction,
            'kind_type' => $entry->kind_type,
            'category' => $entry->category,
            'description' => $entry->description,
            'e1a_code' => $entry->e1a_code,
            'business_share' => (int) $entry->business_share,
            'amount_cents' => (int) $entry->amount_cents,
            'business_amount_cents' => (int) $entry->business_amount_cents,
            'vat_kind' => $entry->vat_kind,
            'payment_method' => $entry->payment_method,
            'receipt_no' => $entry->receipt_no,
            'note' => $entry->note,
            'checks' => JournalRules::checks(array_merge($entry->toArray(), ['kind' => $entry->direction])),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function yearRule(): array
    {
        return ['required', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
            try {
                EaYear::assertBookable((int) $value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        }];
    }
}
