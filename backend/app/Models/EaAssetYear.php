<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EAR · Jahresplan eines Anlageguts (AfA, Restbuchwert, Privatnutzung).
 *
 * `scope` folgt dem Stamm; `carry_over_from_year` hält die Herkunft eines
 * fortgeführten Jahres. Die Eigenbeleg-Felder dokumentieren im privaten
 * Verzeichnis die fiktive AfA zum Betriebsanteil — ohne Buchung (D-85).
 */
class EaAssetYear extends Model
{
    protected $fillable = [
        'brand', 'ea_asset_id', 'year', 'scope', 'carry_over_from_year', 'method',
        'depreciation_cents', 'book_value_cents', 'private_use_percent',
        'private_use_cents', 'business_use_cents', 'status', 'ea_entry_id', 'booked_at',
        'eigenbeleg_no', 'theoretical_depreciation_cents', 'usage_percent',
        'eigenbeleg_cents', 'eigenbeleg_created_at',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'scope' => 'string',
        'carry_over_from_year' => 'integer',
        'depreciation_cents' => 'integer',
        'book_value_cents' => 'integer',
        'private_use_percent' => 'integer',
        'private_use_cents' => 'integer',
        'business_use_cents' => 'integer',
        'ea_entry_id' => 'integer',
        'booked_at' => 'datetime',
        'theoretical_depreciation_cents' => 'integer',
        'usage_percent' => 'integer',
        'eigenbeleg_cents' => 'integer',
        'eigenbeleg_created_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(EaAsset::class, 'ea_asset_id');
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
