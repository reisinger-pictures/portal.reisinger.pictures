<?php

namespace App\Services;

use App\Constants\TierRanks;
use App\Models\Order;
use App\Models\User;
use App\Support\ActorIdentity;
use Illuminate\Support\Facades\Cache;

class PurchaseService
{
    public function __construct(
        private readonly MediaVisibilityService $mediaVisibility,
    ) {}

    /**
     * Order statuses that legitimately grant download access.
     *
     * This is an allow-list on purpose: only orders whose payment/fulfilment has
     * been settled may grant full-resolution downloads. In particular
     * `pending_payment` (Stripe PaymentIntent created, not yet confirmed) and
     * `pending` (quote request, no payment) must never grant access — a blacklist
     * would silently grant access for every status added in the future.
     *
     * - paid / invoice_created: settled purchase (invoice = B2B, collected offline)
     * - overdue: invoice issued, payment late (access was already granted)
     * - delivery_note / archived_in_collective: Org collective-invoice flows
     */
    public const DOWNLOAD_ELIGIBLE_STATUSES = [
        'paid',
        'invoice_created',
        'overdue',
        'delivery_note',
        'archived_in_collective',
    ];

    /**
     * Whether the order's status allows downloading the purchased media.
     * Single source of truth for both the photo purchase check and the
     * order ZIP download gate.
     */
    public function isOrderDownloadEligible(Order $order): bool
    {
        return in_array($order->status, self::DOWNLOAD_ELIGIBLE_STATUSES, true);
    }

    /**
     * Determine whether the given user has purchased the photo at the requested tier.
     *
     * Purchase check mirrors the legacy User::hasPurchasedPhoto logic: only orders
     * with a settled invoice snapshot qualify. Only DOWNLOAD_ELIGIBLE_STATUSES
     * grant access (pending_payment / pending / disputed / refunded / cancelled
     * are excluded). The purchased tier rank must satisfy the requested tier rank.
     */
    public function hasPurchasedPhoto(User $user, string $photoId, string $requestedTier): bool
    {
        $cacheKey = ActorIdentity::purchaseCacheKeyForActor($user, $photoId, $requestedTier);
        if ($cacheKey === null) {
            return false;
        }

        // Purchase entitlement is mutable (refund, dispute, ownership/brand
        // changes), so a positive cache entry can never stand in for the final
        // authorization check immediately before bytes are emitted.
        if (! $this->mediaVisibility->photoIsVisible($photoId)) {
            Cache::forget($cacheKey);

            return false;
        }

        $orders = Order::query()
            ->ownedBy($user)
            ->whereIn('status', self::DOWNLOAD_ELIGIBLE_STATUSES)
            ->with('invoiceSnapshot')->get();
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

        Cache::forget($cacheKey);

        return false;
    }
}
