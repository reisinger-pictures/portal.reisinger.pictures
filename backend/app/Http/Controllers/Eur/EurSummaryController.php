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
use App\Services\Eur\EurSvBookingReminder;
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
    public function __construct(
        private readonly EurOverviewService $overview,
        private readonly EurSvBookingReminder $svReminder,
    ) {}

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

        // EUE-115: 9225 zählt nicht doppelt — bei SV-Berechnung wird es aus
        // den Ausgaben herausgerechnet, im UV-Modus bleibt es stehen.
        $expenseForProfit = $expense - ($this->overview->excludeBookedSvFromExpenses() ? $bookedSv : 0);
        $profit = $income - $expenseForProfit - $afa;

        $svMonthly = $this->overview->svMonthly($year, $income - $expense, $bookedSv);
        $withRelations = EurEntry::forCurrentBrand()->forYear($year)->with(['customer', 'project'])->get();
        $signatures = EurSignature::forCurrentBrand()->where('year', $year)->get(['status'])->all();

        return response()->json([
            'year' => $year,
            'entry_count' => $entries->count(),
            'asset_count' => EurAsset::forCurrentBrand()->count(),
            'income_cents' => $income,
            'expense_cents' => $expense,
            'booked_sv_cents' => $bookedSv,
            // Portal #58: nicht blockierende Erinnerung an die reale 9225-Buchung.
            'sv_reminder' => $this->svReminder->forYear($year, $entries),
            'afa_cents' => $afa,
            'profit_cents' => $profit,
            'sv_monthly' => $svMonthly,
            'kv_rate_hidden' => $this->overview->kvRateHidden(),
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
