<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** EAR · gespeicherte Reisezeile; Geld ausschließlich ganze Cent. */
class EurTravelTrip extends Model
{
    protected $fillable = [
        'brand', 'year', 'month', 'receipt_no', 'vehicle', 'kilometres',
        'start_km', 'end_km', 'passengers', 'country', 'purpose', 'e1a_code',
        'days', 'meals', 'with_receipts', 'business_share',
        'kilometre_cents', 'day_cents', 'night_cents', 'total_cents',
        'business_cents', 'note',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'month' => 'integer',
        'vehicle' => 'string',
        'kilometres' => 'integer',
        'start_km' => 'integer',
        'end_km' => 'integer',
        'passengers' => 'integer',
        'country' => 'string',
        'days' => 'integer',
        'meals' => 'integer',
        'with_receipts' => 'boolean',
        'business_share' => 'integer',
        'kilometre_cents' => 'integer',
        'day_cents' => 'integer',
        'night_cents' => 'integer',
        'total_cents' => 'integer',
        'business_cents' => 'integer',
    ];

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
