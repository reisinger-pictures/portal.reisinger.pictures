<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EAR · Projekt (EUE-121) mit optionaler Kundenzuordnung.
 */
class EurProject extends Model
{
    protected $fillable = ['brand', 'eur_customer_id', 'name', 'external_id', 'active'];

    protected $casts = [
        'brand' => AsBrand::class,
        'active' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(EurCustomer::class, 'eur_customer_id');
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
