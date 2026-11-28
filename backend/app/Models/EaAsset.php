<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EAR · Anlagegut (EUE-049 … EUE-061, DEG-001 … DEG-015).
 *
 * Kosten in ganzen Cent, Nutzungsdauer in Jahren, Betriebsanteil als ganze
 * Prozentzahl (0–100, 5-%-Schritte) und daraus serverseitig abgeleitetes
 * `scope` (`business` ab 50 %, sonst `private`). Die Methode ist linear oder degressiv;
 * ein Wechsel zur linearen AfA wird als Jahresplan (`ea_asset_years`)
 * festgehalten und ist nur einmal zulässig.
 */
class EaAsset extends Model
{
    protected $fillable = [
        'brand', 'name', 'invoice_no', 'supplier', 'purchase_date', 'cost_cents',
        'useful_life_years', 'business_share', 'scope', 'ea_asset_group_id', 'method', 'degressive_rate',
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
        'ea_asset_group_id' => 'integer',
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
        return $this->hasMany(EaAssetYear::class)->orderBy('year');
    }

    /**
     * Portal #57: die Abrechnungsgruppe; `null` bedeutet Einzelabrechnung.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(EaAssetGroup::class, 'ea_asset_group_id');
    }

    /**
     * D-89: der Gruppenanteil überschreibt den des Guts für Scope und alle
     * Berechnungen; der eigene Anteil bleibt als Information erhalten. Die
     * Gruppenzuordnung wird dabei auf die aktuelle Marke eingeschränkt.
     */
    public function effectiveBusinessShare(): int
    {
        if ($this->ea_asset_group_id === null) {
            return (int) $this->business_share;
        }

        $group = EaAssetGroup::forCurrentBrand()->find($this->ea_asset_group_id);

        return $group === null ? (int) $this->business_share : (int) $group->business_share;
    }

    /**
     * Belege gehören zum stabilen Stamm, nicht zum Jahresplan (D-85).
     */
    public function assetDocuments(): BelongsToMany
    {
        return $this->belongsToMany(
            EaDocument::class,
            'ea_asset_documents',
            'ea_asset_id',
            'ea_document_id',
        )->withPivot('brand')->withTimestamps();
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
