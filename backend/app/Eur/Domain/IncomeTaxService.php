<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use App\Eur\Parity\Money;
use App\Eur\Reference\EurYear;
use App\Eur\Reference\IncomeTaxTariff;
use InvalidArgumentException;

/**
 * EAR · Einkommensteuer-Aufteilung und Monatsbelastung (EUE-095, EUE-103, EUE-126).
 *
 * Reine Domäne: ganze Cent, BCMath, keine Datenbank, keine Uhr, kein Float im
 * Rechenpfad. Die Tarifgrenzen und -sätze kommen ausschließlich aus dem
 * übergebenen, gepinnten {@see IncomeTaxTariff}; die SV-Parameter werden vom
 * Aufrufer aus der gepinnten SV-Engine (EurOverviewService) geliefert.
 *
 * Die Kennzahlen folgen dem Regelkatalog (features/eur/cleanroom/DOMAIN.md,
 * technischer Beleg der Regeln EUE-094/095/103/125/126):
 *   U10 = betriebliche Einkünfte nach Grundfreibetrag, U11 = KZ 245,
 *   U12 = E1B-Ergebnis, U13 = Prognose Vermietung, U14 = Gesamteinkommen,
 *   U23 = SUM(U17:U21), U27 = Steuer auf U11, U25 = Steuer auf U11+U12 − U27,
 *   U24 = U23 − U25 − U27, U29 = ue_est − U26 − U28.
 *
 * `ue_est` ist im gepinnten Dump ein definierter Name ohne Formel. Die
 * dokumentierte Interpretation (owner-approved integration note, D-88) liest
 * ihn als U23, die mit dem gepinnten Tarif ermittelte Endsteuer auf das
 * Gesamteinkommen. Das gilt auch für U29.
 */
final class IncomeTaxService
{
    public const MODE_TOTAL = 'Gesamt';

    public const MODE_BUSINESS = 'Nur Betrieb';

    public const MODE_RENTAL = 'Nur Vermietung';

    public const MODE_FORECAST = 'Mit Prognose';

    public const MODE_GROSS_WAGE = 'Nur Bruttolohn';

    public const MODE_OTHER_PROFITS = 'Nur andere Gewinne';

    /** Die Portal-Modi werden auf die im Regelkatalog benannten Modi abgebildet. */
    private const MODE_ALIASES = [
        'total' => self::MODE_TOTAL,
        'business' => self::MODE_BUSINESS,
        'rental' => self::MODE_RENTAL,
        'forecast' => self::MODE_FORECAST,
        'gross_wage' => self::MODE_GROSS_WAGE,
        'other_profits' => self::MODE_OTHER_PROFITS,
    ];

    public function __construct(private readonly IncomeTaxTariff $tariff) {}

    public function normaliseMode(string $mode): string
    {
        $normalised = self::MODE_ALIASES[$mode] ?? $mode;

        if (! in_array($normalised, [
            self::MODE_TOTAL, self::MODE_BUSINESS, self::MODE_RENTAL,
            self::MODE_FORECAST, self::MODE_GROSS_WAGE, self::MODE_OTHER_PROFITS,
        ], true)) {
            throw new InvalidArgumentException(sprintf('Unbekannter Steuer-Modus "%s".', $mode));
        }

        return $normalised;
    }

    /**
     * EUE-095: U14 = IF(modus='Nur Betrieb',N(U10)+N(U11),
     *   IF(modus='Nur Vermietung',N(U11)+N(U12)+N(U13),
     *      N(U10)+N(U11)+N(U12)+N(U13))).
     *
     * "Mit Prognose" entspricht dem Gesamtmodus; die Prognose selbst steckt
     * als U13 im Übergabewert und wird hier nicht erneut hochgerechnet.
     */
    public function totalIncomeCents(
        string $mode,
        int $businessIncomeCents,
        int $employmentIncomeCents,
        int $rentalResultCents,
        int $rentalForecastCents,
    ): int {
        return match ($this->normaliseMode($mode)) {
            self::MODE_BUSINESS => $businessIncomeCents + $employmentIncomeCents,
            self::MODE_RENTAL => $employmentIncomeCents + $rentalResultCents + $rentalForecastCents,
            default => $businessIncomeCents + $employmentIncomeCents + $rentalResultCents + $rentalForecastCents,
        };
    }

