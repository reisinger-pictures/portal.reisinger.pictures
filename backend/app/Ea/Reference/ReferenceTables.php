<?php

declare(strict_types=1);

namespace App\Ea\Reference;

use App\Ea\Data\ReferenceTablesData;
use RuntimeException;

/**
 * EAR · Referenzdaten (EUE-004 … EUE-022) — eine Lesegrenze für alle Tabellen.
 *
 * Quelle sind die committeten Zielwerte in `App\Ea\Data\ReferenceTablesData`
 * (generiert vom Generator `php artisan ea:export-data`,
 * Issue #74). Geldwerte liegen dort bereits in ganzen Cent, Anteile in ganzen
 * Prozent, Sätze in Basispunkten; zur Laufzeit wird keine Datei geöffnet und
 * kein Dezimalwert umgerechnet. Danach gibt es im Portal keine Float-Geldwerte
 * und keine zweite Referenzquelle.
 */
final class ReferenceTables
{
    public const BUSINESS_YEAR = 2026;

    /** EUE-007: fehlt die Zuordnung, gilt dieser Fallback. */
    public const EXPENSE_FALLBACK_E1A = '9230';

    /** EUE-008: fehlt die Zuordnung, gilt dieser Fallback. */
    public const INCOME_FALLBACK_E1A = '9040';

    private static ?self $instance = null;

    private function __construct(private readonly array $data) {}

    public static function load(): self
    {
        return self::$instance ??= new self(ReferenceTablesData::TABLES);
    }

    public function businessYear(): int
    {
        return self::BUSINESS_YEAR;
    }

    public function assertBusinessYear(int $year): void
    {
        EaYear::assert($year);
    }

    /**
     * @return list<array{type: string, e1a: string|null, share_percent: int|null}>
     */
    public function expenseKinds(): array
    {
        return $this->list('expense_kinds');
    }

    /**
     * @return list<array{type: string, e1a: string|null, share_percent: int|null}>
     */
    public function incomeKinds(): array
    {
        return $this->list('income_kinds');
    }

    /**
     * @return list<string>
     */
    public function vatKinds(): array
    {
        return $this->list('vat_kinds');
    }

    /**
     * @return list<array{name: string, category: string}>
     */
    public function vehicleTypes(): array
    {
        return $this->list('vehicle_types');
    }

    /**
     * @return list<array{kind: string, opening_balance_cents: int|null}>
     */
    public function paymentMethods(): array
    {
        return $this->list('payment_methods');
    }

    /**
     * @return list<string>
     */
    public function projects(): array
    {
        return $this->list('projects');
    }

    /**
     * @return array{day_cents: int, night_cents: int}
     */
    public function domesticFlatRates(): array
    {
        return $this->map('domestic_flat_rates');
    }

    public function travel(): TravelAllowances
    {
        $rows = array_map(
            static fn (array $row): array => [
                'land' => (string) $row['country'],
                'tag' => self::euros((int) $row['day_cents']),
                'nacht' => self::euros((int) $row['night_cents']),
            ],
            $this->list('travel_allowances')
        );

        return new TravelAllowances($rows, self::BUSINESS_YEAR);
    }

    public function incomeTax(): IncomeTaxTariff
    {
        $stages = array_map(
            static fn (array $stage): array => [
                'grenze' => self::euros((int) $stage['limit_cents']),
                'satz' => self::fraction((int) $stage['rate_per_10000']),
            ],
            $this->list('income_tax_stages')
        );

        $allowance = $this->map('basic_allowance');

        return new IncomeTaxTariff(
            $stages,
            [
                'grenze' => self::euros((int) $allowance['limit_cents']),
                'satz' => self::fraction((int) $allowance['rate_per_10000']),
            ],
            self::BUSINESS_YEAR,
        );
    }

    public function socialInsurance(): SocialInsurance
    {
        $values = [];

        foreach ($this->map('social_insurance') as $key => $value) {
            $values[(string) $key] = str_ends_with((string) $key, '_satz')
                ? self::fraction((int) $value)
                : self::euros((int) $value);
        }

        return new SocialInsurance($values, self::BUSINESS_YEAR);
    }

    /**
     * Cent → exakte Euro-Dezimalangabe. Die drei Jahres-Lesegrenzen
     * (`TravelAllowances`, `IncomeTaxTariff`, `SocialInsurance`) nehmen ihre
     * Werte in Euro bzw. Anteilen entgegen und rechnen sie über `Money` selbst
     * in Cent um; `bcdiv` liefert die exakte Schreibweise ohne Float.
     */
    private static function euros(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }

    /**
     * Basispunkte → exakter Anteil, z. B. 680 → "0.068".
     */
    private static function fraction(int $per10000): string
    {
        return bcdiv((string) $per10000, '10000', 8);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException(sprintf('Referenztabelle "%s" fehlt oder ist keine Liste.', $key));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function map(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException(sprintf('Referenztabelle "%s" fehlt oder ist keine Map.', $key));
        }

        return $value;
    }
}
