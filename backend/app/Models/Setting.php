<?php

namespace App\Models;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'brand'];
    protected $casts = ['brand' => \App\Casts\AsBrand::class];

    /**
     * Scope to the current brand (host-derived). See spec §3.2 / §3.3.
     */
    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from . '.brand', BrandRegistry::currentId());
    }
}
