<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Eur\Parity\BookkeepingTotals;
use App\Eur\Parity\Money;
use App\Eur\Parity\RuleValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * EAR · Parität — 19 datengetriebene Vektoren aus der gepinnten Fixture.
 *
 * Framework-/DB-frei (PHPUnit\Framework\TestCase, NICHT Tests\TestCase).
 * Expected Values und Inputs kommen ausschließlich aus der Fixture; es gibt
 * keinen Direktvergleich, keine zweite Regelquelle und keine kopierten
 * Formeln. Weicht ein abgeleiteter Wert ab, gilt immer canonical.
 */
class CleanRoomParityTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../features/eur/cleanroom/cleanroom-parity-2026.json';

    /**
     * @return array<string, array{verify: string, rule: string, unit: string, canonical: int, inputs: array<string, int|float>, year: int}>
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
    public function test_vector_matches_fixture_canonical(
        string $verify,
        string $rule,
        string $unit,
        int $canonical,
        array $inputs,
        int $year,
    ): void {
        $this->assertVector(
            $canonical,
            $this->resolve($verify, $rule, $unit, $year, $inputs),
            $verify,
        );
    }

    public function test_fixture_drives_nineteen_named_vectors(): void
    {
        $cases = self::vectorProvider();
        $scenarios = array_unique(array_map(
            static fn (string $key): string => explode('_', $key, 2)[0],
            array_keys($cases),
        ));

        $this->assertCount(19, $cases);
        $this->assertCount(5, $scenarios);
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

        $actual = $this->resolve($case['verify'], $case['rule'], $case['unit'], $case['year'], $case['inputs']);
        $this->assertVector($case['canonical'], $actual, $case['verify']);

        $this->expectException(ExpectationFailedException::class);
        $this->assertVector($case['canonical'] + 1, $actual, $case['verify']);
    }

    /**
     * @param  array<string, int|float>  $inputs
     */
    private function resolve(string $verify, string $rule, string $unit, int $year, array $inputs): int
    {
        if ($rule !== 'SCENARIO') {
            return (new RuleValues)->canonicalFor($rule, $year, $unit);
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
