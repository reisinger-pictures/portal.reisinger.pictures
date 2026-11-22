<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EAR · Jahresplan eines Anlageguts (AfA, Restbuchwert, Privatnutzung).
 */
class EurAssetYear extends Model
{
    protected $fillable = [
        'brand', 'eur_asset_id', 'year', 'method', 'depreciation_cents',
        'book_value_cents', 'private_use_percent', 'private_use_cents',
        'business_use_cents', 'status', 'eur_entry_id', 'booked_at',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'depreciation_cents' => 'integer',
        'book_value_cents' => 'integer',
        'private_use_percent' => 'integer',
        'private_use_cents' => 'integer',
        'business_use_cents' => 'integer',
        'eur_entry_id' => 'integer',
        'booked_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(EurAsset::class, 'eur_asset_id');
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
