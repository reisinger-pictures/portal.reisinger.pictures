<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EAR · privater Eigenbeleg einer Anlagegruppe je Jahr (Portal #57).
 *
 * Die Gruppe ist eine eigene Abrechnungseinheit: statt eines Eigenbelegs je
 * Mitglied entsteht genau eine Dokumentation pro Gruppe und Jahr. Der Betrag
 * bleibt in ganzen Cent; es gibt keine Journalbuchung und keine Gewinnminderung.
 */
class EurAssetGroupYear extends Model
{
    protected $fillable = [
        'brand', 'eur_asset_group_id', 'year', 'depreciation_cents',
        'book_value_cents', 'eigenbeleg_no', 'theoretical_depreciation_cents',
        'usage_percent', 'eigenbeleg_cents', 'eigenbeleg_created_at', 'status',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'depreciation_cents' => 'integer',
        'book_value_cents' => 'integer',
        'theoretical_depreciation_cents' => 'integer',
        'usage_percent' => 'integer',
        'eigenbeleg_cents' => 'integer',
        'eigenbeleg_created_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(EurAssetGroup::class, 'eur_asset_group_id');
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
