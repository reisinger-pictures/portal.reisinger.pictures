<?php

declare(strict_types=1);

namespace App\Eur\Support;

use App\Models\EurAsset;
use App\Models\EurAssetDocument;

/**
 * EAR · Positionsschlüssel eines Anlageguts (Portal #59/D-90).
 *
 * Beim Beleg-Split teilen sich mehrere Güter dieselbe Belegnummer. Der
 * stabile, aus der Pivot abgeleitete Schlüssel `documentId-ordinal` hält
 * Entnahme-Anlage, -Löschung und -Status auseinander. Ohne Position bleibt
 * der Wert null; das klassische Verhalten (eine Entnahme je Belegnummer)
 * bleibt damit exakt erhalten.
 */
final class EurAssetPositionKey
{
    public static function forAsset(EurAsset $asset): ?string
    {
        $pivot = EurAssetDocument::forCurrentBrand()
            ->where('eur_asset_id', $asset->id)
            ->whereNotNull('ordinal')
            ->orderBy('ordinal')
            ->first();

        return $pivot === null
            ? null
            : sprintf('%d-%d', $pivot->eur_document_id, $pivot->ordinal);
    }
}
