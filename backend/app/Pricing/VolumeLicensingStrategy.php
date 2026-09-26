<?php

namespace App\Pricing;

use App\Contracts\PricingStrategy;
use App\Models\Photo;
use App\Models\User;
use App\Models\VolumePreset;
use App\Models\VolumePresetTier;
use App\Services\CouponService;
use App\Support\BrandRegistry;

/**
 * Retroactive volume pricing using a configurable volume preset.
 *
 * The preset holds an arbitrary number of tiers (position, min_quantity,
 * price_cents). All non-quote items are priced at the base tier price (the
 * tier with the smallest min_quantity); the qualifying tier is the one with the
 * highest min_quantity that is still <= the total count of non-quote items. The
 * resulting volume discount is itemized as `tier_breakdown` discount_fixed
 * lines: one per step for monotonic presets, or a single aggregate line when the
 * tier prices are non-monotonic/duplicate. In both cases the breakdown sums to
 * the qualifying tier price, so item lines and total stay consistent.
 *
 * Quote items are 0 cents and do not count toward the volume tier. A volume
 * purchase grants the supported `original` entitlement tier; `volume` is a
 * pricing mode, not a persisted download tier.
 */
class VolumeLicensingStrategy implements PricingStrategy
{
    /** @var VolumePresetTier[] sorted by min_quantity ascending. */
    private array $tiers;

    private ?CouponService $couponService;

    public function __construct(VolumePreset $preset, ?CouponService $couponService = null)
    {
        $this->tiers = $preset->tiers->sortBy('min_quantity')->values()->all();
        $this->couponService = $couponService;
    }

    /**
     * Calculate prices using the volume-based model.
     *
     * All non-quote items are charged at the tier price determined by the total
     * item count (retroactive). Volume discounts are itemized as separate
     * tier_breakdown discount_fixed lines so the invoice shows the progressive
     * discount structure.
     */
    public function calculateCart(array $items, User $user, ?string $couponCode = null): array
    {
        $nonQuoteCount = 0;
        foreach ($items as $item) {
            if (empty($item['is_quote'])) {
                $nonQuoteCount++;
            }
        }

        [$qualifyingIndex] = $this->resolveTierIndex($nonQuoteCount);
        $perImagePriceCents = $this->basePriceCents();
        $effectivePriceCents = $this->effectivePriceCents($qualifyingIndex);

        $pricedItems = [];
        $couponPricedItems = [];
        $totalCents = 0;

        foreach ($items as $item) {
            $itemId = $item['id'] ?? 0;

            $photo = Photo::find($itemId);
            $galleryId = $photo?->gallery_id ?? null;

            if (! empty($item['is_quote'])) {
                $pricedItems[] = [
                    'itemId' => $itemId,
                    'priceCents' => 0,
                    'tier' => 'original',
                    'useCaseName' => 'Anfrage',
                    'modifierNames' => [],
                    'galleryId' => $galleryId,
                ];

                continue;
            }

            $pricedItem = [
                'itemId' => $itemId,
                'priceCents' => $perImagePriceCents,
                'tier' => 'original',
                'useCaseName' => 'Volume Lizenz',
                'modifierNames' => [],
                'galleryId' => $galleryId,
            ];
            $pricedItems[] = $pricedItem;
            $couponPricedItems[] = [
                ...$pricedItem,
                'priceCents' => $effectivePriceCents,
            ];
            $totalCents += $perImagePriceCents;
        }

        $tierBreakdown = $this->buildTierBreakdown($qualifyingIndex, $nonQuoteCount);

        // Subtract tier discounts from the item total
        foreach ($tierBreakdown as $bd) {
            $totalCents += $bd['row_total'];
        }

        $result = [
            'items' => $pricedItems,
            'totalCents' => $totalCents,
            'discountCents' => 0,
            'couponId' => null,
            'tier_breakdown' => $tierBreakdown,
            // Invoice item lines remain at the base price and show the tier
            // discount separately. Coupon math uses the effective qualifying
            // price instead, so max_items/packages cannot re-use base prices.
            'coupon_items' => $couponPricedItems,
            'coupon_item_count' => count($couponPricedItems),
        ];

        // Apply coupon if a code is provided and CouponService is available.
        // Scope validation must consider every priced item in this group; a
        // gallery-scoped coupon is valid when any item belongs to its scope.
        if ($couponCode !== null && $this->couponService !== null) {
            $brand = BrandRegistry::current();
            if ($brand !== null) {
                [$galleryIds, $metaGalleryIds] = $this->couponScopeIds($items);

                [$coupon] = $this->couponService->findValidCoupon(
                    $couponCode,
                    $brand,
                    $galleryIds,
                    $metaGalleryIds,
                    $user->getKey(),
                );

                if ($coupon !== null) {
                    $applied = $this->couponService->applyCoupon(
                        $coupon,
                        $result['coupon_items'],
                        $result['totalCents'],
                    );
                    $result['totalCents'] = $applied['totalCents'];
                    $result['discountCents'] = $applied['discountCents'];
                    $result['couponId'] = $coupon->id;
                    $result['couponType'] = $coupon->type;
                }
            }
        }

        return $result;
    }

    public function supportsCoupons(): bool
    {
        return true;
    }

