<?php

declare(strict_types=1);

namespace App\Eur\Reference;

/**
 * EAR · Jahresabhängige Tabellen der Regeljahre 2024–2026.
 *
 * Werte stammen aus den abgeleiteten Anforderungen und dem Regelkatalog,
 * ausschließlich in ganzen Cent und exakten Brüchen. Kein Float im Rechenpfad.
 * Jede Methode prüft die Eingabe und liest danach über `EurYear::effective()`:
 * ein Buchungsjahr ohne gepinnte Basis (heute 2027) erhält die Werte des
 * letzten Regeljahres.
 */
final class YearTables
{
    /** @var array<int, list<int>> EUE-089 … EUE-093: untere Tarifgrenzen in Cent */
    private const TARIFF_LIMITS = [
        2024 => [1281600, 2081800, 3451300, 6661200, 9926600],
        2025 => [1330800, 2161700, 3583600, 6916600, 10307200],
        2026 => [1353900, 2199200, 3645800, 7036500, 10485900],
    ];

    /** @var list<int> EUE-089 … EUE-093: 20/30/40/48/50 % in Basispunkten */
    private const TARIFF_RATES = [2000, 3000, 4000, 4800, 5000];

    /** @var array<int, array<string, int>> EUE-014 … EUE-022: SV-Werte in Cent */
    private const SOCIAL_INSURANCE_CENTS = [
        2024 => ['max_bgl' => 707000, 'kv_min' => 3545, 'gsvg_pv_min' => 9591, 'fsvg_pv_min' => 10369, 'gsvg_ns_pv_min' => 9591, 'uv_pauschale' => 1135],
        2025 => ['max_bgl' => 752500, 'kv_min' => 3747, 'gsvg_pv_min' => 10195, 'fsvg_pv_min' => 11022, 'gsvg_ns_pv_min' => 10195, 'uv_pauschale' => 1207],
        2026 => ['max_bgl' => 808500, 'kv_min' => 3747, 'gsvg_pv_min' => 10195, 'fsvg_pv_min' => 11022, 'gsvg_ns_pv_min' => 10195, 'uv_pauschale' => 1295],
    ];

    /** @var array<string, int> EUE-014 … EUE-022: Sätze in Basispunkten */
    private const SOCIAL_INSURANCE_RATES = [
        'kv_satz' => 680,
        'gsvg_pv_satz' => 1850,
        'fsvg_pv_satz' => 2000,
        'svs_satz' => 153,
    ];

    /**
     * @return list<array{grenze: int, satz: int}>
     */
    public static function tariffStages(int $year): array
    {
        EurYear::assert($year);
        $year = EurYear::effective($year);

        $stages = [];

        foreach (self::TARIFF_LIMITS[$year] as $index => $limit) {
            $stages[] = ['grenze' => $limit, 'satz' => self::TARIFF_RATES[$index]];
        }

        return $stages;
    }

    /**
     * @return array{grenze: int, satz: int}
     */
    public static function basicAllowance(int $year): array
    {
        EurYear::assert($year);

        // EUE-096/097: 33.000 € Grenze, 15 % Satz in allen Regeljahren.
        return ['grenze' => 3300000, 'satz' => 1500];
    }

    /**
     * @return array<string, int>
     */
    public static function socialInsuranceAmountsCents(int $year): array
    {
        EurYear::assert($year);
        $year = EurYear::effective($year);

        return self::SOCIAL_INSURANCE_CENTS[$year];
    }

    /**
     * @return array<string, int>
     */
    public static function socialInsuranceRatesPer10000(int $year): array
    {
        EurYear::assert($year);

        return self::SOCIAL_INSURANCE_RATES;
    }

    /**
     * EUE-062 … EUE-064: Kilometergeld je Fahrzeug, Jahr und Halbjahr.
     */
    public static function travelPerKmCents(string $vehicle, int $year, ?int $month = null): int
    {
        EurYear::assert($year);
        $year = EurYear::effective($year);

        if ($month !== null && ($month < 1 || $month > 12)) {
            throw new \InvalidArgumentException('Monat muss zwischen 1 und 12 liegen.');
        }

        $secondHalf = $month === null || $month > 6;

        return match (true) {
            str_contains(mb_strtolower($vehicle), 'fahrrad'), str_contains(mb_strtolower($vehicle), 'e-bike') => $year >= 2026 ? 25 : ($year === 2025 ? ($secondHalf ? 25 : 50) : 38),
            str_contains(mb_strtolower($vehicle), 'motorrad') => $year >= 2026 ? 25 : ($year === 2025 ? ($secondHalf ? 25 : 50) : 24),
            default => $year >= 2025 ? 50 : 42,
        };
    }

    /** EUE-065: Kontingent in Kilometern. */
    public static function travelQuota(string $vehicle, int $year): int
    {
        EurYear::assert($year);
        $year = EurYear::effective($year);

        return str_contains(mb_strtolower($vehicle), 'fahrrad')
            ? ($year === 2024 ? 1500 : 3000)
            : 30000;
    }

    /** EUE-066: höchstzulässige Mitfahrer. */
    public static function maximumPassengers(string $vehicle): int
    {
        $normalized = mb_strtolower($vehicle);

        return str_contains($normalized, 'motorrad') ? 1 : (str_contains($normalized, 'fahrrad') ? 0 : 6);
    }

    /** EUE-062/063: Mitfahrerzuschlag je Kilometer in Cent. */
    public static function passengerSurchargeCents(string $vehicle, int $year): int
    {
        EurYear::assert($year);
        $year = EurYear::effective($year);

        if (self::maximumPassengers($vehicle) === 0) {
            return 0;
        }

        return $year === 2024 ? 5 : 15;
    }
}
