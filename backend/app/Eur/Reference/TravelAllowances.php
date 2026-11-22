<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use App\Eur\Parity\Money;
use InvalidArgumentException;

/**
 * EAR · Reise-Pauschalen (EUE-013) — Tag-/Nächtigungssätze je Land.
 *
 * Aufgelöst wird serverseitig nach Land und Reisejahr. Die Sätze stammen
 * ausschließlich aus dem gepinnten Referenzartefakt; im Code steht kein
 * Länder- oder Betragswert. Rückgaben sind ganze Cent.
 */
final class TravelAllowances
{
    /** @var array<string, array{country: string, day_cents: int, night_cents: int}> */
    private array $byCountry = [];

    /**
     * @param  list<array{land: string, nacht: int|float, tag: int|float}>  $rows
     */
    public function __construct(
        array $rows,
        private readonly int $businessYear,
    ) {
        foreach ($rows as $row) {
            $country = trim((string) ($row['land'] ?? ''));

            if ($country === '') {
                throw new InvalidArgumentException('Reise-Pauschale ohne Land.');
            }

            $this->byCountry[self::key($country)] = [
                'country' => $country,
                'day_cents' => Money::eurosToCents($row['tag'] ?? 0),
                'night_cents' => Money::eurosToCents($row['nacht'] ?? 0),
            ];
        }

        if ($this->byCountry === []) {
            throw new InvalidArgumentException('Reise-Pauschalen sind leer.');
        }
    }

    /**
     * @return list<string> Ländernamen in der Reihenfolge des Artefakts
     */
    public function countries(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['country'],
            $this->byCountry
        ));
    }

    /**
     * @return array{country: string, day_cents: int, night_cents: int}
     */
    public function for(string $country, int $year): array
    {
        if ($year !== $this->businessYear) {
            throw new InvalidArgumentException(sprintf(
                'Nur das Wirtschaftsjahr %d ist gepinnt, erhalten: %d.',
                $this->businessYear,
                $year,
            ));
        }

        $key = self::key($country);

        if (! isset($this->byCountry[$key])) {
            throw new InvalidArgumentException(sprintf('Keine Reise-Pauschale für Land "%s".', $country));
        }

        return $this->byCountry[$key];
    }

    public function has(string $country): bool
    {
        return isset($this->byCountry[self::key($country)]);
    }

    private static function key(string $country): string
    {
        return mb_strtolower(trim($country), 'UTF-8');
    }
}
