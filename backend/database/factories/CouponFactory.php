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
            'brand' => Brand::B2B->value,
            'code' => strtoupper($this->faker->bothify('COUPON-????-####')),
            'type' => $this->faker->randomElement(['fixed', 'percentage']),
            'value' => $this->faker->randomFloat(2, 1, 50),
            'max_items' => null,
            'scope_type' => 'global',
            'scope_id' => null,
            'created_by' => null,
            'max_uses_global' => null,
            'max_uses_per_account' => null,
            'used_count' => 0,
            'expires_at' => null,
            'active' => true,
        ];
    }

    /**
     * Set the coupon type to a fixed amount, expressed in CENTS.
     *
     * The parameter is cents because that is what `value` holds for `fixed`
     * (owner decision 2026-09-28). Naming it `amountEuros` and multiplying here
     * would put the conversion this change removed straight back into the test
     * fixtures, where it would hide a wrong unit instead of surfacing it: a
     * test that seeds `fixed(10)` expecting 1000 cents has to mean it.
     */
    public function fixed(int $amountCents): static
    {
        return $this->state(fn (array $_) => [
            'type' => 'fixed',
            'value' => $amountCents,
        ]);
    }

    /**
     * Set the coupon type to percentage. The parameter is a PERCENT — the
     * sibling branch of the same `value` column.
     */
    public function percentage(float $percent): static
    {
        return $this->state(fn (array $_) => [
            'type' => 'percentage',
            'value' => $percent,
        ]);
    }

    /**
     * Set the coupon type to percentage with max_items.
     */
    public function percentageWithMaxItems(float $percent, int $maxItems): static
    {
        return $this->state(fn (array $_) => [
            'type' => 'percentage',
            'value' => $percent,
            'max_items' => $maxItems,
        ]);
    }

    /**
     * Scope the coupon to a specific gallery (UUID string).
     */
    public function scopedToGallery(int|string $galleryId): static
    {
        return $this->state(fn (array $_) => [
            'scope_type' => 'gallery',
            'scope_id' => $galleryId,
        ]);
    }

    /**
     * Set the coupon type to a photo package (N photos for a flat price Y € in cents).
     */
    public function photoPackage(int $quantity, int $priceCents): static
    {
        return $this->state(fn (array $_) => [
            'type' => 'photo_package',
            'value' => 0,
            'max_items' => null,
            'package_quantity' => $quantity,
            'package_price_cents' => $priceCents,
        ]);
    }

    /**
     * Scope the coupon to a specific meta-gallery (gallery group, UUID string).
     */
    public function scopedToMetaGallery(int|string $metaGalleryId): static
    {
        return $this->state(fn (array $_) => [
            'scope_type' => 'meta_gallery',
            'scope_id' => $metaGalleryId,
        ]);
    }
}