    /**
     * EUE-094: die five Tarifstufen U17…U21 ergeben U23. Der gepinnte Tarif
     * rechnet die Anteile zwischen den Grenzen mit ihren Sätzen und rundet
     * genau einmal kaufmännisch auf ganze Cent.
     */
    public function progressiveTaxCents(int $incomeCents): int
    {
        return $this->tariff->taxCents($incomeCents);
    }

    /**
     * EUE-103: Aufteilung der Endsteuer auf Bruttolohn, Vermietung und Betrieb
     * sowie Abzug von Vorauszahlung (U26) und Lohnsteuer (U28).
     *
     * @return array{total_income_cents: int, tax_cents: int, employment_tax_cents: int, rental_tax_cents: int, business_tax_cents: int|null, due_cents: int, prepayment_cents: int, wage_tax_cents: int}
     */
    public function allocate(
        string $mode,
        int $totalIncomeCents,
        int $employmentIncomeCents,
        int $rentalResultCents,
        int $prepaymentCents,
        int $wageTaxCents,
    ): array {
        $mode = $this->normaliseMode($mode);

        $ueEst = $this->progressiveTaxCents($totalIncomeCents); // U23 = ue_est (siehe Klassenkommentar)
        $u27 = $this->progressiveTaxCents($employmentIncomeCents);
        $u25 = $this->progressiveTaxCents($employmentIncomeCents + $rentalResultCents) - $u27;

        // U24 ist im Modus "Nur Bruttolohn" leer (EUE-103/EUE-105).
        $u24 = $mode === self::MODE_GROSS_WAGE ? null : $ueEst - $u25 - $u27;

        $u29 = ($prepaymentCents + $wageTaxCents) === 0
            ? $ueEst
            : $ueEst - $prepaymentCents - $wageTaxCents;

        return [
            'total_income_cents' => $totalIncomeCents,
            'tax_cents' => $ueEst,
            'employment_tax_cents' => $u27,
            'rental_tax_cents' => $u25,
            'business_tax_cents' => $u24,
            'due_cents' => $u29,
            'prepayment_cents' => $prepaymentCents,
            'wage_tax_cents' => $wageTaxCents,
        ];
    }

