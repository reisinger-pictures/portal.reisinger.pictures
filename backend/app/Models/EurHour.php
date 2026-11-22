<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** EAR · Stundenbuch; Zeit ist ausschließlich ganze Minuten. */
class EurHour extends Model
{
    protected $fillable = [
        'brand', 'year', 'month', 'day', 'minutes', 'project', 'description',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'month' => 'integer',
        'day' => 'integer',
        'minutes' => 'integer',
    ];

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
