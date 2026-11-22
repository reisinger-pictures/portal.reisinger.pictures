<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Models\EurDocument;
use App\Models\EurEntry;
use App\Models\EurSignature;
use App\Services\Eur\EurOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EAR · Übersicht — Summen aus Buchungen und Anlagen.
 *
 * Alle Beträge sind ganze Cent; es gibt keine Float-Summe. Die Übersicht
 * liest ausschließlich brand-scoped Tabellen. Die ergänzenden Blöcke kommen
 * aus dem reinen Overview-Dienst; alle bisherigen Antwortschlüssel bleiben
 * unverändert.
 */
class EurSummaryController extends Controller
{
    public function __construct(private readonly EurOverviewService $overview) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', Rule::in(EurYear::SUPPORTED)],
        ]);

        $year = (int) ($data['year'] ?? 2026);

        $entries = EurEntry::forCurrentBrand()->forYear($year)->get();
        $income = (int) $entries->where('direction', 'income')->sum('business_amount_cents');
        $expense = (int) $entries->where('direction', 'expense')->sum('business_amount_cents');

        $assetYears = EurAssetYear::forCurrentBrand()->where('year', $year)->get();
        $afa = (int) $assetYears->sum('business_use_cents');

        $bookedSv = 0;

        foreach ($entries as $entry) {
            if (str_starts_with((string) $entry->e1a_code, '9225')) {
                $bookedSv += (int) $entry->business_amount_cents;
            }
        }

        $svMonthly = $this->overview->svMonthly($year, $income - $expense, $bookedSv);
        $withRelations = EurEntry::forCurrentBrand()->forYear($year)->with(['customer', 'project'])->get();
        $signatures = EurSignature::forCurrentBrand()->where('year', $year)->get(['status'])->all();

        return response()->json([
            'year' => $year,
            'entry_count' => $entries->count(),
            'asset_count' => EurAsset::forCurrentBrand()->count(),
            'income_cents' => $income,
            'expense_cents' => $expense,
            'afa_cents' => $afa,
            'profit_cents' => $income - $expense - $afa,
            'sv_monthly' => $svMonthly,
            'insurance_blocks' => $this->overview->insuranceBlocks($svMonthly),
            'afa_card' => $this->overview->afaCard($assetYears->all()),
            'by_customer' => $this->overview->byCustomer($withRelations),
            'open_documents' => EurDocument::forCurrentBrand()
                ->where('year', $year)
                ->where('status', 'uploaded')
                ->count(),
            'signature_status' => $this->overview->signatureStatus($signatures),
        ]);
    }
}