    /**
     * EUE-125/126: monatliche EST+SV (J12), Reingewinn (K12), Reingewinn je
     * Stunde (N5) und die Verlustbasis I16. Alle Werte ganze Cent; ein Monat
     * ohne Daten liefert null, eine Stunde mit 0 Minuten ebenfalls.
     *
     *   I12 = monatlicher Mindest-SV-Sockel (aus der gepinnten SV-Engine)
     *   I13 = U24 + U25 + ue_sv − I12 × Anzahl Monate mit Daten
     *   I14 = |min(ue_gewinn_pro_monat)|
     *   I16 = Σ(ue_gewinn_pro_monat + I14) über Monate mit Daten
     *   J12 = (G12 + I14) / I16 × I13 + I12
     *   K12 = N(G12) − N(J12)
     *   N5  = K12 × 60 / M12   (M12 = ganze Minuten)
     *
     * Der Vermietungszweig der gepinnten Formel verteilt U23−U27 über
     * Σ(ue_gewinn_pro_monat) + I14×12 und ohne den I12-Zuschlag.
     *
     * @param  array<string, int>  $annual  tax_cents, employment_tax_cents, business_tax_cents, rental_tax_cents, sv_cents, minimum_sv_cents
     * @param  array<int, array{gross_profit_cents?: int, hours_minutes?: int}>  $months  Monat => Bruttoergebnis/Minuten
     * @return array{annual: array<string, int>, months: list<array<string, int|null>>}
     */
    public function monthlyResults(int $year, string $mode, array $annual, array $months): array
    {
        EurYear::assert($year);
        $mode = $this->normaliseMode($mode);

        $tax = (int) ($annual['tax_cents'] ?? 0);
        $employmentTax = (int) ($annual['employment_tax_cents'] ?? 0);
        $businessTax = (int) ($annual['business_tax_cents'] ?? 0);
        $rentalTax = (int) ($annual['rental_tax_cents'] ?? 0);
        $sv = (int) ($annual['sv_cents'] ?? 0);
        $minimumSv = (int) ($annual['minimum_sv_cents'] ?? 0);

        $filled = [];

        foreach ($months as $month => $values) {
            if (! is_int($month) || $month < 1 || $month > 12 || ! is_array($values)) {
                continue;
            }

            if (! isset($values['gross_profit_cents']) || ! is_int($values['gross_profit_cents'])) {
                continue;
            }

            $minutes = $values['hours_minutes'] ?? 0;
            $filled[$month] = [
                'gross_profit_cents' => $values['gross_profit_cents'],
                'hours_minutes' => is_int($minutes) ? max(0, $minutes) : 0,
            ];
        }

        $profits = array_map(static fn (array $values): int => $values['gross_profit_cents'], $filled);
        $count = count($filled);
        $lossBasis = $profits === [] ? 0 : abs(min($profits)); // I14

        $basis = 0; // I16

        foreach ($profits as $profit) {
            $basis += $profit + $lossBasis;
        }

        if ($mode === self::MODE_RENTAL) {
            $divisor = array_sum($profits) + $lossBasis * 12;
            $distributable = $tax - $employmentTax; // U23 − U27
        } else {
            $divisor = $basis;
            $distributable = $businessTax + $rentalTax + $sv - $minimumSv * $count; // I13
        }

        $rows = [];
        $allocated = 0;

        for ($month = 1; $month <= 12; $month++) {
            $values = $filled[$month] ?? null;

            if ($values === null) {
                $rows[] = [
                    'month' => $month,
                    'gross_profit_cents' => null,
                    'hours_minutes' => null,
                    'est_sv_cents' => null,
                    'net_profit_cents' => null,
                    'net_per_hour_cents' => null,
                    'basis_cents' => null,
                    'loss_basis_cents' => null,
                    'minimum_sv_cents' => null,
                ];

                continue;
            }

            $gross = $values['gross_profit_cents'];
            $share = $divisor === 0
                ? null
                : Money::roundCents(bcdiv(
                    bcmul((string) ($gross + $lossBasis), (string) $distributable, 8),
                    (string) $divisor,
                    8,
                ));
            $estSv = $share === null ? null : $share + ($mode === self::MODE_RENTAL ? 0 : $minimumSv);
            $net = $estSv === null ? null : $gross - $estSv;
            $perHour = ($net !== null && $values['hours_minutes'] > 0)
                ? Money::roundCents(bcdiv(bcmul((string) $net, '60'), (string) $values['hours_minutes'], 8))
                : null;

            if ($estSv !== null) {
                $allocated += $estSv;
            }

            $rows[] = [
                'month' => $month,
                'gross_profit_cents' => $gross,
                'hours_minutes' => $values['hours_minutes'],
                'est_sv_cents' => $estSv,
                'net_profit_cents' => $net,
                'net_per_hour_cents' => $perHour,
                'basis_cents' => $basis > 0 ? $basis : null,
                'loss_basis_cents' => $lossBasis,
                'minimum_sv_cents' => $minimumSv,
            ];
        }

        return [
            'annual' => [
                'year' => $year,
                'mode' => $mode,
                'tax_cents' => $tax,
                'employment_tax_cents' => $employmentTax,
                'business_tax_cents' => $businessTax,
                'rental_tax_cents' => $rentalTax,
                'sv_cents' => $sv,
                'minimum_sv_cents' => $minimumSv,
                'burden_cents' => $businessTax + $rentalTax + $sv,
                'distributable_cents' => $distributable,
                'loss_basis_cents' => $lossBasis,
                'allocation_basis_cents' => $basis,
                'allocated_cents' => $allocated,
                'months_with_data' => $count,
            ],
            'months' => $rows,
        ];
    }
}
