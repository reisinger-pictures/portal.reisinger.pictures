<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ea\Domain\VatCalculator;
use App\Ea\Parity\BookkeepingTotals;
use App\Ea\Parity\Money;
use App\Ea\Parity\RuleValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * EAR · Parität — datengetriebene Vektoren aus der gepinnten Fixture.
 *
 * Framework-/DB-frei (PHPUnit\Framework\TestCase, NICHT Tests\TestCase).
 * Expected Values und Inputs kommen ausschließlich aus der Fixture; es gibt
 * keinen Direktvergleich, keine zweite Regelquelle und keine kopierten
 * Formeln. Weicht ein abgeleiteter Wert ab, gilt immer canonical.
 *
 * Regelzellen lesen ihren 2026-Wert über RuleValues::canonicalFor bzw.
 * RuleValues::parameterFor aus dem gepinnten Katalog (EUE-ID + Parametername).
 * Die USt-Zahllast (EUE-086) läuft über die portal-eigene VatCalculator-Kette;
 * ihr Erwartungswert stammt aus dem ersten Teilsatz des gepinnten Parameters.
 */
class CleanRoomParityTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../features/eur/cleanroom/cleanroom-parity-2026.json';

    /**
     * @return array<string, array{verify: string, rule: string, parameter: string|null, unit: string, canonical: int, inputs: array<string, int|float>, year: int}>
     */
    public static function vectorProvider(): array
    {
        $fixture = self::readJson();
        $cases = [];

        foreach ($fixture['scenarios'] as $scenario) {
            $inputs = [];

            foreach ($scenario['inputs'] as $input) {
                $inputs[$input['sheet'].'!'.$input['cell']] = $input['value'];
            }

            foreach ($scenario['expected'] as $cell) {
                $label = $scenario['id'].'_'.$cell['verify'].'_'.$cell['sheet'].'!'.$cell['cell'];
                $cases[$label] = [
                    'verify' => $cell['verify'],
                    'rule' => $cell['rule'],
                    'parameter' => $cell['parameter'] ?? null,
                    'unit' => $cell['unit'],
                    'canonical' => $cell['canonical'],
                    'inputs' => $inputs,
                    'year' => (int) $fixture['basis']['business_year'],
                ];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, int|float>  $inputs
     */
    #[DataProvider('vectorProvider')]
    #[Group('smoke')]
    public function test_vector_matches_fixture_canonical(
        string $verify,
        string $rule,
        ?string $parameter,
        string $unit,
        int $canonical,
        array $inputs,
        int $year,
    ): void {
        $this->assertVector(
            $canonical,
            $this->resolve($verify, $rule, $parameter, $unit, $year, $inputs),
            $verify,
        );
    }

    /**
     * Der Vertrag zählt 16 Szenarien / 45 Zellen. Die Zählung ist selbst Teil
     * der Fixture-Prüfung: ein hinzugefügter oder entfernter Vektor muss rot
     * werden, bis MANIFEST.json und die Zähler bewusst nachgezogen sind.
     */
    public function test_fixture_drives_contract_vector_counts(): void
    {
        $cases = self::vectorProvider();
        $scenarios = array_unique(array_map(
            static fn (string $key): string => explode('_', $key, 2)[0],
            array_keys($cases),
        ));

        $this->assertCount(45, $cases);
        $this->assertCount(16, $scenarios);
    }

    /**
     * Wirksamkeitsnachweis auch für eine abgeleitete Regelkonstante: ein
     * geänderter Erwartungswert lässt genau diesen Vektor rot werden.
     */
    public function test_changed_derived_rule_value_is_rejected(): void
    {
        $case = null;

        foreach (self::vectorProvider() as $candidate) {
            if ($candidate['rule'] === 'EUE-062') {
                $case = $candidate;
                break;
            }
        }

        $this->assertNotNull($case);

        $actual = $this->resolve(
            $case['verify'],
            $case['rule'],
            $case['parameter'],
            $case['unit'],
            $case['year'],
            $case['inputs'],
        );
        $this->assertVector($case['canonical'], $actual, $case['verify']);

        $this->expectException(ExpectationFailedException::class);
        $this->assertVector($case['canonical'] + 1, $actual, $case['verify']);
    }

    public function test_changed_expected_value_is_rejected(): void
    {
        $case = null;

        foreach (self::vectorProvider() as $candidate) {
            if ($candidate['verify'] === 'money.businessShare100Cents') {
                $case = $candidate;
                break;
            }
        }

        $this->assertNotNull($case);

        $actual = $this->resolve(
            $case['verify'],
            $case['rule'],
            $case['parameter'],
            $case['unit'],
            $case['year'],
            $case['inputs'],
        );
        $this->assertVector($case['canonical'], $actual, $case['verify']);

        $this->expectException(ExpectationFailedException::class);
        $this->assertVector($case['canonical'] + 1, $actual, $case['verify']);
    }

    /**
     * @param  array<string, int|float>  $inputs
     */
    private function resolve(
        string $verify,
        string $rule,
        ?string $parameter,
        string $unit,
        int $year,
        array $inputs,
    ): int {
        // EUE-086: ein gepinnter Mehrratenwert („20/10/13/4,9 %"), den
        // RuleValues nicht als Skalar liest. Die Zahllast läuft über die
        // portal-eigene VatCalculator-Kette; der Erwartungswert der Fixture
        // stammt aus dem ersten Teilsatz dieses gepinnten Parameters.
        if ($verify === 'vat.zahllastCents') {
            return (new VatCalculator)->forMonth([
                'ig_bases_cents' => [20 => $inputs['VatInput!B7']],
            ])['zahllast'];
        }

        if ($rule !== 'SCENARIO') {
            $values = new RuleValues;

            return $parameter !== null
                ? $values->parameterFor($rule, $parameter, $year, $unit)
                : $values->canonicalFor($rule, $year, $unit);
        }

        return match ($verify) {
            'money.businessShare100Cents' => Money::applyShare(Money::eurosToCents($inputs['A!L3'])),
            'totals.expensesCents' => self::totals($inputs)->expensesCents(),
            'totals.profitCents' => self::totals($inputs)->profitCents(),
            'e1a.resultCents' => self::totals($inputs)->e1aResultCents(),
            'analytics.expenseCents' => self::totals($inputs)->analyticsExpenseCents(),
            'analytics.paymentCents' => self::totals($inputs)->analyticsPaymentCents(),
            'time.threeHoursMinutes' => Money::hoursToMinutes($inputs['Stunden!F3']),
            default => $this->fail(sprintf('Unbekannter verify-Key: %s', $verify)),
        };
    }

    /**
     * @param  array<string, int|float>  $inputs
     */
    private static function totals(array $inputs): BookkeepingTotals
    {
        return BookkeepingTotals::fromEntries(0, Money::eurosToCents($inputs['A!L3']));
    }

    private function assertVector(int $expected, int $actual, string $label): void
    {
        $this->assertIsInt($actual, $label);
        $this->assertSame($expected, $actual, $label);
    }

    private static function readJson(): array
    {
        $data = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('Paritäts-Fixture ist kein Dokument.');
        }

        return $data;
    }
}
