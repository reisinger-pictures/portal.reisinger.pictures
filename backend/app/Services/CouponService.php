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

        // scope_gallery_id: if set on a meta_gallery coupon, restrict to a specific gallery within the group
        if ($coupon->scope_type === 'meta_gallery' && $coupon->scope_gallery_id !== null) {
            if ($galleryId === null || (string) $coupon->scope_gallery_id !== (string) $galleryId) {
                return [null, 'This coupon is not valid for this specific gallery.'];
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

            $userTenant = User::find($userId)?->tenants()->first();
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
                $discountCents = (int) round($currentTotalCents * $percent / 100);
                break;

            case 'free_items':
                $freeCount = (int) $coupon->value;
                if ($freeCount <= 0) {
                    break;
                }

                if ($coupon->per_sub_gallery && $coupon->scope_type === 'meta_gallery') {
                    $galleryGroups = [];
                    foreach ($items as $idx => $item) {
                        $gid = $item['galleryId'] ?? null;
                        if ($gid === null) continue;
                        $galleryGroups[$gid][] = $idx;
                    }
                    foreach ($galleryGroups as $gid => $indices) {
                        usort($indices, function ($a, $b) use ($items) {
                            return $items[$a]['priceCents'] <=> $items[$b]['priceCents'];
                        });
                        $freeInGallery = array_slice($indices, 0, $freeCount);
                        foreach ($freeInGallery as $idx) {
                            $discountCents += $items[$idx]['priceCents'];
                            $items[$idx]['priceCents'] = 0;
                            $items[$idx]['couponFree'] = true;
                        }
                    }
                } else {
                    $sortedIndices = array_keys($items);
                    usort($sortedIndices, function ($a, $b) use ($items) {
                        return $items[$a]['priceCents'] <=> $items[$b]['priceCents'];
                    });

                    $freeIndices = array_slice($sortedIndices, 0, $freeCount);
                    foreach ($freeIndices as $idx) {
                        $discountCents += $items[$idx]['priceCents'];
                        $items[$idx]['priceCents'] = 0;
                        $items[$idx]['couponFree'] = true;
                    }
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
        // Global counter
        DB::table('coupons')
            ->where('id', $coupon->id)
            ->update(['used_count' => $coupon->used_count + 1]);

        // Per-account counter
        if ($userId !== null) {
            CouponUserUsage::updateOrCreate(
                ['coupon_id' => $coupon->id, 'user_id' => $userId],
            )->increment('used_count');
        }
    }
}
