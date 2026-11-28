<?php

namespace App\Http\Controllers\Ea;

use App\Ea\Reference\EaYear;
use App\Http\Controllers\Controller;
use App\Models\EaAsset;
use App\Models\EaAssetYear;
use App\Models\EaDocument;
use App\Models\EaEntry;
use App\Models\EaSignature;
use App\Services\Ea\EaOverviewService;
use App\Services\Ea\EaSvBookingReminder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EAR · Übersicht — Summen aus Buchungen und Anlagen.
 *
 * Alle Beträge sind ganze Cent; es gibt keine Float-Summe. Die Übersicht
 * liest ausschließlich brand-scoped Tabellen. Die ergänzenden Blöcke kommen
 * aus dem reinen Overview-Dienst; alle bisherigen Antwortschlüssel bleiben
 * unverändert.
 */
class EaSummaryController extends Controller
{
    public function __construct(
        private readonly EaOverviewService $overview,
        private readonly EaSvBookingReminder $svReminder,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    EaYear::assertBookable((int) $value);
                } catch (InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            }],
        ]);

        $year = (int) ($data['year'] ?? EaYear::currentOrLast());

        $entries = EaEntry::forCurrentBrand()->forYear($year)->get();
        $income = (int) $entries->where('direction', 'income')->sum('business_amount_cents');
        $expense = (int) $entries->where('direction', 'expense')->sum('business_amount_cents');

        $assetYears = EaAssetYear::forCurrentBrand()->where('year', $year)->get();
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
        $withRelations = EaEntry::forCurrentBrand()->forYear($year)->with(['customer', 'project'])->get();
        $signatures = EaSignature::forCurrentBrand()->where('year', $year)->get(['status'])->all();

        return response()->json([
            'year' => $year,
            'entry_count' => $entries->count(),
            'asset_count' => EaAsset::forCurrentBrand()->count(),
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
            'open_documents' => EaDocument::forCurrentBrand()
                ->where('year', $year)
                ->where('status', 'uploaded')
                ->count(),
            'signature_status' => $this->overview->signatureStatus($signatures),
        ]);
    }
}
