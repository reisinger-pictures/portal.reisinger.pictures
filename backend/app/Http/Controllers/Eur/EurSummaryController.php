<?php

namespace App\Http\Controllers\Eur;

use App\Http\Controllers\Controller;
use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Models\EurEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EÜR · Übersicht — Summen aus Buchungen und Anlagen.
 *
 * Alle Beträge sind ganze Cent; es gibt keine Float-Summe. Die Übersicht
 * liest ausschließlich brand-scoped Tabellen.
 */
class EurSummaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'nullable|integer|in:2026',
        ]);

        $year = (int) ($data['year'] ?? 2026);

        $entries = EurEntry::forCurrentBrand()->forYear($year)->get();
        $income = (int) $entries->where('direction', 'income')->sum('business_amount_cents');
        $expense = (int) $entries->where('direction', 'expense')->sum('business_amount_cents');

        $afa = (int) EurAssetYear::forCurrentBrand()
            ->where('year', $year)
            ->sum('business_use_cents');

        return response()->json([
            'year' => $year,
            'entry_count' => $entries->count(),
            'asset_count' => EurAsset::forCurrentBrand()->count(),
            'income_cents' => $income,
            'expense_cents' => $expense,
            'afa_cents' => $afa,
            'profit_cents' => $income - $expense - $afa,
        ]);
    }
}
