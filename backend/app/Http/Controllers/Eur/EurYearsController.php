<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use App\Models\EurEntry;
use Illuminate\Http\JsonResponse;

/**
 * EAR · Unterstützte Wirtschaftsjahre (EUE-001 … EUE-003).
 *
 * Der Endpunkt ist die einzige Quelle für Default, supported-Menge und die
 * Warnhinweise zu Jahren außerhalb des Supports. Es werden bewusst Beispiele
 * unter- und oberhalb des Bereichs ausgeliefert, damit der Client die
 * Warntexte unabhängig von einer Anfrage darstellen kann.
 */
class EurYearsController extends Controller
{
    public function index(): JsonResponse
    {
        // Jahre mit mindestens einer Buchung im aktuellen Brand. Die UI zeigt
        // nur diese Jahre (plus das laufende Jahr) im Jahres-Selector an.
        $activeYears = EurEntry::forCurrentBrand()
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->map(static fn (mixed $year): int => (int) $year)
            ->all();

        return response()->json([
            'default_year' => EurYear::DEFAULT,
            'supported' => EurYear::SUPPORTED,
            'active_years' => $activeYears,
            'warnings' => array_map(
                static fn (int $year): array => self::warningRow($year),
                [2023, 2027],
            ),
        ]);
    }

    /**
     * @return array{year: int, code: string|null, warning: string, supported: bool}
     */
    private static function warningRow(int $year): array
    {
        $warning = EurYear::warning($year);

        return [
            'year' => $warning['year'],
            'code' => $warning['code'],
            'warning' => $warning['warning'],
            'supported' => $warning['supported'],
        ];
    }
}
