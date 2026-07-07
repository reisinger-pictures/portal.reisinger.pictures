<?php

namespace App\Contracts;

use App\Models\User;

interface PricingStrategy
{
    /**
     * Calculate prices for all items in the cart.
     *
     * @param  array       $items     Each item has:
     *                                - 'id' (int|string): unique item identifier (e.g. photoId)
     *                                - 'license_use_case_id' (string): license use case ID (RP only)
     *                                - 'license_modifier_ids' (array): modifier IDs (RP only)
     *                                - 'is_quote' (bool): whether this item is a quote request
     * @param  User        $user      The purchasing user
     * @param  string|null $couponCode Optional coupon code to apply after volume pricing.
 * @return array{
 *     items: array<int, array{
 *         itemId: int|string,
 *         priceCents: int,
 *         tier?: string,
 *         useCaseName?: string,
 *         modifierNames?: array<int, string>
 *     }>,
 *     totalCents: int,
 *     discountCents?: int,
 *     couponId?: int|null,
 *     tier_breakdown?: array<int, array{
 *         type: string,
 *         filename: string,
 *         notes?: string,
 *         price: int,
 *         qty: int,
 *         row_total: int
 *     }>
 * }
     */
    public function calculateCart(array $items, User $user, ?string $couponCode = null): array;

    /**
     * Check if this pricing strategy supports coupon codes.
     *
     * VolumeLicensingStrategy (SRP) returns true — coupons are a feature of the
     * SRP self-service portal. ScopeLicensingStrategy (RP) returns false — coupon
     * codes are not applicable for B2B use-case-based pricing.
     */
    public function supportsCoupons(): bool;
}
