<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Domain\E1Statement;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\E1aHelpTexts;
use App\Eur\Reference\EurYear;
use InvalidArgumentException;

/**
 * EAR · Ergänzende Steuerwerte (EUE-095, EUE-101 … EUE-105).
 *
 * Reine Ableitung aus den übergebenen Cent-Werten und den gepinnten Regel-
 * texten: es gibt keine zweite Regelquelle und keinen Float im Rechenpfad.
 * Die Kennzahlen 245/260 und die Werte U13/U26/E40/E39 sind Eingabegrößen
 * des Formulars; ihre Klassifikation und ihre Sichtbarkeit je Modus folgen
 * dem Regelkatalog. Die E1A-Hilfetexte liefern ausschließlich die
 * Bezeichnungen, keine Berechnungsvorschrift.
 */
final class EurTaxStatementService
{
    public const MODES = ['total', 'business', 'rental', 'forecast'];

    private const AFA_CODES = ['9130', '9134', '9135'];

    public function __construct(private readonly RuleValues $rules = new RuleValues) {}

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $input
     * @return array{kz: array<string, int>, steuerbilanz: array<string, mixed>, e1a_e1b: array<string, array<string, int>>}
     */
    public function build(int $year, string $mode, array $entries, array $input, int $allowanceCents): array
    {
        EurYear::assert($year);

        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(sprintf('Unbekannter Steuer-Modus "%s".', $mode));
        }

        $normalised = $this->normaliseEntries($entries);
        $statement = E1Statement::fromEntries($normalised, $year);
        $e1a = $statement['e1a'];
        $e1b = $statement['e1b'];

        $income = (int) ($statement['income_cents'][E1Statement::GROUP_E1A] ?? 0);
        $expenses = (int) ($statement['expense_cents'][E1Statement::GROUP_E1A] ?? 0);

        $isRentalOnly = $mode === 'rental';
        $businessIncome = $isRentalOnly ? 0 : $income;
        $businessExpenses = $isRentalOnly ? 0 : $expenses;
        $profit = $businessIncome - $businessExpenses;
        $afa = $isRentalOnly ? 0 : $this->sumCodes($e1a, self::AFA_CODES);

        $e40 = $isRentalOnly ? 0 : (int) ($input['arbeitsplatzpauschale_cents'] ?? 0);
        $e39 = $isRentalOnly ? 0 : (int) ($input['ergonomisches_mobiliar_cents'] ?? 0);
        $arbeitszimmer = (int) ($input['arbeitszimmer_cents'] ?? 0);

        return [
            'kz' => [
                '245' => (int) ($input['employment_income_cents'] ?? 0),
                '260' => (int) ($input['wage_tax_cents'] ?? 0),
                'U13' => $this->rentalForecast($mode, $e1b, $input),
                'U26' => (int) ($input['prepayment_cents'] ?? 0),
                'E40' => $e40,
                'E39' => $e39,
            ],
            'steuerbilanz' => [
                'betriebliche_einnahmen_cents' => $businessIncome,
                'betriebliche_ausgaben_cents' => $businessExpenses,
                'gewinn_cents' => $profit,
                'steuerlicher_gewinn_cents' => max(0, $profit - $allowanceCents),
                'afa_cents' => $afa,
                'pauschalen' => $this->pauschalen($year, $e40, $e39, $arbeitszimmer),
            ],
            'e1a_e1b' => [
                E1Statement::GROUP_E1A => $e1a,
                E1Statement::GROUP_E1B => $e1b,
            ],
        ];
    }

    /**
     * EUE-104: die Prognose der Vermietung fließt nur in den Modi mit
     * Vermietungsanteil zu. Ohne expliziten Wert wird sie aus den E1B-Zeilen
     * abgeleitet (Einnahmen − Werbungskosten).
     *
     * @param  array<string, int>  $e1b
     * @param  array<string, mixed>  $input
     */
    private function rentalForecast(string $mode, array $e1b, array $input): int
    {
        if ($mode === 'business') {
            return 0; // EUE-095: "Nur Betrieb" schließt Vermietung aus.
        }

        if (isset($input['rental_forecast_cents'])) {
            return (int) $input['rental_forecast_cents'];
        }

        if ($mode !== 'forecast') {
            return 0;
        }

        return array_sum($e1b);
    }

    /**
     * EUE-101/102: Klassifikation ausschließlich aus den gepinnten
     * Regelparametern; die Bezeichnungen stammen aus der E1A-Ausfüllhilfe.
     *
     * @return array{e40_code: string|null, e40_label: string|null, e39_code: string|null, e39_label: string|null}
     */
    private function pauschalen(int $year, int $e40, int $e39, int $arbeitszimmer): array
    {
        $small = $this->rules->parameterFor('EUE-101', 'Kleine Pauschale bis', $year, 'cents');
        $large = $this->rules->parameterFor('EUE-101', 'Große Pauschale bis', $year, 'cents');
        $minimum = $this->rules->parameterFor('EUE-101', 'Eingabe Minimum', $year, 'cents');
        $maximum = $this->rules->parameterFor('EUE-102', 'Eingabe Maximum', $year, 'cents');

        // EUE-108/EUE-101: 9275 unterdrückt beide Pauschalen.
        if ($arbeitszimmer > 0) {
            return ['e40_code' => null, 'e40_label' => null, 'e39_code' => null, 'e39_label' => null];
        }

        $e40Code = null;

        if ($e40 >= $minimum && $e40 <= $small) {
            $e40Code = '9215';
        } elseif ($e40 > $small && $e40 <= $large) {
            $e40Code = '9217';
        }

        $e39Code = $e39 > 0 && $e39 <= $maximum ? '9216' : null;

        return [
            'e40_code' => $e40Code,
            'e40_label' => $e40Code === null ? null : $this->label($e40Code),
            'e39_code' => $e39Code,
            'e39_label' => $e39Code === null ? null : $this->label('9216'),
        ];
    }

    private function label(string $code): ?string
    {
        $help = E1aHelpTexts::forCode($code);

        return $help === null ? null : $help['bezeichnung'];
    }

    /** @param array<string, int> $values */
    private function sumCodes(array $values, array $codes): int
    {
        $sum = 0;

        foreach ($codes as $code) {
            $sum += $values[$code] ?? 0;
        }

        return $sum;
    }

    /**
     * Das Journal speichert die Richtung als `direction`, die Domäne liest
     * `kind`/`type`. Ohne Normalisierung fallen Einnahmen in E1A in die
     * Ausgaben (EUE-099).
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function normaliseEntries(array $entries): array
    {
        $normalised = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! isset($entry['kind']) && ! isset($entry['type']) && isset($entry['direction'])) {
                $entry['kind'] = $entry['direction'];
            }

            $normalised[] = $entry;
        }

        return $normalised;
    }
}
