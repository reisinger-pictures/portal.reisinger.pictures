<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetGroup;
use App\Models\EurDocument;

/**
 * EAR · Lesemodell einer Anlagegruppe (Portal #57, #59).
 *
 * Belege werden transitiv aus den verknüpften Gütern abgeleitet; es gibt
 * keinen group↔document-Store. Die Antwort enthält nur Metadaten, nie Dateipfade.
 */
final class EurAssetGroupSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(EurAssetGroup $group): array
    {
        return [
            'id' => (int) $group->id,
            'name' => $group->name,
            'business_share' => (int) $group->business_share,
            'scope' => (int) $group->business_share < 50 ? 'private' : 'business',
            'asset_ids' => $group->assets->map(fn (EurAsset $asset): int => (int) $asset->id)->values()->all(),
            'assets' => $group->assets->map(fn (EurAsset $asset): array => [
                'id' => (int) $asset->id,
                'name' => $asset->name,
                'invoice_no' => $asset->invoice_no,
                'cost_cents' => (int) $asset->cost_cents,
            ])->values()->all(),
            // Portal #59/D-90: transitiv über die Mitglieder.
            'document_ids' => $group->documentIds(),
            'documents' => $group->documents()->map(fn (EurDocument $document): array => [
                'id' => (int) $document->id,
                'receipt_no' => $document->receipt_no,
                'original_name' => $document->original_name,
            ])->values()->all(),
            'years' => $group->years->map(fn ($year): array => [
                'year' => (int) $year->year,
                'eigenbeleg_no' => $year->eigenbeleg_no,
                'eigenbeleg_cents' => $year->eigenbeleg_cents === null ? null : (int) $year->eigenbeleg_cents,
                'usage_percent' => $year->usage_percent === null ? null : (int) $year->usage_percent,
            ])->values()->all(),
        ];
    }
}
