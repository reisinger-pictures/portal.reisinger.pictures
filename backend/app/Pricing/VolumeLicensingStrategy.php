<?php

namespace App\Pricing;

use App\Contracts\PricingStrategy;
use App\Models\Photo;
use App\Models\User;
use App\Services\CouponService;
use App\Services\SettingResolver;

class VolumeLicensingStrategy implements PricingStrategy
{
    /** Default prices per tier in cents (fallback when settings are not configured). */
    private const DEFAULT_TIER1_PRICE = 3000;
    private const DEFAULT_TIER2_PRICE = 2500;
    private const DEFAULT_TIER3_PRICE = 2000;

    /** Default thresholds (fallback when settings are not configured). */
    private const DEFAULT_THRESHOLD1 = 10;
    private const DEFAULT_THRESHOLD2 = 20;

    private SettingResolver $settings;
    private ?CouponService $couponService;

    public function __construct(SettingResolver $settings, ?CouponService $couponService = null)
    {
        $this->settings = $settings;
        $this->couponService = $couponService;
    }

    /**
     * Calculate prices using the SRP volume-based model.
     *
     * All non-quote items are charged at the tier-1 base price. Volume discounts
     * are itemized as separate tier_breakdown discount_fixed lines so the invoice
     * shows the progressive discount structure.
     *
     * Quote items are 0 cents and do not count toward the volume tier.
     *
     * If a $couponCode is provided and a CouponService is available, the coupon is applied
     * after the volume pricing calculation. Only one coupon is valid at a time, alongside
     * the always-active volume discount.
     */
    public function calculateCart(array $items, User $user, ?string $couponCode = null): array
    {
        // Count non-quote items
        $nonQuoteCount = 0;
        foreach ($items as $item) {
            if (empty($item['is_quote'])) {
                $nonQuoteCount++;
            }
        }

        // Read tier prices and thresholds
        $tier1Cents = (int) ($this->settings->get('srp_price_per_image_tier1', self::DEFAULT_TIER1_PRICE));
        $tier2Cents = (int) ($this->settings->get('srp_price_per_image_tier2', self::DEFAULT_TIER2_PRICE));
        $tier3Cents = (int) ($this->settings->get('srp_price_per_image_tier3', self::DEFAULT_TIER3_PRICE));
        $threshold1 = (int) ($this->settings->get('srp_tier_threshold1', self::DEFAULT_THRESHOLD1));
        $threshold2 = (int) ($this->settings->get('srp_tier_threshold2', self::DEFAULT_THRESHOLD2));

        // All non-quote items use the tier-1 base price; volume discounts are
        // itemized separately via tier_breakdown.
        $perImagePriceCents = $tier1Cents;

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

        // Build tier breakdown discount lines
        $tierBreakdown = [];

        if ($nonQuoteCount >= $threshold2) {
            $diff12 = $tier1Cents - $tier2Cents;
            $diff23 = $tier2Cents - $tier3Cents;
            $tierBreakdown[] = [
                'type' => 'discount_fixed',
                'filename' => 'Mengenrabatt ab ' . $threshold1 . ' Bildern',
                'notes' => sprintf('%d × -%s €', $nonQuoteCount, number_format($diff12 / 100, 2, ',', '.')),
                'price' => -$diff12,
                'qty' => $nonQuoteCount,
                'row_total' => -($nonQuoteCount * $diff12),
            ];
            $tierBreakdown[] = [
                'type' => 'discount_fixed',
                'filename' => 'Mengenrabatt ab ' . $threshold2 . ' Bildern',
                'notes' => sprintf('%d × -%s €', $nonQuoteCount, number_format($diff23 / 100, 2, ',', '.')),
                'price' => -$diff23,
                'qty' => $nonQuoteCount,
                'row_total' => -($nonQuoteCount * $diff23),
            ];
        } elseif ($nonQuoteCount >= $threshold1) {
            $diff12 = $tier1Cents - $tier2Cents;
            $tierBreakdown[] = [
                'type' => 'discount_fixed',
                'filename' => 'Mengenrabatt ab ' . $threshold1 . ' Bildern',
                'notes' => sprintf('%d × -%s €', $nonQuoteCount, number_format($diff12 / 100, 2, ',', '.')),
                'price' => -$diff12,
                'qty' => $nonQuoteCount,
                'row_total' => -($nonQuoteCount * $diff12),
            ];
        }

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
                // Extract gallery/meta-gallery context from items (first non-quote item's gallery)
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

    /**
     * Determine the per-image price in cents based on the total number of non-quote items.
     *
     * Reads thresholds and prices from SettingResolver, falling back to hardcoded defaults.
     */
    public function supportsCoupons(): bool
    {
        return true;
    }

    private function resolveTierPrice(int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        $threshold1 = (int) ($this->settings->get('srp_tier_threshold1', self::DEFAULT_THRESHOLD1));
        $threshold2 = (int) ($this->settings->get('srp_tier_threshold2', self::DEFAULT_THRESHOLD2));

        if ($count >= $threshold2) {
            return (int) ($this->settings->get('srp_price_per_image_tier3', self::DEFAULT_TIER3_PRICE));
        }

        if ($count >= $threshold1) {
            return (int) ($this->settings->get('srp_price_per_image_tier2', self::DEFAULT_TIER2_PRICE));
        }

        return (int) ($this->settings->get('srp_price_per_image_tier1', self::DEFAULT_TIER1_PRICE));
    }
}
