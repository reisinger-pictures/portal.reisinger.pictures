<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use App\Eur\Parity\Money;
use InvalidArgumentException;
use RuntimeException;

/**
 * EÜR · Sozialversicherungswerte (EUE-014 … EUE-022, EUE-109 … EUE-114).
 *
 * Die Tabelle `sv_werte` wird an dieser einen Lesegrenze in ganze Cent bzw.
 * Basispunkte übersetzt. Die Werte sind jahresabhängig gepinnt; ein anderes
 * Wirtschaftsjahr wird abgewiesen, statt einen stillen Default zu liefern.
 */
final class SocialInsurance
{
    /** @var array<string, int> */
    private array $amountsCents = [];

    /** @var array<string, int> */
    private array $ratesPer10000 = [];

    /**
     * @param  array<string, int|float>  $values
     */
    public function __construct(array $values, private readonly int $businessYear)
    {
        if ($values === []) {
            throw new InvalidArgumentException('SV-Werte sind leer.');
        }

        foreach ($values as $key => $value) {
            if (str_ends_with((string) $key, '_satz')) {
                $this->ratesPer10000[(string) $key] = Money::fractionToPer10000($value);

                continue;
            }

            $this->amountsCents[(string) $key] = Money::eurosToCents($value);
        }
    }

    public function businessYear(): int
    {
        return $this->businessYear;
    }

    /**
     * @return array<string, int>
     */
    public function amountsCents(): array
    {
        return $this->amountsCents;
    }

    /**
     * @return array<string, int>
     */
    public function ratesPer10000(): array
    {
        return $this->ratesPer10000;
    }

    public function amountCents(string $key): int
    {
        return $this->lookup($this->amountsCents, $key);
    }

    public function ratePer10000(string $key): int
    {
        return $this->lookup($this->ratesPer10000, $key);
    }

    /** Maximale monatliche Beitragsgrundlage (EUE-014). */
    public function maximumMonthlyBaseCents(): int
    {
        return $this->amountCents('max_bgl');
    }

    /** Maximale jährliche Beitragsgrundlage = 12 × Monatswert (EUE-109). */
    public function maximumYearlyBaseCents(): int
    {
        return $this->maximumMonthlyBaseCents() * 12;
    }

    /** Mindest-KV-Beitrag je Monat (EUE-016/110). */
    public function minimumHealthMonthlyCents(): int
    {
        return $this->amountCents('kv_min');
    }

    public function minimumHealthYearlyCents(): int
    {
        return $this->minimumHealthMonthlyCents() * 12;
    }

    /** Mindest-PV-Beitrag je Monat, modusabhängig (EUE-017/018/111). */
    public function minimumPensionMonthlyCents(string $mode): int
    {
        $key = match ($mode) {
            'fsvg' => 'fsvg_pv_min',
            'ns_gsvg' => 'gsvg_ns_pv_min',
            'gsvg' => 'gsvg_pv_min',
            default => throw new InvalidArgumentException(sprintf('Unbekannter SV-Modus "%s".', $mode)),
        };

        return $this->amountCents($key);
    }

    /** Jährliche Unfallversicherungspauschale (EUE-022/112). */
    public function accidentYearlyCents(): int
    {
        return $this->amountCents('uv_pauschale');
    }

    public function healthRatePer10000(): int
    {
        return $this->ratePer10000('kv_satz');
    }

    public function pensionRatePer10000(string $mode): int
    {
        $key = $mode === 'fsvg' ? 'fsvg_pv_satz' : 'gsvg_pv_satz';

        return $this->ratePer10000($key);
    }

    public function provisionRatePer10000(): int
    {
        return $this->ratePer10000('svs_satz');
    }

    /**
     * @param  array<string, int>  $values
     */
    private function lookup(array $values, string $key): int
    {
        if (! isset($values[$key])) {
            throw new RuntimeException(sprintf('SV-Wert "%s" fehlt im gepinnten Artefakt.', $key));
        }

        return $values[$key];
    }
}
