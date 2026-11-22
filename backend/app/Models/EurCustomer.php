<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EÜR · Kunde (EUE-006, Erweiterung).
 */
class EurCustomer extends Model
{
    protected $fillable = ['brand', 'name', 'external_id', 'active'];

    protected $casts = [
        'brand' => AsBrand::class,
        'active' => 'boolean',
    ];

    public function projects(): HasMany
    {
        return $this->hasMany(EurProject::class);
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
