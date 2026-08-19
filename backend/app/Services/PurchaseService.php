<?php

namespace App\Services;

use App\Constants\TierRanks;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class PurchaseService
{
    /**
     * Determine whether the given user has purchased the photo at the requested tier.
     *
     * Purchase check mirrors the legacy User::hasPurchasedPhoto logic: only orders
     * with a settled invoice snapshot qualify (disputed/refunded/cancelled excluded,
     * pending quote requests excluded). The purchased tier rank must satisfy the
     * requested tier rank.
     */
    public function hasPurchasedPhoto(User $user, string $photoId, string $requestedTier): bool
    {
        $cacheKey = "user.{$user->id}.purchased.{$photoId}.{$requestedTier}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $orders = Order::where('user_id', $user->id)
            ->whereNotIn('status', ['disputed', 'refunded', 'cancelled'])
            ->where(function ($q) {
                $q->where('is_quote_request', false)
                    ->orWhere('status', '!=', 'pending');
            })->with('invoiceSnapshot')->get();
        $reqRank = TierRanks::RANKS[$requestedTier] ?? 3;

        foreach ($orders as $order) {
            $snapshot = $order->invoiceSnapshot;
            if (! $snapshot) {
                continue;
            }

            $items = $snapshot->customer_details['items'] ?? [];
            foreach ($items as $item) {
                if (($item['photoId'] ?? '') === $photoId) {
                    $itemRank = TierRanks::RANKS[$item['tier'] ?? 'none'] ?? 0;
                    if ($itemRank >= $reqRank) {
                        Cache::put($cacheKey, true, 3600);

                        return true;
                    }
                }
            }
        }

        return false;
    }
}