    /**
     * Resolve all gallery and meta-gallery IDs represented by the priced items.
     *
     * @return array{0: array<int, string>|null, 1: array<int, string>|null}
     */
    private function couponScopeIds(array $items): array
    {
        $photoIds = [];
        foreach ($items as $item) {
            if (! empty($item['is_quote'])) {
                continue;
            }

            $photoId = $item['id'] ?? null;
            if ($photoId !== null && $photoId !== '') {
                $photoIds[] = $photoId;
            }
        }

        if ($photoIds === []) {
            return [null, null];
        }

        $photos = Photo::with('gallery')
            ->whereIn('id', array_values(array_unique($photoIds)))
            ->get();

        $galleryIds = [];
        $metaGalleryIds = [];
        foreach ($photos as $photo) {
            if ($photo->gallery_id !== null) {
                $galleryIds[] = (string) $photo->gallery_id;
            }
            if ($photo->gallery?->gallery_group_id !== null) {
                $metaGalleryIds[] = (string) $photo->gallery->gallery_group_id;
            }
        }

        $galleryIds = array_values(array_unique($galleryIds));
        $metaGalleryIds = array_values(array_unique($metaGalleryIds));

        return [
            $galleryIds === [] ? null : $galleryIds,
            $metaGalleryIds === [] ? null : $metaGalleryIds,
        ];
    }

    /**
     * Determine the qualifying tier index for a given item count.
     *
     * The qualifying tier is the one with the highest min_quantity that is still
     * <= count. All items are priced at the base tier price; the retroactive
     * discount to the qualifying tier is itemized via `buildTierBreakdown`.
     *
     * @return array{0: int} [qualifying index]
     */
    private function resolveTierIndex(int $count): array
    {
        if (count($this->tiers) === 0) {
            return [0];
        }

        if ($count <= 0) {
            return [0];
        }

        $qualifyingIndex = 0;
        foreach ($this->tiers as $index => $tier) {
            if ($count >= $tier->min_quantity) {
                $qualifyingIndex = $index;
            } else {
                break;
            }
        }

        return [$qualifyingIndex];
    }

    private function basePriceCents(): int
    {
        return count($this->tiers) > 0 ? $this->tiers[0]->price_cents : 0;
    }

    /**
     * Return the effective per-item price after the retroactive volume tier.
     * Pathological higher-than-base tiers are clamped to the base price, just
     * like the invoice breakdown and total calculation.
     */
    private function effectivePriceCents(int $qualifyingIndex): int
    {
        if ($this->tiers === []) {
            return 0;
        }

        $basePrice = $this->basePriceCents();
        $qualifyingPrice = (int) $this->tiers[$qualifyingIndex]->price_cents;

        return max(0, min($basePrice, $qualifyingPrice));
    }

    /**
     * Build the retroactive discount lines.
     *
     * The authoritative price for the cart is the qualifying tier's price. As
     * long as every higher tier is strictly cheaper, the discount can be
     * itemized progressively: one line per step below the qualifying tier,
     * priced as the difference to the next tier. For non-monotonic or
     * inconsistent tier data (a higher `min_quantity` that is not cheaper) the
     * per-step differences no longer telescope to the qualifying price, so a
     * single accurate aggregate line is emitted instead. In both cases the sum
     * of `row_total` equals `nonQuoteCount × (basePrice − qualifyingPrice)`,
     * which keeps the itemized invoice consistent with `totalCents`.
     */
    private function buildTierBreakdown(int $qualifyingIndex, int $nonQuoteCount): array
    {
        $breakdown = [];

        if ($nonQuoteCount <= 0 || $qualifyingIndex <= 0 || count($this->tiers) === 0) {
            return $breakdown;
        }

        $basePrice = $this->basePriceCents();
        $qualifyingPrice = (int) $this->tiers[$qualifyingIndex]->price_cents;

        // Volume pricing must never increase the unit price above the base tier.
        $perItemDiscount = max(0, $basePrice - $qualifyingPrice);

        if ($perItemDiscount === 0) {
            return $breakdown;
        }

        $steps = [];
        for ($i = 1; $i <= $qualifyingIndex; $i++) {
            $diff = (int) $this->tiers[$i - 1]->price_cents - (int) $this->tiers[$i]->price_cents;
            if ($diff > 0) {
                $steps[] = [
                    'min_quantity' => (int) $this->tiers[$i]->min_quantity,
                    'diff' => $diff,
                ];
            }
        }

        $stepDiscount = array_sum(array_column($steps, 'diff'));

        if ($stepDiscount === $perItemDiscount) {
            foreach ($steps as $step) {
                $breakdown[] = $this->discountLine($step['min_quantity'], $step['diff'], $nonQuoteCount);
            }

            return $breakdown;
        }

        // Non-monotonic/duplicate tier prices: fall back to a single line that
        // reflects the actual discount implied by the qualifying tier.
        return [
            $this->discountLine(
                (int) $this->tiers[$qualifyingIndex]->min_quantity,
                $perItemDiscount,
                $nonQuoteCount,
            ),
        ];
    }

    /**
     * @return array{type: string, filename: string, notes: string, price: int, qty: int, row_total: int}
     */
    private function discountLine(int $minQuantity, int $diffPerItem, int $nonQuoteCount): array
    {
        return [
            'type' => 'discount_fixed',
            'filename' => 'Mengenrabatt ab '.$minQuantity.' Bildern',
            'notes' => sprintf('%d × -%s €', $nonQuoteCount, number_format($diffPerItem / 100, 2, ',', '.')),
            'price' => -$diffPerItem,
            'qty' => $nonQuoteCount,
            'row_total' => -($nonQuoteCount * $diffPerItem),
        ];
    }
}
