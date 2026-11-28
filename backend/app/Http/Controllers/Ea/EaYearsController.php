<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ea;

use App\Ea\Reference\EaYear;
use App\Http\Controllers\Controller;
use App\Models\EaEntry;
use Illuminate\Http\JsonResponse;

/**
 * EAR · Wirtschaftsjahre — Regeljahre und Buchungsjahre (#75/#78).
 *
 * Der Endpunkt ist die einzige Quelle für die gepinnten Regeljahre, die
 * buchbaren Jahre, den Default und die bebuchten Jahre des Brands. Ein
 * Buchungsjahr ohne gepinnte Regelbasis (heute 2027) ist buchbar und rechnet
 * mit dem letzten Regeljahr; dafür nennt der Client `last_pinned_year`.
 */
class EaYearsController extends Controller
{
    public function index(): JsonResponse
    {
        // Jahre mit mindestens einer Buchung im aktuellen Brand. Die UI zeigt
        // nur diese Jahre (plus das laufende Jahr) im Jahres-Selector an.
        $activeYears = EaEntry::forCurrentBrand()
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->map(static fn (mixed $year): int => (int) $year)
            ->all();

        return response()->json([
            'pinned_years' => EaYear::pinned(),
            'bookable_years' => EaYear::bookable(),
            'last_pinned_year' => EaYear::LAST_PINNED,
            'default_year' => EaYear::currentOrLast(),
            'active_years' => $activeYears,
        ]);
    }
}
