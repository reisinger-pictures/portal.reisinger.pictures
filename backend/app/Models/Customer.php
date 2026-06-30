<?php

namespace App\Models;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Scout\Searchable;

class Customer extends Model
{
    use HasUuids, Searchable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'company',
        'email',
        'street',
        'zip',
        'city',
        'country',
        'uid',
        'brand'
    ];

    protected $casts = ['brand' => Brand::class];

    /**
     * Scope to the current brand (host-derived). See spec §3.3 / §3.4.
     */
    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from . '.brand', BrandRegistry::currentOrDefault()->value);
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'company' => $this->company,
            'email' => $this->email,
            'street' => $this->street,
            'zip' => $this->zip,
            'city' => $this->city,
            'country' => $this->country,
            'uid' => $this->uid,
        ];
    }
}
