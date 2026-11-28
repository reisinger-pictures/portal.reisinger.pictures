<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** EAR · Wareneingang; Beträge sind ganzzahlige Cent. */
class EaWarehouseEntry extends Model
{
    protected $fillable = [
        'brand', 'year', 'month', 'day', 'description', 'supplier', 'expense_kind',
        'amount_cents', 'business_share', 'receipt_no',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'month' => 'integer',
        'day' => 'integer',
        'amount_cents' => 'integer',
        'business_share' => 'integer',
    ];

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
