<?php

namespace App\Pricing;

use App\Contracts\PricingStrategy;
use App\Models\Photo;
use App\Models\User;
use App\Models\VolumePreset;
use App\Models\VolumePresetTier;
use App\Services\CouponService;

/**
 * Retroactive volume pricing using a configurable volume preset.
 *
 * The preset holds an arbitrary number of tiers (position, min_quantity,
 * price_cents). All non-quote items are priced at the base tier price (the
 * tier with the smallest min_quantity); the tier that applies is determined by
 * the total count of non-quote items, and the volume discount is itemized as
 * `tier_breakdown` discount_fixed lines (one per step below the qualifying tier).
 *
 * Quote items are 0 cents and do not count toward the volume tier.
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

        $pricedItems = [];
        $totalCents = 0;

        foreach ($items as $item) {
            $itemId = $item['id'] ?? 0;

            $photo = Photo::find($itemId);
            $galleryId = $photo?->gallery_id ?? null;

            if (!empty($item['is_quote'])) {
                $pricedItems[] = [
                    'itemId' => $itemId,
                    'priceCents' => 0,
                    'tier' => 'volume',
                    'useCaseName' => 'Anfrage',
                    'modifierNames' => [],
                    'galleryId' => $galleryId,
                ];
                continue;
            }

            $pricedItems[] = [
                'itemId' => $itemId,
                'priceCents' => $perImagePriceCents,
                'tier' => 'volume',
                'useCaseName' => 'Volume Lizenz',
                'modifierNames' => [],
                'galleryId' => $galleryId,
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
        ];

        // Apply coupon if a code is provided and CouponService is available
        if ($couponCode !== null && $this->couponService !== null) {
            $brand = \App\Support\BrandRegistry::current();
            if ($brand !== null) {
                $galleryId = null;
                $metaGalleryId = null;
                foreach ($items as $item) {
                    if (!empty($item['id']) && empty($item['is_quote'])) {
                        $photo = \App\Models\Photo::find($item['id']);
                        if ($photo && $photo->gallery) {
                            $galleryId = (int) $photo->gallery_id;
                            $metaGalleryId = $photo->gallery->gallery_group_id
                                ? (int) $photo->gallery->gallery_group_id
                                : null;
                            break;
                        }
                    }
                }

                [$coupon, $error] = $this->couponService->findValidCoupon(
                    $couponCode,
                    $brand,
                    $galleryId,
                    $metaGalleryId,
                    $user->id,
                );

                if ($coupon !== null) {
                    $applied = $this->couponService->applyCoupon($coupon, $result['items'], $result['totalCents']);
                    $result['items'] = $applied['items'];
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
     * Build the retroactive discount lines: one step per tier below the
     * qualifying tier, priced as the difference to the next tier.
     */
    private function buildTierBreakdown(int $qualifyingIndex, int $nonQuoteCount): array
    {
        $breakdown = [];

        if ($nonQuoteCount <= 0 || $qualifyingIndex <= 0) {
            return $breakdown;
        }

        for ($i = 1; $i <= $qualifyingIndex; $i++) {
            $upperPrice = $this->tiers[$i - 1]->price_cents;
            $lowerPrice = $this->tiers[$i]->price_cents;
            $diff = $upperPrice - $lowerPrice;

            if ($diff <= 0) {
                continue;
            }

            $breakdown[] = [
                'type' => 'discount_fixed',
                'filename' => 'Mengenrabatt ab ' . $this->tiers[$i]->min_quantity . ' Bildern',
                'notes' => sprintf('%d × -%s €', $nonQuoteCount, number_format($diff / 100, 2, ',', '.')),
                'price' => -$diff,
                'qty' => $nonQuoteCount,
                'row_total' => -($nonQuoteCount * $diff),
            ];
        }

        return $breakdown;
    }
}
