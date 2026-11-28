<?php

declare(strict_types=1);

namespace App\Services\Ea;

use App\Ea\Support\Share;
use App\Models\EaAsset;
use App\Models\EaAssetYear;

/**
 * EAR · automatischer Eigenbeleg im privaten Anlagenverzeichnis (D-85, EUE-055).
 *
 * Der Eigenbeleg dokumentiert die fiktive AfA des Jahres nur für den
 * betrieblichen Nutzungsanteil (`100 - private_use_percent`). Es entsteht
 * keine Journalbuchung, kein Aufwand und keine Gewinnminderung. Der Eigentümer
 * hat die Formel am 2026-11-24 ausdrücklich bestätigt: sein Beispiel
 * „5 Jahre, 30 % beruflich" ergibt 20 % × 30 % = 6 % des Anschaffungswerts.
 *
 * Der Service wird beim Anlegen des AfA-Plans und beim Jahreswechsel
 * aufgerufen, also ohne Benutzeraktion. Er ist über `eigenbeleg_created_at`
 * idempotent; ein bereits erstellter Eigenbeleg wird nie überschrieben.
 * Alle Beträge bleiben ganze Cent.
 */
final class EaAssetEigenbelegService
{
    /**
     * @return array{eigenbeleg: array<string, mixed>, already_created: bool}
     */
    public function createForYear(EaAsset $asset, EaAssetYear $year): array
    {
        if ($year->eigenbeleg_created_at !== null) {
            return ['eigenbeleg' => $this->serialize($year), 'already_created' => true];
        }

        $theoreticalCents = (int) $year->depreciation_cents;
        $usagePercent = 100 - (int) $year->private_use_percent;

        $year->forceFill([
            'eigenbeleg_no' => sprintf('EB-%d-%d', $asset->id, $year->year),
            'theoretical_depreciation_cents' => $theoreticalCents,
            'usage_percent' => $usagePercent,
            'eigenbeleg_cents' => Share::apply($theoreticalCents, $usagePercent),
            'eigenbeleg_created_at' => now(),
        ])->save();

        return ['eigenbeleg' => $this->serialize($year->refresh()), 'already_created' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(EaAssetYear $year): array
    {
        return [
            'year' => (int) $year->year,
            'eigenbeleg_no' => $year->eigenbeleg_no,
            'theoretical_depreciation_cents' => $year->theoretical_depreciation_cents === null
                ? null : (int) $year->theoretical_depreciation_cents,
            'usage_percent' => $year->usage_percent === null ? null : (int) $year->usage_percent,
            'eigenbeleg_cents' => $year->eigenbeleg_cents === null ? null : (int) $year->eigenbeleg_cents,
            'eigenbeleg_created_at' => $year->eigenbeleg_created_at?->toIso8601String(),
        ];
    }
}
