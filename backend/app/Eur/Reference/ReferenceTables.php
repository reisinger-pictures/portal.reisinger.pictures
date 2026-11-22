<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use App\Eur\Parity\Money;
use RuntimeException;

/**
 * EÜR · Referenzdaten (EUE-004 … EUE-022) — eine Lesegrenze für alle Tabellen.
 *
 * Quelle ist ausschließlich das gepinnte Artefakt
 * `features/eur/cleanroom/artifacts/analysis/tables.json`. Die Datei enthält
 * Dezimalwerte; sie werden genau einmal hier in ganze Cent, ganze Prozent
 * und Basispunkte umgerechnet. Danach gibt es im Portal keine Float-Geldwerte
 * und keine zweite Referenzquelle.
 */
final class ReferenceTables
{
    public const BUSINESS_YEAR = 2026;

    /** EUE-007: fehlt die Zuordnung, gilt dieser Fallback. */
    public const EXPENSE_FALLBACK_E1A = '9230';

    /** EUE-008: fehlt die Zuordnung, gilt dieser Fallback. */
    public const INCOME_FALLBACK_E1A = '9040';

    private const PATH = __DIR__.'/../../../../features/eur/cleanroom/artifacts/analysis/tables.json';

    private static ?self $instance = null;

    private function __construct(private readonly array $data) {}

    public static function load(?string $path = null): self
    {
        if ($path === null && self::$instance !== null) {
            return self::$instance;
        }

        $file = $path ?? self::PATH;

        if (! is_file($file)) {
            throw new RuntimeException('Gepinntes Referenzartefakt fehlt: '.$file);
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('Referenzartefakt ist kein Dokument.');
        }

        $instance = new self($data);

        if ($path === null) {
            self::$instance = $instance;
        }

        return $instance;
    }

    public function businessYear(): int
    {
        return self::BUSINESS_YEAR;
    }

    public function assertBusinessYear(int $year): void
    {
        EurYear::assert($year);
    }

    /**
     * @return list<array{type: string, e1a: string|null, share_percent: int|null}>
     */
    public function expenseKinds(): array
    {
        return $this->kinds('ausgaben_arten');
    }

    /**
     * @return list<array{type: string, e1a: string|null, share_percent: int|null}>
     */
    public function incomeKinds(): array
    {
        return $this->kinds('einnahmen_arten');
    }

    /**
     * @return list<string>
     */
    public function vatKinds(): array
    {
        return array_values(array_map(
            static fn (mixed $kind): string => (string) $kind,
            $this->list('ust_arten')
        ));
    }

    /**
     * @return list<array{name: string, category: string}>
     */
    public function vehicleTypes(): array
    {
        return array_values(array_map(
            static fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'category' => (string) ($row['typ'] ?? ''),
            ],
            $this->list('fahrzeuge')
        ));
    }

    /**
     * @return list<array{kind: string, opening_balance_cents: int|null}>
     */
    public function paymentMethods(): array
    {
        return array_values(array_map(
            static fn (array $row): array => [
                'kind' => (string) ($row['art'] ?? ''),
                'opening_balance_cents' => isset($row['startsaldo'])
                    ? Money::eurosToCents($row['startsaldo'])
                    : null,
            ],
            $this->list('zahlarten')
        ));
    }

    /**
     * @return list<string>
     */
    public function projects(): array
    {
        return array_values(array_map(
            static fn (mixed $project): string => (string) $project,
            $this->list('projekte')
        ));
    }

    /**
     * @return array{day_cents: int, night_cents: int}
     */
    public function domesticFlatRates(): array
    {
        $row = $this->map('tag_nacht_pauschale_at');

        return [
            'day_cents' => Money::eurosToCents($row['tag'] ?? 0),
            'night_cents' => Money::eurosToCents($row['nacht'] ?? 0),
        ];
    }

    public function travel(): TravelAllowances
    {
        /** @var list<array{land: string, nacht: int|float, tag: int|float}> $rows */
        $rows = $this->list('reise_pauschalen');

        return new TravelAllowances($rows, self::BUSINESS_YEAR);
    }

    public function incomeTax(): IncomeTaxTariff
    {
        /** @var list<array{grenze: int|float, satz: int|float}> $stages */
        $stages = $this->list('est_tarif');

        return new IncomeTaxTariff($stages, $this->map('grundfreibetrag'), self::BUSINESS_YEAR);
    }

    public function socialInsurance(): SocialInsurance
    {
        return new SocialInsurance($this->map('sv_werte'), self::BUSINESS_YEAR);
    }

    /**
     * @return list<array{type: string, e1a: string|null, share_percent: int|null}>
     */
    private function kinds(string $key): array
    {
        return array_values(array_map(
            static fn (array $row): array => [
                'type' => (string) ($row['typ'] ?? ''),
                'e1a' => isset($row['e1a']) ? (string) $row['e1a'] : null,
                'share_percent' => isset($row['anteil'])
                    ? Money::fractionToPercent($row['anteil'])
                    : null,
            ],
            $this->list($key)
        ));
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
