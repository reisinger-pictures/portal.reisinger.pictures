<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'brand' => Brand::SRP->value,
            'code' => strtoupper($this->faker->bothify('COUPON-????-####')),
            'type' => $this->faker->randomElement(['fixed', 'percentage', 'free_items']),
            'value' => $this->faker->randomFloat(2, 1, 50),
            'scope_type' => 'global',
            'scope_id' => null,
            'scope_gallery_id' => null,
            'per_sub_gallery' => false,
            'created_by' => null,
            'max_uses_global' => null,
            'max_uses_per_account' => null,
            'used_count' => 0,
            'expires_at' => null,
            'active' => true,
        ];
    }

    /**
     * Set the coupon type to fixed amount.
     */
    public function fixed(float $amount): static
    {
        return $this->state(fn (array $_) => [
            'type' => 'fixed',
            'value' => $amount,
        ]);
    }

    /**
     * Set the coupon type to percentage.
     */
    public function percentage(float $percent): static
    {
        return $this->state(fn (array $_) => [
            'type' => 'percentage',
            'value' => $percent,
        ]);
    }

    /**
     * Set the coupon type to free items.
     */
    public function freeItems(int $count): static
    {
        return $this->state(fn (array $_) => [
            'type' => 'free_items',
            'value' => $count,
        ]);
    }

    /**
     * Scope the coupon to a specific gallery.
     */
    public function scopedToGallery(int $galleryId): static
    {
        return $this->state(fn (array $_) => [
            'scope_type' => 'gallery',
            'scope_id' => $galleryId,
        ]);
    }

    /**
     * Scope the coupon to a specific meta-gallery (gallery group).
     */
    public function scopedToMetaGallery(int $metaGalleryId): static
    {
        return $this->state(fn (array $_) => [
            'scope_type' => 'meta_gallery',
            'scope_id' => $metaGalleryId,
        ]);
    }
}
