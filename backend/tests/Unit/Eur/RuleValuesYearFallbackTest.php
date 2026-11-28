<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Eur\Data\RuleCatalogue;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\EurYear;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EAR · Regel-Fallback — ein Buchungsjahr ohne gepinnte Basis rechnet mit dem
 * letzten Regeljahr (Owner-Entscheidungen #75/#78).
 *
 * Die Parameterliste kommt aus dem gepinnten Katalog (`RuleCatalogue`), die
 * Werte werden ausschließlich über `RuleValues::parameterFor` gelesen. Damit
 * ist der Fallback programmatisch abgesichert, ohne eine zweite Werteliste.
 */
class RuleValuesYearFallbackTest extends TestCase
{
    /**
     * Jeder Parameter, der für das letzte Regeljahr deklariert ist und einen
     * skalaren Wert hat.
     *
     * Reine Bereichstexte (z. B. EUE-030 „1–31" oder EUE-068 „AM3/2") sind
     * keine Skalarwerte und über `parameterFor` nicht lesbar; sie bleiben
     * außen vor, weil sie keinen Jahreswert tragen.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function pinnedParameterProvider(): array
    {
        $cases = [];

        foreach (RuleCatalogue::RULES as $ruleId => $rule) {
            foreach ($rule['parameter'] as $parameter) {
                if (! in_array(EurYear::LAST_PINNED, $parameter['jahre'], true)) {
                    continue;
                }

                $value = $parameter['wert'];

                if (is_string($value)) {
                    if (preg_match('/^\s*[+-]?\d+(?:[.,]\d+)?\s*%\s*$/u', $value) !== 1) {
                        continue;
                    }

                    $unit = 'percent';
                } else {
                    $unit = 'cents';
                }

                $cases[$ruleId.' '.$parameter['name']] = [$ruleId, $parameter['name'], $unit];
            }
        }

        return $cases;
    }

    #[DataProvider('pinnedParameterProvider')]
    public function test_an_unpinned_booking_year_reads_the_last_pinned_value(string $ruleId, string $name, string $unit): void
    {
        $values = new RuleValues;

        $this->assertSame(
            $values->parameterFor($ruleId, $name, EurYear::LAST_PINNED, $unit),
            $values->parameterFor($ruleId, $name, EurYear::LAST_PINNED + 1, $unit),
        );
    }

    public function test_the_provider_covers_every_scalar_parameter_of_the_last_pinned_year(): void
    {
        $this->assertCount(79, self::pinnedParameterProvider());
    }

    public function test_pinned_years_keep_their_own_values_and_2027_inherits_2026(): void
    {
        $values = new RuleValues;

        $this->assertSame(707000, $values->parameterFor('EUE-014', 'maximale Beitragsgrundlage', 2024, 'cents'));
        $this->assertSame(752500, $values->parameterFor('EUE-014', 'maximale Beitragsgrundlage', 2025, 'cents'));
        $this->assertSame(808500, $values->parameterFor('EUE-014', 'maximale Beitragsgrundlage', 2026, 'cents'));
        $this->assertSame(808500, $values->parameterFor('EUE-014', 'maximale Beitragsgrundlage', 2027, 'cents'));
    }

    public function test_the_canonical_value_of_2027_equals_2026(): void
    {
        $values = new RuleValues;

        $this->assertSame(
            $values->canonicalFor('EUE-014', 2026, 'cents'),
            $values->canonicalFor('EUE-014', 2027, 'cents'),
        );
        $this->assertNotSame(
            $values->canonicalFor('EUE-014', 2024, 'cents'),
            $values->canonicalFor('EUE-014', 2026, 'cents'),
        );
    }
}
