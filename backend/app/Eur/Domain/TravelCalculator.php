<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use App\Eur\Parity\Money;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\TravelAllowances;
use App\Eur\Reference\YearTables;
use InvalidArgumentException;

/**
 * EAR · Reise- und Fahrtkosten (EUE-062 … EUE-081).
 *
 * Kilometergeld, Mitfahrerzuschlag, Kontingente und Zeitgrenzen kommen aus dem
 * gepinnten Regelkatalog; Tag-/Nächtigungssätze aus der Ländertabelle. Alle
 * Beträge sind ganze Cent, Zeiten ganze Minuten, Kilometer ganze Zahlen.
 */
final class TravelCalculator
{
    public function __construct(
        private readonly RuleValues $rules,
        private readonly TravelAllowances $allowances,
    ) {}

    /**
     * EUE-062/063/064: Kilometergeld je Transportmittel, Jahr und Halbjahr in Cent.
     *
     * Die H1/H2-Sätze 2025 (30.06./01.07.) liegen als strukturierter Wert im
     * Katalog und können von `RuleValues::parseValue()` nicht gelesen werden.
     * `YearTables` löst sie nach Jahr und Monat auf; das verhindert HTTP 500
     * für das API-erlaubte Jahr 2025.
     */
    public function kilometreRateCents(string $vehicle, int $year = 2026, ?int $month = null): int
    {
        return YearTables::travelPerKmCents($vehicle, $year, $month);
    }

    /** EUE-062/063: Mitfahrerzuschlag je Person und Kilometer in Cent. */
    public function passengerSurchargeCents(string $vehicle, int $year = 2026): int
    {
        return match ($this->vehicleKind($vehicle)) {
            'pkw' => $this->rules->parameterFor('EUE-062', 'Mitfahrerzuschlag PKW', $year, 'cents'),
            'motorrad' => $this->rules->parameterFor('EUE-063', 'Mitfahrerzuschlag Motorrad', $year, 'cents'),
            default => 0,
        };
    }

    /** EUE-066: höchstzulässige Mitfahrer je Transportmittel. */
    public function maximumPassengers(string $vehicle, int $year = 2026): int
    {
        return match ($this->vehicleKind($vehicle)) {
            'pkw' => $this->rules->parameterFor('EUE-066', 'Max Mitfahrer PKW', $year, 'count'),
            'motorrad' => $this->rules->parameterFor('EUE-066', 'Max Mitfahrer Motorrad', $year, 'count'),
            default => 0,
        };
    }

    /** EUE-065: Jahreskontingent in Kilometern. */
    public function kilometreQuota(string $vehicle, int $year = 2026): int
    {
        return $this->vehicleKind($vehicle) === 'fahrrad'
            ? $this->rules->parameterFor('EUE-065', 'Fahrrad-Kontingent', $year, 'count')
            : $this->rules->parameterFor('EUE-065', 'KFZ-Kontingent', $year, 'count');
    }

