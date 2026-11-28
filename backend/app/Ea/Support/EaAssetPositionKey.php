<?php

declare(strict_types=1);

namespace App\Ea\Support;

use App\Models\EaAsset;
use App\Models\EaAssetDocument;

/**
 * EAR · Positionsschlüssel eines Anlageguts (Portal #59/D-90).
 *
 * Beim Beleg-Split teilen sich mehrere Güter dieselbe Belegnummer. Der
 * stabile, aus der Pivot abgeleitete Schlüssel `documentId-ordinal` hält
 * Entnahme-Anlage, -Löschung und -Status auseinander. Ohne Position bleibt
 * der Wert null; das klassische Verhalten (eine Entnahme je Belegnummer)
 * bleibt damit exakt erhalten.
 */
final class EaAssetPositionKey
{
    public static function forAsset(EaAsset $asset): ?string
    {
        $pivot = EaAssetDocument::forCurrentBrand()
            ->where('ea_asset_id', $asset->id)
            ->whereNotNull('ordinal')
            ->orderBy('ordinal')
            ->first();

        return $pivot === null
            ? null
            : sprintf('%d-%d', $pivot->ea_document_id, $pivot->ordinal);
    }
}
