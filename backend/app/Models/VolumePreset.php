<?php
namespace App\Models;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named volume-licensing price model (preset) for a brand.
 *
 * Exactly one preset per brand carries `is_default = true`; the invariant is
 * enforced in `VolumePresetService` (a partial DB index is not portable to
 * MySQL). Galleries may reference a preset via `galleries.volume_preset_id`
 * (null = brand default).
 */
class VolumePreset extends Model
{
    protected $fillable = ['brand', 'name', 'is_default'];

    protected $casts = [
        'is_default' => 'boolean',
        'brand' => \App\Casts\AsBrand::class,
    ];

    public function tiers(): HasMany
    {
        return $this->hasMany(VolumePresetTier::class)->orderBy('position');
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from . '.brand', BrandRegistry::currentId());
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public static function forBrand(Brand|string $brand): ?self
    {
        $value = $brand instanceof Brand ? $brand->value : $brand;
        return static::query()->where('brand', $value)->first();
    }
}
