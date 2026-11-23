<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EAR · Anlagegut (EUE-049 … EUE-061, DEG-001 … DEG-015).
 *
 * Kosten in ganzen Cent, Nutzungsdauer in Jahren, Betriebsanteil als ganze
 * Prozentzahl (0–100, 5-%-Schritte) und daraus serverseitig abgeleitetes
 * `scope` (`business` ab 50 %, sonst `private`). Die Methode ist linear oder degressiv;
 * ein Wechsel zur linearen AfA wird als Jahresplan (`eur_asset_years`)
 * festgehalten und ist nur einmal zulässig.
 */
class EurAsset extends Model
{
    protected $fillable = [
        'brand', 'name', 'invoice_no', 'supplier', 'purchase_date', 'cost_cents',
        'useful_life_years', 'business_share', 'scope', 'method', 'degressive_rate',
        'category', 'is_private_investment', 'switched_to_linear_at',
        'switch_reason', 'status', 'disposed_on', 'disposal_proceeds_cents', 'notes',
        'is_used', 'is_immaterial', 'immaterial_exception', 'special_depreciation',
        'is_electric_vehicle',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'purchase_date' => 'date',
        'disposed_on' => 'date',
        'cost_cents' => 'integer',
        'useful_life_years' => 'integer',
        'business_share' => 'integer',
        'scope' => 'string',
        'degressive_rate' => 'integer',
        'is_private_investment' => 'boolean',
        'switched_to_linear_at' => 'datetime',
        'disposal_proceeds_cents' => 'integer',
        'is_used' => 'boolean',
        'is_immaterial' => 'boolean',
        'special_depreciation' => 'boolean',
        'is_electric_vehicle' => 'boolean',
    ];

    public function years(): HasMany
    {
        return $this->hasMany(EurAssetYear::class)->orderBy('year');
    }

    /**
     * Belege gehören zum stabilen Stamm, nicht zum Jahresplan (D-85).
     */
    public function assetDocuments(): BelongsToMany
    {
        return $this->belongsToMany(
            EurDocument::class,
            'eur_asset_documents',
            'eur_asset_id',
            'eur_document_id',
        )->withPivot('brand')->withTimestamps();
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
