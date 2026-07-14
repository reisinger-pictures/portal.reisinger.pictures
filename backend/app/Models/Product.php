<?php

namespace App\Models;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory, HasUuids;
    protected $fillable = ['type', 'name', 'description', 'price', 'brand'];
    protected $casts = ['price' => 'integer', 'brand' => \App\Casts\AsBrand::class];

    /**
     * Scope to the current brand (host-derived). See spec §3.3
     * (features/infrastructure/14-per-brand-catalog.md).
     */
    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from . '.brand', BrandRegistry::currentId());
    }
}
