<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** EAR · Monatsabschluss; je Brand/Jahr/Monat genau ein Datensatz. */
class EaMonthlyReport extends Model
{
    protected $fillable = [
        'brand', 'year', 'month', 'status', 'note', 'closed_at',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'month' => 'integer',
        'closed_at' => 'datetime',
    ];

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
