<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'brand'];

    protected $casts = ['brand' => AsBrand::class];

    /**
     * Scope to the current brand (host-derived). See spec §3.2 / §3.3.
     */
    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }

    /**
     * The DB primary key is composite `(key, brand)`, but Eloquent only knows
     * the single `key` column. Scope the save query by brand as well so that
     * updating one brand never touches another brand's row for the same key.
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where($this->getKeyName(), '=', $this->getKeyForSaveQuery())
            ->where('brand', '=', $this->brandForQuery());
    }

    /**
     * Same composite-key handling for select queries (e.g. refresh()/fresh()).
     */
    protected function setKeysForSelectQuery($query)
    {
        return $query
            ->where($this->getKeyName(), '=', $this->getKeyForSelectQuery())
            ->where('brand', '=', $this->brandForQuery());
    }

    private function brandForQuery(): ?string
    {
        return $this->getRawOriginal('brand') ?? $this->getAttributeFromArray('brand');
    }
}
