<?php

namespace App\Services;

use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\CouponUserUsage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Core service for coupon validation and application (SRP-01).
 *
 * @see features/ecommerce/08-srp-coupon-system.md
 */
class CouponService
{
    /**
     * Find a valid coupon by code and brand, checking scope, expiry, usage limits, and per-account limit.
     *
     * @param  string      $code          Coupon code entered by the user.
     * @param  Brand       $brand         Current brand (SRP or B2B).
     * @param  int|null    $galleryId     Gallery ID from the cart (null if mixed/unknown).
     * @param  int|null    $metaGalleryId Meta-gallery ID from the cart (null if mixed/unknown).
     * @param  int|string|null $userId    Authenticated user ID (for per-account limit check). Accepts UUID strings.
     * @return array{0: Coupon|null, 1: string|null}  [coupon, errorMessage]
     */
    public function findValidCoupon(
        string $code,
        Brand $brand,
        int|string|null $galleryId = null,
        int|string|null $metaGalleryId = null,
        int|string|null $userId = null,
    ): array {
        /** @var Coupon|null $coupon */
        $coupon = Coupon::byBrand($brand)
            ->where('code', $code)
            ->first();

        if ($coupon === null) {
            return [null, 'Coupon code not found.'];
        }

        // Check active flag
        if (!$coupon->active) {
            return [null, 'This coupon is not active.'];
        }

        // Check expiry
        if ($coupon->isExpired()) {
            return [null, 'This coupon has expired.'];
        }

        // Check global usage limit
        if ($coupon->isGloballyMaxedOut()) {
            return [null, 'This coupon has reached its usage limit.'];
        }

        // Check per-account usage limit
        if ($userId !== null && $coupon->isPerAccountMaxedOut($userId)) {
            return [null, 'You have reached the usage limit for this coupon.'];
        }

        // Check scope
        if ($coupon->scope_type === 'gallery' && $coupon->scope_id !== null) {
            if ($galleryId === null || (string) $coupon->scope_id !== (string) $galleryId) {
                return [null, 'This coupon is not valid for your selected items.'];
            }
        }

        if ($coupon->scope_type === 'meta_gallery' && $coupon->scope_id !== null) {
            if ($metaGalleryId === null || (string) $coupon->scope_id !== (string) $metaGalleryId) {
                return [null, 'This coupon is not valid for your selected items.'];
            }
        }

        // photographer scope: coupon is valid for all galleries the photographer has access to
        if ($coupon->scope_type === 'photographer') {
            if ($galleryId === null) {
                return [null, 'This coupon is not valid for your selected items.'];
            }

            $photographerId = $coupon->created_by;
            if ($photographerId === null) {
                return [null, 'This coupon is not properly configured.'];
            }

            $photographer = User::find($photographerId);
            if ($photographer === null) {
                return [null, 'This coupon is not properly configured.'];
            }

            // Check direct gallery access
            if ($photographer->photographerGalleries()->where('galleries.id', $galleryId)->exists()) {
                return [$coupon, null];
            }

            // Check gallery group access
            $groupIds = $photographer->photographerGalleryGroups()->pluck('gallery_groups.id')->toArray();
            if (!empty($groupIds)) {
                $galleryInGroup = DB::table('gallery_gallery_group')
                    ->whereIn('gallery_group_id', $groupIds)
                    ->where('gallery_id', $galleryId)
                    ->exists();
                if ($galleryInGroup) {
                    return [$coupon, null];
                }
            }

            return [null, 'This coupon is not valid for your selected items.'];
        }

        // organisation scope: coupon is valid for the user's tenant
        if ($coupon->scope_type === 'organisation') {
            if ($userId === null) {
                return [null, 'This coupon is not valid for your account.'];
            }

            $userTenant = User::find($userId)?->tenant;
            if ($userTenant === null) {
                return [null, 'This coupon is not valid for your account.'];
            }

            if ((string) $coupon->scope_id !== (string) $userTenant->id) {
                return [null, 'This coupon is not valid for your account.'];
            }
        }

        return [$coupon, null];
    }

    /**
     * Lock the coupon row for update within an existing transaction and re-validate usage limits.
     * Must be called from within a DB transaction (serialisable checkpoint).
     *
     * @return array{0: Coupon|null, 1: string|null}
     */
    public function lockAndRevalidateCoupon(Coupon $coupon, int|string|null $userId = null): array
    {
        try {
            /** @var Coupon|null $fresh */
            $fresh = Coupon::where('id', $coupon->id)->lockForUpdate()->first();
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'lock wait timeout')) {
                return [null, 'Server ist derzeit überlastet. Bitte versuche es in einigen Sekunden erneut.'];
            }
            throw $e;
        }

        if ($fresh === null) {
            return [null, 'Coupon not found.'];
        }

        if ($fresh->isGloballyMaxedOut()) {
            return [null, 'This coupon has reached its usage limit.'];
        }

        if ($userId !== null && $fresh->isPerAccountMaxedOut($userId)) {
            return [null, 'You have reached the usage limit for this coupon.'];
        }

        return [$fresh, null];
    }

    /**
     * Apply a valid coupon to the cart calculation.
     *
     * @param  Coupon $coupon         The validated coupon.
     * @param  array  $pricedItems    Items from PricingStrategy result (with 'priceCents', 'itemId').
     * @param  int    $currentTotalCents  Total before coupon discount.
     * @return array{totalCents: int, discountCents: int, items: array}
     */
    public function applyCoupon(Coupon $coupon, array $pricedItems, int $currentTotalCents): array
    {
        $discountCents = 0;
        $items = $pricedItems;

        switch ($coupon->type) {
            case 'fixed':
                $discountCents = (int) round((float) $coupon->value * 100);
                if ($discountCents > $currentTotalCents) {
                    $discountCents = $currentTotalCents;
                }
                break;

            case 'percentage':
                $percent = min(max((float) $coupon->value, 0), 100);
                if ($coupon->max_items !== null) {
                    $maxItems = (int) $coupon->max_items;
                    $prices = array_map(fn ($item) => $item['priceCents'], $items);
                    sort($prices);
                    $cheapestSubtotal = (int) round(array_sum(array_slice($prices, 0, $maxItems)));
                    $discountCents = (int) round($cheapestSubtotal * $percent / 100);
                } else {
                    $discountCents = (int) round($currentTotalCents * $percent / 100);
                }
                break;
        }

        $totalCents = max($currentTotalCents - $discountCents, 0);

        return [
            'totalCents' => $totalCents,
            'discountCents' => $discountCents,
            'items' => $items,
        ];
    }

    /**
     * Atomically increment usage counters (global + per-account).
     */
    public function incrementUsage(Coupon $coupon, int|string|null $userId = null): void
    {
        DB::table('coupons')
            ->where('id', $coupon->id)
            ->increment('used_count');

        if ($userId !== null) {
            CouponUserUsage::updateOrCreate(
                ['coupon_id' => $coupon->id, 'user_id' => $userId],
            )->increment('used_count');
        }
    }
}
