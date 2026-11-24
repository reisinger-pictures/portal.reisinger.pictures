<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EAR · Belegverknüpfung am stabilen Anlagegut (D-85).
 *
 * Ein Beleg wird einmal pro Anlagegut verknüpft und nie pro Jahr kopiert.
 * Portal #59/D-90: dieselbe Pivot trägt optional die angereicherten
 * Positionsdaten `label`, `amount_cents` und `ordinal`. Bestehende Zeilen
 * behalten NULL-Werte und bleiben gültige Belegzuordnungen.
 */
class EurAssetDocument extends Model
{
    protected $fillable = [
        'brand', 'eur_asset_id', 'eur_document_id', 'label', 'amount_cents', 'ordinal',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'eur_asset_id' => 'integer',
        'eur_document_id' => 'integer',
        'amount_cents' => 'integer',
        'ordinal' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(EurAsset::class, 'eur_asset_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EurDocument::class, 'eur_document_id');
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
