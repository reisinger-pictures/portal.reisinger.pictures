<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** EAR · Antrag als fachlicher Vorgang. */
class EaApplication extends Model
{
    protected $fillable = [
        'brand', 'year', 'type', 'subject', 'status', 'note',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
    ];

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
