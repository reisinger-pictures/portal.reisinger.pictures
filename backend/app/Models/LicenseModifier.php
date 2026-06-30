<?php
namespace App\Models;
use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class LicenseModifier extends Model {
    use HasFactory, HasUuids;
    public const UPDATED_AT = null;
    protected $fillable = ['name', 'description', 'percent_surcharge', 'is_included_in_flatrate', 'sort_order', 'brand'];
    protected $casts = ['percent_surcharge' => 'float', 'is_included_in_flatrate' => 'boolean', 'brand' => Brand::class];

    /**
     * Scope to the current brand (host-derived). See spec §3.3.
     */
    public function scopeForCurrentBrand(Builder $query): Builder {
        return $query->where($query->getQuery()->from . '.brand', BrandRegistry::currentOrDefault()->value);
    }
}
