<?php

declare(strict_types=1);

namespace Tests\Unit\Eur\Data;

use App\Eur\Parity\RuleValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * EAR · Issue #74 — jede gepinnte Regel ist über den Code erreichbar.
 *
 * Der Generator schreibt `App\Eur\Data\RuleCatalogue` aus dem Artefakt; dieser
 * Test liest den Katalog selbst und fragt jede Regel über `RuleValues` ab.
 * Zweck: keine Regel des gepinnten Katalogs darf beim Übertragen in Code still
 * herausfallen — fehlt eine ID, wirft `RuleValues` und der Test wird rot.
 *
 * Framework-/DB-frei wie `CleanRoomParityTest`. Mehrteilige Werte („20/10/13 %
 * ", Bereiche wie „1–31", Rechenverweise wie „AM3/2") liest `RuleValues` per
 * Konstruktion nicht: `parseValue` akzeptiert nur Skalare und einfache
 * Prozentangaben. Diese Formen sind unten namentlich dokumentiert, damit eine
 * NEUE nicht-skalare Form nicht still übersprungen wird.
 */
class GeneratedDataIsCoveredTest extends TestCase
{
    /** @var list<string> */
    private const DOCUMENTED_NON_SCALAR_VALUES = [
        '0,5',
        '0,50 H1 / 0,25 H2',
        '0–1',
        '0–10',
        '1–31',
        '2–99',
        '20/10/13 %',
        '20/10/13/4,9 %',
        '25–1200',
        '50 % < Anteil < 100 %',
        'AM3 (voll)',
        'AM3/2',
        'AM3/3',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function ruleProvider(): array
    {
        $cases = [];

        foreach (self::rules() as $rule) {
            $cases[$rule['id']] = [$rule['id']];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function parameterProvider(): array
    {
        $cases = [];

        foreach (self::rules() as $rule) {
            foreach ($rule['parameter'] ?? [] as $parameter) {
                $name = (string) ($parameter['name'] ?? '');
                $unit = self::unit($parameter['wert'] ?? null);

                if ($unit === null) {
                    continue;
                }

                foreach ($parameter['jahre'] ?? [] as $year) {
                    $year = (int) $year;

                    if ($year < 2024 || $year > 2026) {
                        continue;
                    }

                    $cases[$rule['id'].'|'.$name.'|'.$year] = [$rule['id'], $name, $year, $unit];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('ruleProvider')]
    public function test_rule_logic_is_served_from_the_data_class(string $ruleId): void
    {
        $logic = (new RuleValues)->ruleLogic($ruleId);

        $this->assertNotSame('', trim($logic), $ruleId.' hat keinen Regeltext in der Datenklasse.');
    }

    #[DataProvider('parameterProvider')]
    public function test_parameter_is_reachable_for_supported_years(string $ruleId, string $name, int $year, string $unit): void
    {
        $value = (new RuleValues)->parameterFor($ruleId, $name, $year, $unit);

        $this->assertIsInt($value, sprintf('%s "%s" %d', $ruleId, $name, $year));
    }

    /**
     * Nur die dokumentierten nicht-skalaren Formen dürfen fehlen — jede andere
     * übersprungene Form macht den Test rot.
     */
    public function test_only_documented_non_scalar_values_are_skipped(): void
    {
        $skipped = [];

        foreach (self::rules() as $rule) {
            foreach ($rule['parameter'] ?? [] as $parameter) {
                if (self::unit($parameter['wert'] ?? null) !== null) {
                    continue;
                }

                $skipped[(string) $parameter['wert']] = true;
            }
        }

        $values = array_keys($skipped);
        sort($values);

        $expected = self::DOCUMENTED_NON_SCALAR_VALUES;
        sort($expected);

        $this->assertSame($expected, $values, 'Unbekannte, von RuleValues nicht lesbare Parameterform im Katalog.');
    }

    private static function unit(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return 'count';
        }

        if (is_string($value) && preg_match('/^\s*[+-]?\d+(?:[.,]\d+)?\s*%\s*$/u', $value) === 1) {
            return 'percent';
        }

        return null;
    }

    /**
     * @return list<array{id: string, parameter?: list<array<string, mixed>>}>
     */
    private static function rules(): array
    {
        $path = dirname(__DIR__, 5).'/features/eur/cleanroom/domain-rules.json';

        if (! is_file($path)) {
            throw new RuntimeException(
                'Regelkatalog fehlt: '.$path.'. Die Datenklassen mit `php artisan eur:export-data` neu erzeugen.',
            );
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $rules = [];

        foreach ($data['bereiche'] ?? [] as $bereich) {
            foreach ($bereich['regeln'] ?? [] as $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }
}
