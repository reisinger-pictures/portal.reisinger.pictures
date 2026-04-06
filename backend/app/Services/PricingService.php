<?php

namespace AppServices;

use AppModelsPricingFactor;

class PricingService
{
    /**
     * Calculates the dynamic price based on a base price and a set of selected factor IDs.
     *
     * @param float $basePrice
     * @param array $factorIds
     * @return float
     */
    public function calculatePrice(float $basePrice, array $factorIds): float
    {
        $factors = PricingFactor::whereIn('id', $factorIds)->get();
        $multiplier = 1.0;
        
        foreach ($factors as $factor) {
            $multiplier *= $factor->multiplier;
        }

        return round($basePrice * $multiplier, 2);
    }

    /**
     * Calculates the delta price for upgrades.
     * If the delta is 0 or negative, it means the checkout can be bypassed.
     *
     * @param float $newPrice
     * @param float $paidPrice
     * @return float
     */
    public function calculateDelta(float $newPrice, float $paidPrice): float
    {
        $delta = $newPrice - $paidPrice;
        
        // We do not refund money automatically on downgrades, delta bottoms out at 0.00.
        return $delta > 0 ? round($delta, 2) : 0.00;
    }
}
