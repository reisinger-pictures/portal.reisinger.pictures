<?php

declare(strict_types=1);

namespace App\Eur\Parity;

use App\Eur\Data\RuleCatalogue;
use App\Eur\Reference\EurYear;
use InvalidArgumentException;
use RuntimeException;

/**
 * EAR · Parität — Jahreskonstanten aus dem gepinnten Regelkatalog.
 *
 * Es gibt keine zweite Regelquelle im Portal: die Werte stehen als
 * Programmcode in `App\Eur\Data\RuleCatalogue` (generiert aus dem
 * unveränderlichen Katalog der Clean-Room-Basis, Issue #74). Regeltexte,
 * Formeln und fremde Bezeichnungen werden nicht neu geschrieben; referenziert
 * wird nur über Regel-IDs, Parameternamen und die strukturierten Felder.
 *
 * Kein Laufzeit-Lesen: der Katalog ist eine PHP-Konstante, `features/` wird
 * zur Laufzeit nicht geöffnet. Ein Buchungsjahr ohne gepinnte Basis (heute
 * 2027) liest über `EurYear::effective()` die Parameter des letzten
 * Regeljahres; 2024 und 2025 bleiben als Regeljahre abfragbar.
 */
final class RuleValues
{
    private const UNITS = ['cents', 'percent', 'per-mille', 'per-10000', 'fraction-percent', 'count'];

    public function canonicalFor(string $ruleId, int $year, string $unit): int
    {
        self::assertYear($year);
        // Fehlt die gepinnte Basis für das Jahr, gelten die Regeln des letzten
        // Regeljahres (EurYear::effective, Owner-Entscheidung #75/#78).
        $year = EurYear::effective($year);

        $rule = self::findRule($ruleId);
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

        return self::convert($first, $ruleId, $unit);
    }

    /**
     * Benannter Parameter einer Regel, z. B. "KM-Geld PKW" oder "GWG-Grenze".
     */
    public function parameterFor(string $ruleId, string $name, int $year, string $unit = 'cents'): int
    {
        self::assertYear($year);
        $year = EurYear::effective($year);

        $rule = self::findRule($ruleId);

        foreach ($rule['parameter'] ?? [] as $parameter) {
            if (($parameter['name'] ?? null) !== $name || ! in_array($year, $parameter['jahre'] ?? [], true)) {
                continue;
            }

            return self::convert(self::parseValue($parameter['wert'] ?? null, $ruleId), $ruleId, $unit);
        }

        throw new RuntimeException(sprintf('Regel %s hat keinen Parameter "%s" für %d.', $ruleId, $name, $year));
    }

    /**
     * Der gepinnte Regeltext einer Regel — für Hinweise, deren Werte nicht als
     * Parameter, sondern im Formeltext stehen (z. B. die AfA-KZ-Whitelist).
     */
    public function ruleLogic(string $ruleId): string
    {
        return (string) (self::findRule($ruleId)['logik'] ?? '');
    }

    /**
     * @return list<string> die Namen aller Parameter einer Regel
     */
    public function parameterNames(string $ruleId): array
    {
        $rule = self::findRule($ruleId);

        return array_values(array_map(
            static fn (array $parameter): string => (string) ($parameter['name'] ?? ''),
            $rule['parameter'] ?? [],
        ));
    }

    public static function assertYear(int $year): void
    {
        EurYear::assert($year);
    }

    /**
     * @return array{logik: string, parameter: list<array{name: string, jahre: list<int>, wert: int|float|string}>}
     */
    private static function findRule(string $ruleId): array
    {
        if (! array_key_exists($ruleId, RuleCatalogue::RULES)) {
            throw new InvalidArgumentException(sprintf('Regel %s ist im gepinnten Katalog nicht vorhanden.', $ruleId));
        }

        return RuleCatalogue::RULES[$ruleId];
    }

    /**
     * @param  array{isPercent: bool, number: string}  $value
     */
    private static function convert(array $value, string $ruleId, string $unit): int
    {
        if (! in_array($unit, self::UNITS, true)) {
            throw new InvalidArgumentException(sprintf('Unbekannte Einheit: "%s".', $unit));
        }

        if ($unit === 'count') {
            if ($value['isPercent']) {
                throw new InvalidArgumentException(sprintf('Regel %s: Prozentwert ist keine Anzahl.', $ruleId));
            }

            return Money::roundCents($value['number']);
        }

        if ($unit === 'fraction-percent') {
            if ($value['isPercent']) {
                return Money::roundCents($value['number']);
            }

            return Money::fractionToPercent($value['number']);
        }

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
