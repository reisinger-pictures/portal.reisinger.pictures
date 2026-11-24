<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Parity\Money;
use App\Eur\Reference\SocialInsurance;
use App\Eur\Reference\YearTables;
use App\Services\SettingResolver;

/**
 * EAR · Zusatzwerte der Jahresübersicht (EUE-107 … EUE-115, EUE-114).
 *
 * Alle Beiträge werden aus den gepinnten Jahrestabellen abgeleitet; es gibt
 * keine neue Regelquelle und keinen Float im Rechenpfad. Der Dienst kennt
 * keine Datenbank — der Controller liefert die brand-scoped Zeilen.
 */
final class EurOverviewService
{
    /** EUE-014: gepinnter Hinweistext aus `Ü!Y10` des ODS-Artefakts. */
    public const MAXIMUM_BASE_HINT = 'Wow! Maximale Beitragsgrundlage erreicht.';

    public function __construct(private readonly SettingResolver $settings) {}

    /**
     * EUE-115: gebuchte 9225-Beiträge werden bei der Gewinnermittlung aus den
     * Ausgaben herausgerechnet — außer bei "Nur Unfallversicherung", wo sie
     * als Ausgabe stehen bleiben.
     */
    public function excludeBookedSvFromExpenses(): bool
    {
        $mode = (string) ($this->settings->get('eur_sv_mode', 'gsvg') ?? 'gsvg');

        return $mode !== SocialInsurance::MODE_UV_ONLY;
    }

    /**
     * EUE-108: Jungunternehmer zahlen den Mindest-KV; nur die Anzeige des
     * KV-Satzes entfällt. Der Mindestbeitrag bleibt davon unberührt.
     */
    public function kvRateHidden(): bool
    {
        $mode = (string) ($this->settings->get('eur_sv_mode', 'gsvg') ?? 'gsvg');

        return $mode === SocialInsurance::MODE_JUNGUNTERNEHMER;
    }

    /**
     * Monatliche SV-Beiträge des Jahres (EUE-109 … EUE-114).
     *
     * Der Est-Modus ist von `eur_sv_mode` verschieden: nur "Nur andere
     * Gewinne" lässt den 9225-Zuschlag zur Beitragsgrundlage entfallen (EUE-115).
     * Ohne übergebenen Modus bleibt das Verhalten aller bisherigen Aufrufer
     * unverändert.
     *
     * @return list<array{month: int, kv_cents: int, pv_cents: int, svs_cents: int, uv_cents: int, base_at_cap_hint: string|null}>
     */
    public function svMonthly(
        int $year,
        int $profitCents,
        int $bookedSvCents,
        string $estMode = 'total',
    ): array {
        $amounts = YearTables::socialInsuranceAmountsCents($year);
        $rates = YearTables::socialInsuranceRatesPer10000($year);
        $mode = (string) ($this->settings->get('eur_sv_mode', 'gsvg') ?? 'gsvg');
        $flatRateOnly = $mode === SocialInsurance::MODE_UV_ONLY;

        // EUE-109/115: Einkünfte + bereits gebuchte 9225 (außer bei Est-Modus
        // "Nur andere Gewinne"), gedeckelt auf 12 × max_bgl.
        $svSurcharge = $estMode === 'other_profits' ? 0 : $bookedSvCents;
        $maximumYearlyBase = $amounts['max_bgl'] * 12;
        $base = min($profitCents + $svSurcharge, $maximumYearlyBase);
        // EUE-014: nur der bereits gedeckelte Wert entscheidet über den
        // Hinweis; es gibt keine zweite Grenze und keinen Float im Rechenpfad.
        $baseHint = $base >= $maximumYearlyBase ? self::MAXIMUM_BASE_HINT : null;

        // EUE-110/108: Jungunternehmer zahlen den Mindest-KV weiter; nur der
        // UV-Modus lässt KV, SVS und PV gemeinsam entfallen.
        $kvYearly = $flatRateOnly ? 0 : max(
            $this->share($base, (int) $rates['kv_satz']),
            $amounts['kv_min'] * 12,
        );

        $pvRateKey = $mode === 'fsvg' ? 'fsvg_pv_satz' : 'gsvg_pv_satz';
        $pvMinKey = match ($mode) {
            'fsvg' => 'fsvg_pv_min',
            'ns_gsvg' => 'gsvg_ns_pv_min',
            default => 'gsvg_pv_min',
        };
        $pvYearly = $flatRateOnly ? 0 : max(
            $this->share($base, (int) $rates[$pvRateKey]),
            $amounts[$pvMinKey] * 12,
        );

        // EUE-112: SVS entfällt bei FSVG und UV; der Mindestwert wird aus dem
        // Mindest-KV und den gepinnten Sätzen abgeleitet (kein fest Betrag).
        $svsYearly = ($mode === 'fsvg' || $flatRateOnly) ? 0 : max(
            $this->share($base, (int) $rates['svs_satz']),
            Money::roundCents(bcdiv(
                bcmul((string) $amounts['kv_min'], (string) ((int) $rates['svs_satz'] * 12)),
                (string) $rates['kv_satz'],
                8,
            )),
        );

        // EUE-022/112: die UV-Pauschale ist in jedem Modus fällig.
        $uvYearly = $amounts['uv_pauschale'] * 12;

        $monthly = [
            'kv_cents' => Money::divideCents($kvYearly, 12),
            'pv_cents' => Money::divideCents($pvYearly, 12),
            'svs_cents' => Money::divideCents($svsYearly, 12),
            'uv_cents' => Money::divideCents($uvYearly, 12),
        ];

        $rows = [];

        for ($month = 1; $month <= 12; $month++) {
            $rows[] = ['month' => $month] + $monthly + ['base_at_cap_hint' => $baseHint];
        }

        return $rows;
    }

