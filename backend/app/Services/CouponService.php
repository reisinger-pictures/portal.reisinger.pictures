<?php

namespace App\Services;

use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\CouponUserUsage;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Database\QueryException;
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
     * @param  string  $code  Coupon code entered by the user.
     * @param  Brand  $brand  Current brand (SRP or B2B).
     * @param  int|string|array<int, int|string>|null  $galleryId  Gallery ID(s) from the cart.
     * @param  int|string|array<int, int|string>|null  $metaGalleryId  Meta-gallery ID(s) from the cart.
     * @param  int|string|null  $userId  Authenticated user ID (for per-account limit check). Accepts UUID strings.
     * @return array{0: Coupon|null, 1: string|null} [coupon, errorMessage]
     */
    public function findValidCoupon(
        string $code,
        Brand|string $brand,
        int|string|array|null $galleryId = null,
        int|string|array|null $metaGalleryId = null,
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
        if (! $coupon->active) {
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

        // A scoped coupon is valid when any eligible cart item belongs to the
        // scope. Scalar IDs remain supported for preview/API callers; checkout
        // passes all item IDs so the result is not dependent on cart order.
        $galleryIds = $this->normalizeScopeIds($galleryId);
        $metaGalleryIds = $this->normalizeScopeIds($metaGalleryId);

        if ($coupon->scope_type === 'gallery' && $coupon->scope_id !== null) {
            if (! in_array((string) $coupon->scope_id, $galleryIds, true)) {
                return [null, 'This coupon is not valid for your selected items.'];
            }
        }

        if ($coupon->scope_type === 'meta_gallery' && $coupon->scope_id !== null) {
            if (! in_array((string) $coupon->scope_id, $metaGalleryIds, true)) {
                return [null, 'This coupon is not valid for your selected items.'];
            }
        }

        // photographer scope: coupon is valid for all galleries the photographer has access to
        if ($coupon->scope_type === 'photographer') {
            if ($galleryIds === []) {
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

            // Check direct gallery access for any gallery in the cart.
            $directGalleryIds = $photographer->photographerGalleries()
                ->whereIn('galleries.id', $galleryIds)
                ->pluck('galleries.id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();
            if ($directGalleryIds !== []) {
                return [$coupon, null];
            }

            // Check gallery group access, including descendant groups, for any
            // gallery in the cart rather than only the first cart item.
            $groupIds = $photographer->photographerGalleryGroups()->pluck('gallery_groups.id')->toArray();
            $allGroupIds = app(AuthorizationService::class)->getSubGroupIds($groupIds);
            if (! empty($allGroupIds)) {
                $galleryInGroup = Gallery::whereIn('id', $galleryIds)
                    ->whereIn('gallery_group_id', $allGroupIds)
                    ->exists();
                if ($galleryInGroup) {
                    return [$coupon, null];
                }
            }

            return [null, 'This coupon is not valid for your selected items.'];
        }

        // organisation scope: coupon is valid for the user's org
        if ($coupon->scope_type === 'organisation') {
            if ($userId === null) {
                return [null, 'This coupon is not valid for your account.'];
            }

            $userOrg = User::find($userId)?->org;
            if ($userOrg === null) {
                return [null, 'This coupon is not valid for your account.'];
            }

            if ((string) $coupon->scope_id !== (string) $userOrg->id) {
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
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'lock wait timeout')) {
                return [null, 'Server ist derzeit überlastet. Bitte versuche es in einigen Sekunden erneut.'];
            }
            throw $e;
        }

        if ($fresh === null) {
            return [null, 'Coupon not found.'];
        }

        // Full re-validation on the locked row: active flag, expiry and usage
        // limits may all have changed between preview and checkout.
        if (! $fresh->active) {
            return [null, 'This coupon is not active.'];
        }

        if ($fresh->isExpired()) {
            return [null, 'This coupon has expired.'];
        }

        if ($fresh->isGloballyMaxedOut()) {
            return [null, 'This coupon has reached its usage limit.'];
        }

        if ($userId !== null && $fresh->isPerAccountMaxedOut($userId)) {
            return [null, 'You have reached the usage limit for this coupon.'];
        }

        // The discount was priced from the pre-lock coupon. If any definition
        // relevant to the discount (or scope) changed in the meantime, reject the
        // checkout instead of charging a total that no longer matches the coupon.
        if ($this->couponDefinitionChanged($coupon, $fresh)) {
            return [null, 'Der Rabattcode ist nicht mehr gültig.'];
        }

        return [$fresh, null];
    }

    /**
     * Detect definition changes between the pre-lock coupon and the freshly locked row.
     */
    private function couponDefinitionChanged(Coupon $before, Coupon $after): bool
    {
        return $before->type !== $after->type
            || (float) $before->value !== (float) $after->value
            || $before->max_items !== $after->max_items
            || $before->package_quantity !== $after->package_quantity
            || $before->package_price_cents !== $after->package_price_cents
            || $before->scope_type !== $after->scope_type
            || (string) $before->scope_id !== (string) $after->scope_id;
    }

    /**
     * Apply a valid coupon to the cart calculation.
     *
     * @param  Coupon  $coupon  The validated coupon.
     * @param  array  $pricedItems  Items from PricingStrategy result (with 'priceCents', 'itemId'). Volume strategies supply the effective qualifying price here, while invoice item lines remain base-priced.
     * @param  int  $currentTotalCents  Total before coupon discount.
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

            case 'photo_package':
                // N photos for a flat price Y € (bundle). See features/ecommerce/08-srp-coupon-system.md §3a.
                $n = (int) $coupon->package_quantity;
                $y = (int) $coupon->package_price_cents;

                // M = payable items (priceCents > 0); quote items (0) are excluded.
                $payablePrices = array_map(
                    fn ($item) => (int) ($item['priceCents'] ?? 0),
                    array_filter($items, fn ($item) => (int) ($item['priceCents'] ?? 0) > 0),
                );
                sort($payablePrices); // ascending → cheapest first (best for the customer)

                $m = count($payablePrices);
                $covered = min($m, $n);
                $normalCovered = (int) array_sum(array_slice($payablePrices, 0, $covered));

                // Overcharge guard: customer never pays more than the normal price.
                $effectivePackage = min($y, $normalCovered);
                $discountCents = max($normalCovered - $effectivePackage, 0);
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
     * Normalize scalar or multi-item scope context to comparable string IDs.
     *
     * @param  int|string|array<int, int|string>|null  $scopeIds
     * @return array<int, string>
     */
    private function normalizeScopeIds(int|string|array|null $scopeIds): array
    {
        if ($scopeIds === null) {
            return [];
        }

        $values = is_array($scopeIds) ? $scopeIds : [$scopeIds];
        $normalized = [];
        foreach ($values as $value) {
            if (is_int($value) || is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    $normalized[] = $value;
                }
            }
        }

        return array_values(array_unique($normalized));
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
