<?php

namespace App\Models;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Coupon / Discount Code Model (SRP-01).
 *
 * Supports fixed-amount and percentage discount types.
 * Coupons are brand-isolated and can be scoped globally, to a gallery,
 * to a meta-gallery (gallery group), or to a photographer's galleries.
 *
 * @property int $id
 * @property string $brand
 * @property string $code
 * @property string $type fixed|percentage
 * @property float $value
 * @property int|null $max_items
 * @property string $scope_type global|gallery|meta_gallery|photographer|organisation
 * @property int|null $scope_id
 * @property int|null $max_uses_global
 * @property int|null $max_uses_per_account
 * @property int $used_count
 * @property int|null $created_by
 * @property string|null $expires_at
 * @property bool $active
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Coupon extends Model
{
    use HasFactory;

    protected $table = 'coupons';

    protected $fillable = [
        'brand',
        'code',
        'type',
        'value',
        'max_items',
        'scope_type',
        'scope_id',
        'max_uses_global',
        'max_uses_per_account',
        'used_count',
        'created_by',
        'expires_at',
        'active',
    ];

    protected $casts = [
        'value' => 'float',
        'max_items' => 'integer',
        'scope_id' => 'string',
        'max_uses_global' => 'integer',
        'max_uses_per_account' => 'integer',
        'used_count' => 'integer',
        'created_by' => 'string',
        'active' => 'boolean',
        'expires_at' => 'datetime',
        'brand' => Brand::class,
    ];

    // ──────────────────────────────────────────────
    //  Relations
    // ──────────────────────────────────────────────

    public function usageByUser(): HasMany
    {
        return $this->hasMany(CouponUserUsage::class, 'coupon_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────────────
    //  Scopes
    // ──────────────────────────────────────────────

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from . '.brand', BrandRegistry::currentOrDefault()->value);
    }

    public function scopeByBrand(Builder $query, Brand $brand): Builder
    {
        return $query->where($query->getQuery()->from . '.brand', $brand->value);
    }

    public function scopeForCreator(Builder $query, User $user): Builder
    {
        return $query->where('coupons.created_by', $user->id);
    }

    // ──────────────────────────────────────────────
    //  Validation Helpers
    // ──────────────────────────────────────────────

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return Carbon::parse($this->expires_at)->isPast();
    }

    public function isGloballyMaxedOut(): bool
    {
        if ($this->max_uses_global === null) {
            return false;
        }

        return $this->used_count >= $this->max_uses_global;
    }

    public function isPerAccountMaxedOut(int|string $userId): bool
    {
        if ($this->max_uses_per_account === null) {
            return false;
        }

        $usage = CouponUserUsage::where('coupon_id', $this->id)
            ->where('user_id', $userId)
            ->first();

        if ($usage === null) {
            return false;
        }

        return $usage->used_count >= $this->max_uses_per_account;
    }

    public function isValid(): bool
    {
        if (!$this->active) {
            return false;
        }

        if ($this->isExpired()) {
            return false;
        }

        if ($this->isGloballyMaxedOut()) {
            return false;
        }

        return true;
    }
}