    /**
     * @param  list<array{month: int, kv_cents: int, pv_cents: int, svs_cents: int, uv_cents: int}>  $svMonthly
     * @return list<array{label: string, monthly_cents: int}>
     */
    public function insuranceBlocks(array $svMonthly): array
    {
        $first = $svMonthly[0] ?? ['kv_cents' => 0, 'pv_cents' => 0, 'svs_cents' => 0, 'uv_cents' => 0];

        return [
            ['label' => 'Krankenversicherung', 'monthly_cents' => (int) $first['kv_cents']],
            ['label' => 'Pensionsversicherung', 'monthly_cents' => (int) $first['pv_cents']],
            ['label' => 'Selbständigenvorsorge', 'monthly_cents' => (int) $first['svs_cents']],
            ['label' => 'Unfallversicherung', 'monthly_cents' => (int) $first['uv_cents']],
        ];
    }

    /**
     * @param  iterable<mixed>  $assetYears
     * @return array{booking_cents: int, private_cents: int, residual_cents: int}
     */
    public function afaCard(iterable $assetYears): array
    {
        $booking = 0;
        $private = 0;
        $residual = 0;

        foreach ($assetYears as $row) {
            $booking += (int) ($row['business_use_cents'] ?? 0);
            $private += (int) ($row['private_use_cents'] ?? 0);
            $residual += (int) ($row['book_value_cents'] ?? 0);
        }

        return [
            'booking_cents' => $booking,
            'private_cents' => $private,
            'residual_cents' => $residual,
        ];
    }

    /**
     * @param  iterable<mixed>  $entries  Eloquent-Zeilen mit customer/project-Relation
     * @return list<array{customer: string, revenue_cents: int, cost_cents: int, margin_cents: int, project: string}>
     */
    public function byCustomer(iterable $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $customer = $entry->customer?->name ?: 'Ohne Kunde';
            $project = $entry->project?->name ?: 'Ohne Projekt';
            $key = $customer."\0".$project;
            $amount = (int) $entry->business_amount_cents;

            $groups[$key] ??= [
                'customer' => $customer,
                'revenue_cents' => 0,
                'cost_cents' => 0,
                'margin_cents' => 0,
                'project' => $project,
            ];

            if ($entry->direction === 'income') {
                $groups[$key]['revenue_cents'] += $amount;
            } else {
                $groups[$key]['cost_cents'] += $amount;
            }
        }

        $rows = array_values($groups);

        foreach ($rows as &$row) {
            $row['margin_cents'] = $row['revenue_cents'] - $row['cost_cents'];
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => $b['revenue_cents'] <=> $a['revenue_cents']
            ?: strcmp($a['customer'], $b['customer'])
            ?: strcmp($a['project'], $b['project']));

        return $rows;
    }

    /**
     * Ein Status für das Jahr: ohne Signatur "none", gemischt "partial",
     * sonst der gepinnte Status der Signaturen (z. B. "signed").
     *
     * @param  iterable<mixed>  $signatures
     */
    public function signatureStatus(iterable $signatures): string
    {
        $statuses = [];

        foreach ($signatures as $signature) {
            $status = (string) ($signature['status'] ?? '');

            if ($status !== '') {
                $statuses[$status] = true;
            }
        }

        if ($statuses === []) {
            return 'none';
        }

        if (count($statuses) > 1) {
            return 'partial';
        }

        return (string) array_key_first($statuses);
    }

    private function share(int $cents, int $ratePer10000): int
    {
        return Money::applyShare($cents, bcdiv((string) $ratePer10000, '10000', 8));
    }
}