    /**
     * EUE-062 … EUE-064: Kilometergeld inklusive Mitfahrerzuschlag.
     */
    public function kilometreAllowanceCents(
        string $vehicle,
        int $kilometres,
        int $passengers = 0,
        int $year = 2026,
        ?int $month = null,
    ): int {
        if ($kilometres < 0) {
            throw new InvalidArgumentException('Kilometer dürfen nicht negativ sein.');
        }

        $maximum = $this->maximumPassengers($vehicle, $year);

        if ($passengers < 0 || $passengers > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Mitfahrer für %s außerhalb 0–%d: %d.',
                $vehicle,
                $maximum,
                $passengers,
            ));
        }

        $rate = $this->kilometreRateCents($vehicle, $year, $month)
            + $passengers * $this->passengerSurchargeCents($vehicle, $year);

        return $kilometres * $rate;
    }

    /**
     * EUE-067: Entfernung aus Kilometerstand oder Distanz, nie beides.
     */
    public function distance(?int $startKilometres, ?int $endKilometres, ?int $distanceKilometres): int
    {
        $hasReadings = $startKilometres !== null && $endKilometres !== null;
        $hasDistance = $distanceKilometres !== null;

        if ($hasReadings === $hasDistance) {
            throw new InvalidArgumentException('Entweder Kilometerstand oder Distanz angeben, nicht beides.');
        }

        if ($hasDistance) {
            if ($distanceKilometres < 0) {
                throw new InvalidArgumentException('Distanz darf nicht negativ sein.');
            }

            return $distanceKilometres;
        }

        if ($startKilometres === null || $endKilometres === null || $endKilometres < $startKilometres) {
            throw new InvalidArgumentException('Der Endkilometerstand liegt vor dem Anfangsstand.');
        }

        return $endKilometres - $startKilometres;
    }

    /**
     * EUE-068: Tagesgeld nach Mahlzeitenabzug.
     *
     * Inland: eine Mahlzeit halbiert, zwei Mahlzeiten streichen das Tagesgeld.
     * Ausland: eine Mahlzeit lässt die volle Pauschale, zwei ein Drittel.
     */
    public function perDiemCents(int $dayRateCents, int $meals, bool $domestic): int
    {
        if ($meals < 0 || $meals > 2) {
            throw new InvalidArgumentException('Mahlzeiten müssen 0, 1 oder 2 sein.');
        }

        $factor = match (true) {
            $meals === 0 => '1',
            $domestic && $meals === 1 => '0.5',
            $domestic && $meals === 2 => '0',
            ! $domestic && $meals === 1 => '1',
            default => '0.333333333333',
        };

        return Money::applyShare($dayRateCents, $factor);
    }

    /**
     * EUE-068/069: Tagesgeld und Nächtigung für eine Reise.
     *
     * @return array{day_cents: int, night_cents: int}
     */
    public function dailyAllowancesCents(
        string $country,
        int $days,
        int $meals,
        bool $withReceipts,
        int $year = 2026,
    ): array {
        if ($days < 1) {
            throw new InvalidArgumentException('Eine Reise dauert mindestens einen Tag.');
        }

        $rates = $this->allowances->for($country, $year);
        $domestic = $this->isDomestic($country);

        // EUE-070: ab dem 5. Tag entfällt das Tagesgeld, ab dem 15. die Nächtigung.
        $paidDays = min($days, $this->rules->parameterFor('EUE-070', 'Tagesgeld-Grenze', $year, 'count') - 1);
        $paidNights = min($days, $this->rules->parameterFor('EUE-070', 'Nächtigungsgeld-Grenze', $year, 'count') - 1);

        $day = $paidDays > 0 ? $this->perDiemCents($rates['day_cents'], $meals, $domestic) * $paidDays : 0;

        $nightRate = $withReceipts
            ? $rates['night_cents']
            : $this->nightWithoutReceiptCents($domestic, $year);

        return [
            'day_cents' => $day,
            'night_cents' => $nightRate * $paidNights,
        ];
    }

    /** EUE-069: Nächtigungspauschale ohne Beleg. */
    public function nightWithoutReceiptCents(bool $domestic, int $year = 2026): int
    {
        return $domestic
            ? $this->rules->parameterFor('EUE-069', 'Inland Nächtigung ohne Beleg', $year, 'cents')
            : $this->rules->parameterFor('EUE-069', 'Ausland Nächtigung Pauschale', $year, 'cents');
    }

    /**
     * EUE-081: betriebliche Reiseausgabe = Fahrtkosten + Tagesgeld + Nächtigung.
     */
    public function businessTravelCostsCents(
        string $vehicle,
        int $kilometres,
        int $passengers,
        string $country,
        int $days,
        int $meals,
        bool $withReceipts,
        int $businessSharePercent,
        int $year = 2026,
        ?int $month = null,
    ): int {
        $this->assertShare($businessSharePercent);

        $total = $this->kilometreAllowanceCents($vehicle, $kilometres, $passengers, $year, $month);
        $daily = $this->dailyAllowancesCents($country, $days, $meals, $withReceipts, $year);

        return Money::applyShare(
            $total + $daily['day_cents'] + $daily['night_cents'],
            bcdiv((string) $businessSharePercent, '100', 8),
        );
    }

    /**
     * EUE-072: Betriebsanteil aus betrieblicher und gesamter Reisezeit.
     */
    public function businessSharePercent(int $businessMinutes, int $totalMinutes): int
    {
        if ($totalMinutes <= 0 || $businessMinutes < 0 || $businessMinutes > $totalMinutes) {
            throw new InvalidArgumentException('Reisezeiten sind unplausibel.');
        }

        $share = Money::roundCents(bcdiv((string) ($businessMinutes * 100), (string) $totalMinutes, 8));

        if ($share < 10) {
            return 0;
        }

        if ($share >= 90) {
            return 100;
        }

        return $share;
    }

    /**
     * EUE-071: Hin-/Rückfahrt — kein Tagesgeld, Mindestentfernung je Zeilenart.
     *
     * Die ODS stuft eine Zeile als Hin-/Rückfahrt ein, wenn die Reisezeit
     * mindestens drei Stunden beträgt (AJ3) — alternativ die Stunden des
     * Belegs (AE3) — und die Mindestentfernung erreicht ist. Die frühere
     * Bedingung war invertiert und wertete 60-Minuten-Fahrten als Reise.
     */
    public function isTravelLeg(
        int $minutes,
        int $kilometres,
        bool $chained,
        int $ledgerMinutes = 0,
    ): bool {
        $minimum = $this->rules->parameterFor(
            'EUE-071',
            $chained ? 'Hinfahrt-Minimum verkettet' : 'Hinfahrt-Minimum Einzelzeile',
            2026,
            'count',
        );

        $timeLimit = $this->rules->parameterFor('EUE-071', 'Zeitgrenze Hin-/Rückfahrt', 2026, 'count') * 60;

        return ($minutes >= $timeLimit || $ledgerMinutes >= $timeLimit)
            && $kilometres >= $minimum;
    }

    /**
     * EUE-075 … EUE-079: Pflicht- und Plausibilitätsprüfung einer Reisezeile.
     *
     * @return list<string> deutsche Hinweise; leer bedeutet gültig
     */
    public function validateTrip(array $trip): array
    {
        $warnings = [];
        $year = $trip['year'] ?? null;
        $start = $trip['start'] ?? null;
        $end = $trip['end'] ?? null;

        if ($year !== 2026) {
            $warnings[] = 'Das Reisejahr muss dem Wirtschaftsjahr 2026 entsprechen.';
        }

        if (! is_string($start) || ! is_string($end) || $end <= $start) {
            $warnings[] = 'Das Reiseende muss nach dem Reisestart liegen.';
        }

        if (trim((string) ($trip['receipt_no'] ?? '')) === '') {
            $warnings[] = 'Belegnummer fehlt. Bitte eine Belegnummer vergeben.';
        }

        if (trim((string) ($trip['country'] ?? '')) === '') {
            $warnings[] = 'Reiseziel fehlt. Bitte Land oder Region auswählen.';
        }

        if (trim((string) ($trip['vehicle'] ?? '')) === '') {
            $warnings[] = 'Transportmittel fehlt. Bitte ein Fahrzeug auswählen.';
        }

        if (($trip['leg'] ?? false) === true
            && (trim((string) ($trip['purpose'] ?? '')) !== '' || trim((string) ($trip['location'] ?? '')) !== '')) {
            $warnings[] = 'Bei einer Hin- oder Rückfahrt bleiben Veranlassung und Einsatzort leer.';
        }

        return $warnings;
    }

    /** EUE-073: private Veranlassung schließt den Abzug aus. */
    public function isPrivateTrip(string $purpose): bool
    {
        return mb_strtolower(trim($purpose), 'UTF-8') === 'privat';
    }

    private function isDomestic(string $country): bool
    {
        return mb_strtolower(trim($country), 'UTF-8') === 'österreich';
    }

    private function vehicleKind(string $vehicle): string
    {
        $normalized = mb_strtolower(trim($vehicle), 'UTF-8');

        return match (true) {
            str_contains($normalized, 'motorrad') => 'motorrad',
            str_contains($normalized, 'fahrrad'), str_contains($normalized, 'e-bike') => 'fahrrad',
            default => 'pkw',
        };
    }

    private function assertShare(int $percent): void
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException(sprintf('Betriebsanteil außerhalb 0–100 %%: %d.', $percent));
        }
    }
}
