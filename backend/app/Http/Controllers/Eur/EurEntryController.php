<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Support\Share;
use App\Http\Controllers\Controller;
use App\Models\EurAuditLog;
use App\Models\EurCustomer;
use App\Models\EurEntry;
use App\Models\EurProject;
use App\Support\BrandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EÜR · Buchungen (Einnahmen und Ausgaben).
 *
 * Geld kommt und geht ausschließlich als ganze Cent; der Betriebsanteil ist
 * eine ganze Prozentzahl in 5-%-Schritten. Der betriebliche Betrag wird hier
 * einmal an der Schreibgrenze berechnet, damit Auswertungen keinen Float
 * bilden. Jeder Schreibzugriff erzeugt einen Audit-Eintrag ohne Dateiinhalte.
 */
class EurEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'nullable|integer',
            'month' => 'nullable|integer|min:1|max:12',
            'direction' => 'nullable|in:income,expense',
        ]);

        $query = EurEntry::forCurrentBrand()->with(['project', 'customer']);

        if (isset($validated['year'])) {
            $query->where('year', $validated['year']);
        }

        if (isset($validated['month'])) {
            $query->where('month', $validated['month']);
        }

        if (isset($validated['direction'])) {
            $query->where('direction', $validated['direction']);
        }

        $entries = $query->orderByDesc('year')->orderByDesc('month')->orderByDesc('id')->get();

        return response()->json([
            'entries' => $entries->map(fn (EurEntry $entry): array => $this->serialize($entry))->values(),
            'totals' => [
                'income_cents' => (int) $entries->where('direction', 'income')->sum('business_amount_cents'),
                'expense_cents' => (int) $entries->where('direction', 'expense')->sum('business_amount_cents'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateEntry($request, null);
        $data['brand'] = BrandRegistry::currentId();
        $data['business_amount_cents'] = $this->businessAmount($data);

        $entry = EurEntry::create($data);
        $this->audit('created', $entry);

        return response()->json(['entry' => $this->serialize($entry->refresh())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entry = EurEntry::forCurrentBrand()->findOrFail($id);
        $data = $this->validateEntry($request, $entry);
        $data['business_amount_cents'] = $this->businessAmount($data);

        $entry->fill($data)->save();
        $this->audit('updated', $entry);

        return response()->json(['entry' => $this->serialize($entry->refresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $entry = EurEntry::forCurrentBrand()->findOrFail($id);
        $entry->delete();
        $this->audit('deleted', $entry);

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEntry(Request $request, ?EurEntry $entry): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', Rule::in([2026])],
            'month' => 'required|integer|min:1|max:12',
            'direction' => 'required|in:income,expense',
            'kind_type' => 'required|string|max:190',
            'e1a_code' => 'nullable|string|max:8',
            'business_share' => 'required|integer|min:0|max:100|multiple_of:5',
            'amount_cents' => 'required|integer|min:0',
            'vat_kind' => 'nullable|string|max:64',
            'payment_method' => 'nullable|string|max:64',
            'eur_project_id' => 'nullable|integer',
            'eur_customer_id' => 'nullable|integer',
            'receipt_no' => 'nullable|string|max:64',
            'note' => 'nullable|string|max:2000',
        ]);

        if (! empty($data['eur_project_id'])) {
            EurProject::forCurrentBrand()->findOrFail((int) $data['eur_project_id']);
        }

        if (! empty($data['eur_customer_id'])) {
            EurCustomer::forCurrentBrand()->findOrFail((int) $data['eur_customer_id']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function businessAmount(array $data): int
    {
        $amount = (int) $data['amount_cents'];
        $share = (int) $data['business_share'];

        return Share::apply($amount, $share);
    }

    private function audit(string $action, EurEntry $entry): void
    {
        EurAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'eur_entry',
            'subject_id' => $entry->id,
            'payload' => [
                'year' => $entry->year,
                'month' => $entry->month,
                'direction' => $entry->direction,
                'amount_cents' => $entry->amount_cents,
                'business_share' => $entry->business_share,
                'business_amount_cents' => $entry->business_amount_cents,
            ],
        ]);
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
            'direction' => $entry->direction,
            'kind_type' => $entry->kind_type,
            'e1a_code' => $entry->e1a_code,
            'business_share' => (int) $entry->business_share,
            'amount_cents' => (int) $entry->amount_cents,
            'business_amount_cents' => (int) $entry->business_amount_cents,
            'vat_kind' => $entry->vat_kind,
            'payment_method' => $entry->payment_method,
            'project' => $entry->project?->name,
            'customer' => $entry->customer?->name,
            'receipt_no' => $entry->receipt_no,
            'note' => $entry->note,
        ];
    }
}
