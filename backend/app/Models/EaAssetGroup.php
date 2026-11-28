<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EAR · Anlagegruppe als eigene Abrechnungseinheit (Portal #57, D-85/EUE-055).
 *
 * Eine Gruppe bündelt mehrere wirtschaftlich zusammengehörige Anlagegüter.
 * Der Betriebsanteil liegt am Gruppenstamm und gilt für alle Mitglieder; die
 * Gruppe erzeugt EINE Entnahme (betrieblich) bzw. EINEN Eigenbeleg je Jahr
 * (privat) über die Summe der Anschaffungskosten. Belege bleiben an den
 * einzelnen Gütern — die Gruppe ist keine Belegeinheit.
 */
class EaAssetGroup extends Model
{
    protected $fillable = [
        'brand', 'name', 'business_share',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'business_share' => 'integer',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(EaAsset::class, 'ea_asset_group_id')->orderBy('id');
    }

    public function years(): HasMany
    {
        return $this->hasMany(EaAssetGroupYear::class, 'ea_asset_group_id')->orderBy('year');
    }

    /**
     * Portal #59/D-90: Belege gehören transitiv zu den Mitgliedern, nie direkt
     * zur Gruppe. Nur die Pivot `ea_asset_documents` ist die Wahrheitsquelle.
     *
     * @return list<int>
     */
    public function documentIds(): array
    {
        $assetIds = $this->assets()->pluck('id');

        return EaAssetDocument::forCurrentBrand()
            ->whereIn('ea_asset_id', $assetIds)
            ->pluck('ea_document_id')
            ->unique()
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, EaDocument>
     */
    public function documents(): Collection
    {
        return EaDocument::forCurrentBrand()
            ->whereIn('id', $this->documentIds())
            ->orderBy('id')
            ->get();
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
