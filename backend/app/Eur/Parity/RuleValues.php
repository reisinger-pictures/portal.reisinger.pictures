<?php

declare(strict_types=1);

namespace App\Eur\Parity;

use InvalidArgumentException;
use RuntimeException;

/**
 * EÜR · Parität — Jahreskonstanten aus dem gepinnten Regelkatalog.
 *
 * Es gibt keine zweite Regelquelle im Portal: die Werte werden zur Laufzeit
 * aus dem unveränderlichen Katalog der Clean-Room-Basis gelesen. Regeltexte,
 * Formeln und fremde Bezeichnungen stehen nicht im Code; referenziert wird
 * nur über Regel-IDs und die strukturierten Felder der Parameter.
 */
final class RuleValues
{
    private const YEAR = 2026;

    private const CATALOG = __DIR__.'/../../../../features/eur/cleanroom/domain-rules.json';

    private const UNITS = ['cents', 'percent', 'per-mille', 'per-10000'];

    public function canonicalFor(string $ruleId, int $year, string $unit): int
    {
        if ($year !== self::YEAR) {
            throw new InvalidArgumentException(sprintf('Nur das Wirtschaftsjahr %d ist gepinnt, erhalten: %d.', self::YEAR, $year));
        }

        if (! in_array($unit, self::UNITS, true)) {
            throw new InvalidArgumentException(sprintf('Unbekannte Einheit: "%s".', $unit));
        }

        $rule = self::findRule(self::readCatalog(), $ruleId);
        $value = self::valueForYear($rule, $ruleId, $year);

        if ($unit === 'cents') {
            if ($value['isPercent']) {
                throw new InvalidArgumentException(sprintf('Regel %s: Prozentwert kann nicht in Cent umgerechnet werden.', $ruleId));
            }

            return Money::eurosToCents($value['number']);
        }

        if (! $value['isPercent']) {
            throw new InvalidArgumentException(sprintf('Regel %s: EUR-Betrag ist keine Prozentangabe.', $ruleId));
        }

        // percent -> 1, per-mille -> 10, per-10000 -> 100. Die Skalierung
        // läuft über die eine BCMath-Umrechnung in Money, es gibt keine
        // zweite Rundungslogik im Portal.
        $divisor = match ($unit) {
            'percent' => '100',
            'per-mille' => '10',
            'per-10000' => '1',
        };

        return Money::eurosToCents(bcdiv($value['number'], $divisor, 6));
    }

    private static function readCatalog(): array
    {
        if (! is_file(self::CATALOG)) {
            throw new RuntimeException('Gepinnter Regelkatalog fehlt: '.self::CATALOG);
        }

        $catalog = json_decode((string) file_get_contents(self::CATALOG), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($catalog)) {
            throw new RuntimeException('Gepinnter Regelkatalog ist kein Dokument.');
        }

        return $catalog;
    }

    private static function findRule(array $catalog, string $ruleId): array
    {
        foreach ($catalog['bereiche'] ?? [] as $bereich) {
            foreach ($bereich['regeln'] ?? [] as $rule) {
                if (($rule['id'] ?? null) === $ruleId) {
                    return $rule;
                }
            }
        }

        throw new InvalidArgumentException(sprintf('Regel %s ist im gepinnten Katalog nicht vorhanden.', $ruleId));
    }

    /**
     * @return array{isPercent: bool, number: string}
     */
    private static function valueForYear(array $rule, string $ruleId, int $year): array
    {
        $parameters = array_values(array_filter(
            $rule['parameter'] ?? [],
            static fn (array $parameter): bool => in_array($year, $parameter['jahre'] ?? [], true),
        ));

        if ($parameters === []) {
            throw new RuntimeException(sprintf('Regel %s hat keinen Wert für %d.', $ruleId, $year));
        }

        $first = self::parseValue($parameters[0]['wert'] ?? null, $ruleId);

        foreach (array_slice($parameters, 1) as $parameter) {
            if (self::parseValue($parameter['wert'] ?? null, $ruleId) !== $first) {
                throw new RuntimeException(sprintf('Regel %s: widersprüchliche Werte für %d.', $ruleId, $year));
            }
        }

        return $first;
    }

    /**
     * @return array{isPercent: bool, number: string}
     */
    private static function parseValue(mixed $wert, string $ruleId): array
    {
        if (is_int($wert)) {
            return ['isPercent' => false, 'number' => (string) $wert];
        }

        if (is_float($wert)) {
            return ['isPercent' => false, 'number' => rtrim(rtrim(number_format($wert, 14, '.', ''), '0'), '.') ?: '0'];
        }

        if (is_string($wert) && preg_match('/^\s*([+-]?\d+(?:[.,]\d+)?)\s*%\s*$/u', $wert, $matches) === 1) {
            return ['isPercent' => true, 'number' => str_replace(',', '.', $matches[1])];
        }

        throw new InvalidArgumentException(sprintf('Regel %s: unlesbarer Parameterwert.', $ruleId));
    }
}
