<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * EÜR · Unterstützte Wirtschaftsjahre (EUE-001 … EUE-003).
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
        return response()->json([
            'default_year' => EurYear::DEFAULT,
            'supported' => EurYear::SUPPORTED,
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
