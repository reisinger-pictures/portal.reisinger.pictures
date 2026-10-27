<?php

namespace App\Services;

use App\Exceptions\PayoutChronologyException;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\PayoutCorrection;
use App\Models\PayoutPool;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class PayoutCalculationService
{
    public function getShareMultiplier($tier)
    {
        return match ($tier) {
            'original' => 4,
            'print' => 2,
            'web' => 1,
            default => 1
        };
    }

    public function calculatePoolShares(PayoutPool $pool)
    {
        $this->assertChronological((int) $pool->month, (int) $pool->year);

        $startDate = Carbon::create($pool->year, $pool->month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $logs = DownloadLog::whereNotNull('user_id')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $totalShares = '0.0000';
        $totalDownloads = 0;
        $photographerEarnings = [];

        // Load every gallery/photo once. A gallery can contain photos from
        // several photographers, and an order ZIP can span galleries, so
        // ownership must not be collapsed to one gallery/user row.
        $directGalleryIds = $logs->pluck('gallery_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
        $payloadPhotoIds = [];
        $payloadGalleryIds = [];
        foreach ($logs as $log) {
            if ($log->item_type === 'full_zip' && ! $this->hasPositivePhotoCount($log)) {
                continue;
            }

            $photoIds = $this->explicitPhotoIds($log, $log->item_type === 'full_zip');
            if ($photoIds !== null) {
                $payloadPhotoIds = array_merge($payloadPhotoIds, $photoIds);
            }

            $payload = $log->payload;
            if (! is_array($payload) || ! isset($payload['gallery_ids']) || ! is_array($payload['gallery_ids'])) {
                continue;
            }
            foreach ($payload['gallery_ids'] as $galleryId) {
                if (is_string($galleryId) || is_int($galleryId)) {
                    $galleryId = trim((string) $galleryId);
                    if ($galleryId !== '') {
                        $payloadGalleryIds[] = $galleryId;
                    }
                }
            }
        }

        $payloadPhotoIds = array_values(array_unique($payloadPhotoIds));
        $payloadGalleryIds = array_values(array_unique($payloadGalleryIds));
        $initialGalleryIds = array_values(array_unique(array_merge($directGalleryIds, $payloadGalleryIds)));
        if ($initialGalleryIds !== [] || $payloadPhotoIds !== []) {
            $photos = Photo::withoutEagerLoads()
                ->where(function ($query) use ($initialGalleryIds, $payloadPhotoIds): void {
                    if ($initialGalleryIds !== []) {
                        $query->whereIn('gallery_id', $initialGalleryIds);
                    }
                    if ($payloadPhotoIds !== []) {
                        $query->orWhereIn('id', $payloadPhotoIds);
                    }
                })
                ->select('id', 'gallery_id', 'user_id')
                ->get();
        } else {
            $photos = collect();
        }

        $galleryIds = array_values(array_unique(array_merge(
            $directGalleryIds,
            $payloadGalleryIds,
            $photos->pluck('gallery_id')
                ->map(fn ($id): string => (string) $id)
                ->all(),
        )));
        $galleries = Gallery::withoutEagerLoads()
            ->whereIn('id', $galleryIds)
            ->get()
            ->keyBy('id');
        $photosById = $photos->keyBy('id');
        $photosByGallery = $photos->groupBy(fn ($photo): string => (string) $photo->gallery_id);
        /** @var array<string, array{customer_id:string, gallery_id:string, photographer_id:string, multiplier:int}> $explicitPhotoDownloads */
        $explicitPhotoDownloads = [];
        /** @var array<string, array{customer_id:string, gallery_id:string, photo_count:int, multiplier:int}> $legacyZipDownloads */
        $legacyZipDownloads = [];

        // All identity-bearing rows share one customer/photo ledger, regardless
        // of whether the source was a single, gallery ZIP, or mixed-gallery ZIP.
        // A customer's best tier for one photo is therefore applied exactly
        // once for this pool month, and ownership is resolved from that photo.
        foreach ($logs as $log) {
            if ($log->item_type === 'full_zip' && ! $this->hasPositivePhotoCount($log)) {
                continue;
            }

            $customerId = (string) $log->user_id;
            if ($log->gallery_id === null) {
                if ($this->hasExplicitPhotoPayload($log)) {
                    $this->recordExplicitLogDownloads(
                        $log,
                        $photosById,
                        $galleries,
                        null,
                        $explicitPhotoDownloads,
                    );
                }

                continue;
            }

            $galleryId = (string) $log->gallery_id;
            $gallery = $galleries->get($galleryId);
            if (! $gallery || $gallery->effective_is_free_download) {
                continue;
            }

            if ($log->item_type === 'full_zip' && ! $this->hasExplicitPhotoPayload($log)) {
                // A count-only archive has no per-photo identities. Preserve
                // the historical one-archive count (largest archive and best
                // ZIP tier), but do not mix its unknown contents with explicit
                // photo rows from the same customer/gallery below.
                $identity = $customerId.'|'.$galleryId;
                $photoCount = (int) $log->photo_count;
                $multiplier = $this->getShareMultiplier($log->resolution_tier);
                if (! isset($legacyZipDownloads[$identity])) {
                    $legacyZipDownloads[$identity] = [
                        'customer_id' => $customerId,
                        'gallery_id' => $galleryId,
                        'photo_count' => $photoCount,
                        'multiplier' => $multiplier,
                    ];
                } else {
                    $legacyZipDownloads[$identity]['photo_count'] = max(
                        $legacyZipDownloads[$identity]['photo_count'],
                        $photoCount,
                    );
                    $legacyZipDownloads[$identity]['multiplier'] = max(
                        $legacyZipDownloads[$identity]['multiplier'],
                        $multiplier,
                    );
                }

                continue;
            }

            if ($this->hasExplicitPhotoPayload($log)) {
                $this->recordExplicitLogDownloads(
                    $log,
                    $photosById,
                    $galleries,
                    $galleryId,
                    $explicitPhotoDownloads,
                );
            }
            // Legacy single-image rows without photo identity are deliberately
            // suppressed: the system cannot prove they represent distinct
            // photos, so counting each row would violate monthly uniqueness.
        }

        // A count-only ZIP may overlap any photo identity from the same
        // customer/gallery. Drop those identities rather than double-pay an
        // archive whose individual files cannot be reconciled.
        foreach ($explicitPhotoDownloads as $identity => $download) {
            if (isset($legacyZipDownloads[$download['customer_id'].'|'.$download['gallery_id']])) {
                unset($explicitPhotoDownloads[$identity]);
            }
        }

        foreach ($explicitPhotoDownloads as $download) {
            $photographerId = $download['photographer_id'];
            $shares = (string) $download['multiplier'];
            $totalDownloads++;
            $totalShares = bcadd($totalShares, $shares, 4);
            $photographerEarnings[$photographerId] = bcadd(
                $photographerEarnings[$photographerId] ?? '0.0000',
                $shares,
                4,
            );
        }

        foreach ($legacyZipDownloads as $download) {
            $galleryPhotos = $photosByGallery->get($download['gallery_id']) ?? collect();
            $ownerPhotoCounts = $galleryPhotos
                ->whereNotNull('user_id')
                ->countBy('user_id')
                ->all();
            if ($ownerPhotoCounts === []) {
                continue;
            }

            $photoCount = $download['photo_count'];
            $shares = bcmul((string) $photoCount, (string) $download['multiplier'], 4);
            $attributedShares = $this->allocateSharesByPhotoOwnership($ownerPhotoCounts, $shares);
            $totalDownloads += $photoCount;
            foreach ($attributedShares as $photographerId => $ownerShares) {
                $totalShares = bcadd($totalShares, $ownerShares, 4);
                $photographerEarnings[$photographerId] = bcadd(
                    $photographerEarnings[$photographerId] ?? '0.0000',
                    $ownerShares,
                    4,
                );
            }
        }

        // Persist the aggregate under the same row lock used by the pool
        // writer. This keeps a direct service invocation race-safe as well as
        // the controller transaction.
        $pool = $this->savePoolTotals($pool, $totalShares, $totalDownloads);

        $poolContributions = [];
        foreach ($photographerEarnings as $photogId => $shares) {
            $earnings = (int) bcmul($shares, (string) $pool->value_per_share_cents, 0);
            $earnings = (int) bcdiv(bcmul((string) $earnings, (string) $pool->photographer_share_percent, 0), '100', 0);
            $poolContributions[(string) $photogId] = [
                'shares' => $shares,
                'earnings_cents' => $earnings,
            ];
        }

        $this->replacePoolContributions(
            (int) $pool->month,
            (int) $pool->year,
            $poolContributions,
        );

        return $pool;
    }

    private function savePoolTotals(
        PayoutPool $pool,
        string $totalShares,
        int $totalDownloads,
    ): PayoutPool {
        return DB::transaction(function () use (
            $pool,
            $totalShares,
            $totalDownloads,
        ): PayoutPool {
            $lockedPool = PayoutPool::query()
                ->whereKey($pool->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPool->total_shares = $totalShares;
            $lockedPool->total_unique_downloads = $totalDownloads;
            $lockedPool->value_per_share_cents = (float) $totalShares > 0
                ? (int) bcdiv((string) $lockedPool->net_pool_cents, $totalShares, 0)
                : 0;
            $lockedPool->save();

            return $lockedPool;
        }, 3);
    }

    /**
     * A stored count of one is valid for a historical full-ZIP row. New rows
     * are validated by DownloadLog::creating; this read-side guard keeps a
     * malformed legacy value from becoming a negative or zero payout.
     */
    private function hasPositivePhotoCount(DownloadLog $log): bool
    {
        return (int) $log->photo_count > 0;
    }

    /**
     * Whether a log carries the explicit photo identity written by a current
     * download path. Legacy logs without that key return false.
     */
    private function hasExplicitPhotoPayload(DownloadLog $log): bool
    {
        $payload = $log->payload;
        if (! is_array($payload)) {
            return false;
        }

        return $log->item_type === 'full_zip'
            ? array_key_exists('photo_ids', $payload)
            : array_key_exists('photo_id', $payload);
    }

    /**
     * @return array<int, string>|null
     */
    private function explicitPhotoIds(DownloadLog $log, bool $isZip): ?array
    {
        $payload = $log->payload;
        if (! is_array($payload)) {
            return null;
        }

        if ($isZip) {
            if (! array_key_exists('photo_ids', $payload)) {
                return null;
            }
            $values = is_array($payload['photo_ids']) ? $payload['photo_ids'] : [];
        } else {
            if (! array_key_exists('photo_id', $payload)) {
                return null;
            }
            $values = [$payload['photo_id']];
        }

        $ids = [];
        foreach (array_slice($values, 0, 500) as $photoId) {
            if (! is_string($photoId) && ! is_int($photoId)) {
                continue;
            }

            $photoId = trim((string) $photoId);
            if ($photoId !== '' && ! in_array($photoId, $ids, true)) {
                $ids[] = $photoId;
            }
        }

        return $ids;
    }

    /**
     * Add a log's explicit photo identities to the shared monthly deduplication
     * ledger. Mixed-gallery ZIP payloads may constrain their IDs with
     * `gallery_ids`; a malformed constraint fails closed.
     *
     * @param  array<string, array{customer_id:string, gallery_id:string, photographer_id:string, multiplier:int}>  $downloads
     */
    private function recordExplicitLogDownloads(
        DownloadLog $log,
        $photosById,
        $galleries,
        ?string $requiredGalleryId,
        array &$downloads,
    ): void {
        $isZip = $log->item_type === 'full_zip';
        $photoIds = $this->explicitPhotoIds($log, $isZip);
        if ($photoIds === null || $photoIds === []) {
            return;
        }

        $allowedGalleryIds = null;
        $payload = $log->payload;
        if ($requiredGalleryId === null && $isZip && array_key_exists('gallery_ids', $payload)) {
            if (! is_array($payload['gallery_ids'])) {
                return;
            }

            $allowedGalleryIds = [];
            foreach ($payload['gallery_ids'] as $galleryId) {
                if (! is_string($galleryId) && ! is_int($galleryId)) {
                    continue;
                }

                $galleryId = trim((string) $galleryId);
                if ($galleryId !== '') {
                    $allowedGalleryIds[] = $galleryId;
                }
            }
            $allowedGalleryIds = array_values(array_unique($allowedGalleryIds));
            if ($allowedGalleryIds === []) {
                return;
            }
        }

        $customerId = (string) $log->user_id;
        $multiplier = $this->getShareMultiplier($log->resolution_tier);
        foreach ($photoIds as $photoId) {
            $photo = $photosById->get($photoId);
            if (! $photo || ! $photo->user_id) {
                continue;
            }

            $galleryId = (string) $photo->gallery_id;
            if (($requiredGalleryId !== null && $galleryId !== $requiredGalleryId)
                || ($allowedGalleryIds !== null && ! in_array($galleryId, $allowedGalleryIds, true))) {
                continue;
            }

            $gallery = $galleries->get($galleryId);
            if (! $gallery || $gallery->effective_is_free_download) {
                continue;
            }

            $identity = $customerId.'|'.$photoId;
            if (! isset($downloads[$identity]) || $multiplier > $downloads[$identity]['multiplier']) {
                $downloads[$identity] = [
                    'customer_id' => $customerId,
                    'gallery_id' => $galleryId,
                    'photographer_id' => (string) $photo->user_id,
                    'multiplier' => $multiplier,
                ];
            }
        }
    }

    /**
     * Split a ZIP's shares according to the number of downloaded photos owned
     * by each photographer. The final owner receives the rounding remainder so
     * the attributed shares always add up to the pool contribution exactly.
     */
    private function allocateSharesByPhotoOwnership(array $ownerPhotoCounts, string $totalShares): array
    {
        $totalPhotos = array_sum($ownerPhotoCounts);
        if ($totalPhotos <= 0 || bccomp($totalShares, '0', 4) === 0) {
            return [];
        }

        ksort($ownerPhotoCounts, SORT_STRING);
        $photographerIds = array_keys($ownerPhotoCounts);
        $lastPhotographerId = array_pop($photographerIds);

        $allocatedShares = [];
        $allocatedTotal = '0.0000';
        foreach ($photographerIds as $photographerId) {
            $ownerNumerator = bcmul(
                $totalShares,
                (string) $ownerPhotoCounts[$photographerId],
                4
            );
            $shares = bcdiv($ownerNumerator, (string) $totalPhotos, 4);
            $allocatedShares[$photographerId] = $shares;
            $allocatedTotal = bcadd($allocatedTotal, $shares, 4);
        }

        $allocatedShares[$lastPhotographerId] = bcsub($totalShares, $allocatedTotal, 4);

        return $allocatedShares;
    }

    /**
     * Replace the pool-derived portion of every statement for this month.
     *
     * calculatePoolShares() is an authoritative calculation for the one pool
     * identified by (year, month), not an incremental delta. Resetting the
     * pool fields first makes a direct service replay idempotent. Surcharge
     * earnings and locked approved/paid rows are preserved.
     *
     * @param  array<string, array{shares: string, earnings_cents: int}>  $contributions
     */
    private function replacePoolContributions(int $month, int $year, array $contributions): void
    {
        DB::transaction(function () use ($month, $year, $contributions): void {
            $statements = PhotographerStatement::query()
                ->where('month', $month)
                ->where('year', $year)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $statementsByUser = [];

            foreach ($statements as $statement) {
                $statementsByUser[(string) $statement->user_id] = $statement;
                if ($statement->isLocked()) {
                    continue;
                }

                $statement->total_shares_earned = '0.0000';
                $statement->pool_earnings_cents = 0;
                $statement->save();
            }

            foreach ($contributions as $userId => $contribution) {
                $statement = $statementsByUser[(string) $userId] ?? null;
                if ($statement === null) {
                    $statement = PhotographerStatement::query()->createOrFirst(
                        [
                            'user_id' => (string) $userId,
                            'year' => $year,
                            'month' => $month,
                        ],
                        [
                            'total_shares_earned' => '0.0000',
                            'pool_earnings_cents' => 0,
                            'delta_surcharge_earnings_cents' => 0,
                            'status' => 'pending',
                        ],
                    );
                    $statement = PhotographerStatement::query()
                        ->whereKey($statement->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                if ($statement->isLocked()) {
                    continue;
                }

                $statement->total_shares_earned = $contribution['shares'];
                $statement->pool_earnings_cents = $contribution['earnings_cents'];
                $statement->save();
            }
        }, 3);
    }

    public function calculatePowerUserDelta(int $month, int $year)
    {
        $this->assertChronological($month, $year);

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // Paid orders and fully refunded orders both belong to this month. A
        // full refund contributes zero in an open statement, but it must remain
        // in the order basis so a late-refund correction can compare its booked
        // share with the new refunded share.
        $orders = Order::whereIn('status', ['paid', 'refunded'])
            ->where('is_quote_request', false)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('invoiceSnapshot')
            ->get();

        // N+1 Fix: Gather all photo IDs and preload them
        $photoIds = [];
        foreach ($orders as $order) {
            $snapshot = $order->invoiceSnapshot;
            if (! $snapshot) {
                continue;
            }
            $customerDetails = $snapshot->customer_details;
            $items = is_array($customerDetails) ? ($customerDetails['items'] ?? []) : [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (is_array($item) && isset($item['photoId'])) {
                    $photoIds[] = $item['photoId'];
                }
            }
        }
        // Memory-Optimierung: Nur die benötigten Spalten laden, um das PHP Memory Limit zu schonen
        $photos = Photo::select('id', 'user_id')->whereIn('id', array_unique($photoIds))->get()->keyBy('id');

        $deltaContributions = [];
        foreach ($orders as $order) {
            foreach ($this->calculateOrderPhotographerShares(
                $order,
                $photos,
                max(0, (int) ($order->refunded_amount_cents ?? 0)),
            ) as $userId => $shareCents) {
                $deltaContributions[(string) $userId] = ($deltaContributions[(string) $userId] ?? 0) + $shareCents;
            }
        }

        $this->replaceDeltaContributions($month, $year, $deltaContributions);
    }

    /**
     * Replace the order-derived portion of every statement for this month.
     *
     * The monthly order calculation is authoritative, so replaying it replaces
     * each unlocked statement's delta instead of incrementing the stored value.
     * Approved/paid statements remain immutable.
     *
     * @param  array<string, int>  $contributions
     */
    private function replaceDeltaContributions(int $month, int $year, array $contributions): void
    {
        DB::transaction(function () use ($month, $year, $contributions): void {
            $statements = PhotographerStatement::query()
                ->where('month', $month)
                ->where('year', $year)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $statementsByUser = [];

            foreach ($statements as $statement) {
                $statementsByUser[(string) $statement->user_id] = $statement;
                if ($statement->isLocked()) {
                    continue;
                }

                $statement->delta_surcharge_earnings_cents = 0;
                $statement->save();
            }

            foreach ($contributions as $userId => $deltaCents) {
                $statement = $statementsByUser[(string) $userId] ?? null;
                if ($statement === null) {
                    $statement = PhotographerStatement::query()->createOrFirst(
                        [
                            'user_id' => (string) $userId,
                            'year' => $year,
                            'month' => $month,
                        ],
                        [
                            'total_shares_earned' => '0.0000',
                            'pool_earnings_cents' => 0,
                            'delta_surcharge_earnings_cents' => 0,
                            'status' => 'pending',
                        ],
                    );
                    $statement = PhotographerStatement::query()
                        ->whereKey($statement->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                if ($statement->isLocked()) {
                    continue;
                }

                $statement->delta_surcharge_earnings_cents = $deltaCents;
                $statement->save();
            }
        }, 3);
    }

    /**
     * Persist the share reduction caused by a newly observed cumulative refund
     * when the order's accounting statement is already closed.
     */
    public function recordLateRefundCorrections(Order $order, int $previousRefundCents, int $currentRefundCents): void
    {
        if ($currentRefundCents <= $previousRefundCents) {
            return;
        }

        $order->loadMissing('invoiceSnapshot');
        $customerDetails = $order->invoiceSnapshot?->customer_details;
        $items = is_array($customerDetails) ? ($customerDetails['items'] ?? []) : [];
        if (! is_array($items) || $order->created_at === null) {
            return;
        }

        $photoIds = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['photoId'])) {
                $photoIds[] = (string) $item['photoId'];
            }
        }
        $photos = Photo::query()
            ->select('id', 'user_id')
            ->whereIn('id', array_unique($photoIds))
            ->get()
            ->keyBy('id');

        $beforeShares = $this->calculateOrderPhotographerShares($order, $photos, $previousRefundCents);
        $afterShares = $this->calculateOrderPhotographerShares($order, $photos, $currentRefundCents);
        $closedYear = (int) $order->created_at->year;
        $closedMonth = (int) $order->created_at->month;

        foreach (array_unique([...array_keys($beforeShares), ...array_keys($afterShares)]) as $userId) {
            $statement = PhotographerStatement::query()
                ->where('user_id', $userId)
                ->where('year', $closedYear)
                ->where('month', $closedMonth)
                ->lockForUpdate()
                ->first();

            if ($statement === null || ! $statement->isLocked()) {
                continue;
            }

            $amountCents = ($afterShares[$userId] ?? 0) - ($beforeShares[$userId] ?? 0);
            if ($amountCents >= 0) {
                continue;
            }

            PayoutCorrection::query()->createOrFirst(
                [
                    'order_id' => (string) $order->getKey(),
                    'user_id' => (string) $userId,
                    'closed_month' => $closedMonth,
                    'closed_year' => $closedYear,
                    'refund_amount_cents' => $currentRefundCents,
                ],
                [
                    'amount_cents' => $amountCents,
                    'settled_at' => null,
                ],
            );
        }
    }

    /**
     * Apply the same proportional refund, fee and share split used by the
     * monthly calculation, returning each photographer's aggregate share.
     *
     * @param  EloquentCollection<string, Photo>  $photos
     * @return array<string, int>
     */
    private function calculateOrderPhotographerShares(Order $order, EloquentCollection $photos, int $refundCents): array
    {
        $snapshot = $order->invoiceSnapshot;
        if (! $snapshot) {
            return [];
        }

        $customerDetails = $snapshot->customer_details;
        $items = is_array($customerDetails) ? ($customerDetails['items'] ?? []) : [];
        if (! is_array($items)) {
            return [];
        }

        // New checkout snapshots carry exact post-volume/post-coupon photo
        // revenue separately from their customer-facing aggregate discount.
        // Historical snapshots retain a capped proportional fallback.
        $photoItems = [];
        $totalPhotoGross = 0;
        $discountCents = 0;
        $payoutAttribution = is_array($customerDetails)
            ? ($customerDetails[InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY] ?? null)
            : null;
        $hasExplicitAttribution = is_array($payoutAttribution);
        foreach ($items as $lineIndex => $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? null;
            if (is_string($type) && str_starts_with($type, 'discount_')) {
                $rowTotal = $item['row_total'] ?? null;
                if (is_numeric($rowTotal) && (int) $rowTotal < 0) {
                    $discountCents += abs((int) $rowTotal);
                }

                continue;
            }

            if (! isset($item['photoId'], $item['price']) || ! is_numeric($item['price'])) {
                continue;
            }

            $priceCents = max(0, (int) $item['price']);
            if ($priceCents <= 0 || ! empty($item['isQuote'])) {
                continue;
            }

            $hasLineAttribution = is_array($payoutAttribution)
                && array_key_exists((string) $lineIndex, $payoutAttribution)
                && is_numeric($payoutAttribution[(string) $lineIndex]);
            $lineRevenue = $hasLineAttribution ? (int) $payoutAttribution[(string) $lineIndex] : null;
            if ($hasExplicitAttribution && (! $hasLineAttribution || $lineRevenue < 0 || $lineRevenue > $priceCents)) {
                $hasExplicitAttribution = false;
            }

            $photo = $photos->get((string) $item['photoId']);
            $photoItems[] = [
                'user_id' => $photo?->user_id,
                'price' => $priceCents,
                'explicit_net_revenue' => $lineRevenue,
            ];
            $totalPhotoGross += $priceCents;
        }

        if ($totalPhotoGross <= 0) {
            return [];
        }

        $snapshotNet = max(0, (int) ($snapshot->total_net ?? 0));
        if ($hasExplicitAttribution) {
            $photoRevenue = array_map(
                static fn (array $item): int => (int) $item['explicit_net_revenue'],
                $photoItems,
            );
            if (array_sum($photoRevenue) > $snapshotNet) {
                $hasExplicitAttribution = false;
            }
        }

        if (! $hasExplicitAttribution) {
            $discountedPhotoRevenue = max(0, $totalPhotoGross - min($discountCents, $totalPhotoGross));
            $allocablePhotoRevenue = min($snapshotNet, $discountedPhotoRevenue);
            $photoRevenue = $this->allocateCentsProportionally(
                $allocablePhotoRevenue,
                array_column($photoItems, 'price'),
            );
        }

        // A refund is allocated to photo revenue in the same proportion as the
        // normal split, before fee proration and the photographer share.
        $refundCents = min(max(0, $refundCents), array_sum($photoRevenue));
        if ($refundCents > 0) {
            $refundAllocations = $this->allocateCentsProportionally($refundCents, $photoRevenue);
            foreach ($photoRevenue as $lineIndex => $lineRevenue) {
                $photoRevenue[$lineIndex] = max(0, $lineRevenue - ($refundAllocations[$lineIndex] ?? 0));
            }
        }

        $totalPhotoRevenue = array_sum($photoRevenue);
        if ($totalPhotoRevenue <= 0) {
            return [];
        }

        $stripeFeePercent = config('services.stripe.fee_percent', 0.04);
        $feeCents = max(0, (int) ($order->stripe_fee_cents
            ?? round($snapshotNet * max(0, (float) $stripeFeePercent))));
        $feeCents = min($feeCents, $snapshotNet);
        $photoFeeCents = $snapshotNet > 0
            ? min(
                $totalPhotoRevenue,
                (int) bcdiv(
                    bcmul((string) $feeCents, (string) $totalPhotoRevenue, 0),
                    (string) $snapshotNet,
                    0,
                ),
            )
            : 0;
        $itemFees = $this->allocateCentsProportionally($photoFeeCents, $photoRevenue);

        $shares = [];
        foreach ($photoItems as $index => $item) {
            if (! $item['user_id'] || ($photoRevenue[$index] ?? 0) <= 0) {
                continue;
            }

            $netCents = max(0, $photoRevenue[$index] - ($itemFees[$index] ?? 0));
            $shareCents = (int) bcmul((string) $netCents, '0.50', 0);
            $userId = (string) $item['user_id'];
            $shares[$userId] = ($shares[$userId] ?? 0) + $shareCents;
        }

        return $shares;
    }

    /**
     * Allocate an integer cent amount in proportion to integer weights. A
     * cumulative floor distributes every cent exactly once without producing
     * a negative remainder on the final line.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private function allocateCentsProportionally(int $amount, array $weights): array
    {
        $totalWeight = array_sum($weights);
        if ($amount <= 0 || $totalWeight <= 0) {
            return array_fill(0, count($weights), 0);
        }

        $allocations = [];
        $cumulativeWeight = 0;
        $allocatedAmount = 0;
        foreach ($weights as $weight) {
            $cumulativeWeight += max(0, $weight);
            $cumulativeAllocation = (int) bcdiv(
                bcmul((string) $amount, (string) $cumulativeWeight, 0),
                (string) $totalWeight,
                0,
            );
            $allocations[] = $cumulativeAllocation - $allocatedAmount;
            $allocatedAmount = $cumulativeAllocation;
        }

        return $allocations;
    }

    public function finalizeStatements(int $month, int $year)
    {
        DB::transaction(function () use ($month, $year): void {
            $this->assertChronological($month, $year);

            $corrections = PayoutCorrection::query()
                ->whereNull('settled_at')
                ->where(function ($query) use ($month, $year): void {
                    $query->where('closed_year', '<', $year)
                        ->orWhere(function ($query) use ($month, $year): void {
                            $query->where('closed_year', $year)
                                ->where('closed_month', '<', $month);
                        });
                })
                ->orderBy('closed_year')
                ->orderBy('closed_month')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Recreate a statement that was removed during recalculation when
            // it has already-booked correction lines. Also include users with
            // newly claimable lines so a correction can create a zeroed row.
            $bookedCorrectionUsers = PayoutCorrection::query()
                ->where('settled_month', $month)
                ->where('settled_year', $year)
                ->distinct()
                ->pluck('user_id');
            $correctionUsers = $corrections->pluck('user_id')
                ->merge($bookedCorrectionUsers)
                ->unique();
            $claimableUsers = [];

            foreach ($correctionUsers as $userId) {
                $identity = [
                    'user_id' => $userId,
                    'year' => $year,
                    'month' => $month,
                ];
                $statement = PhotographerStatement::query()
                    ->where($identity)
                    ->lockForUpdate()
                    ->first();

                if ($statement !== null && $statement->isLocked()) {
                    continue;
                }

                if ($statement === null) {
                    $statement = PhotographerStatement::query()->createOrFirst(
                        $identity,
                        [
                            'total_shares_earned' => '0.0000',
                            'pool_earnings_cents' => 0,
                            'delta_surcharge_earnings_cents' => 0,
                            'status' => 'pending',
                        ],
                    );
                    $statement = PhotographerStatement::query()
                        ->whereKey($statement->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                if ($statement->isLocked()) {
                    continue;
                }

                $claimableUsers[(string) $userId] = true;
            }

            // Claim every eligible correction into this finalized period. An
            // approved/paid statement remains immutable, so its corrections
            // stay unsettled for a later finalization.
            foreach ($corrections as $correction) {
                if (! isset($claimableUsers[(string) $correction->user_id])) {
                    continue;
                }

                $correction->settled_at = now();
                $correction->settled_month = $month;
                $correction->settled_year = $year;
                $correction->save();
            }

            $statements = PhotographerStatement::query()
                ->where('month', $month)
                ->where('year', $year)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($statements as $stmt) {
                // Locked statements (approved/paid) are immutable: never recompute or
                // downgrade their status — they are the payout audit trail.
                if ($stmt->isLocked()) {
                    continue;
                }

                $correctionCents = (int) PayoutCorrection::query()
                    ->where('user_id', $stmt->user_id)
                    ->where('settled_month', $month)
                    ->where('settled_year', $year)
                    ->sum('amount_cents');
                $stmt->earned_amount_cents = $stmt->pool_earnings_cents
                    + $stmt->delta_surcharge_earnings_cents
                    + $correctionCents;

                // The latest prior statement is the carry boundary, even when its
                // payable is zero. Lock it so a concurrent finalization cannot
                // consume stale rollover state.
                $latestPreviousStatement = PhotographerStatement::query()
                    ->where('user_id', $stmt->user_id)
                    ->where(function ($query) use ($year, $month): void {
                        $query->where('year', '<', $year)
                            ->orWhere(function ($query) use ($year, $month): void {
                                $query->where('year', $year)
                                    ->where('month', '<', $month);
                            });
                    })
                    ->orderByDesc('year')
                    ->orderByDesc('month')
                    ->lockForUpdate()
                    ->first();

                $stmt->rolled_over_amount_cents = $latestPreviousStatement?->status === 'rollover'
                    ? (int) $latestPreviousStatement->total_payable_cents
                    : 0;
                $stmt->total_payable_cents = $stmt->earned_amount_cents + $stmt->rolled_over_amount_cents;

                // Auszahlungsschwelle prüfen (50 Euro = 5000 Cents)
                if ($stmt->total_payable_cents >= 5000) {
                    $stmt->status = 'pending'; // Bereit für die Freigabe durch Super-Admin
                } else {
                    $stmt->status = 'rollover'; // Wird ins nächste Monat übernommen
                }

                $stmt->save();
            }
        }, 3);
    }

    public function assertChronological(int $month, int $year): void
    {
        $blockingStatement = PhotographerStatement::query()
            ->where(function ($query) use ($month, $year): void {
                $query->where('year', '>', $year)
                    ->orWhere(function ($query) use ($month, $year): void {
                        $query->where('year', $year)
                            ->where('month', '>', $month);
                    });
            })
            ->orderBy('year')
            ->orderBy('month')
            ->lockForUpdate()
            ->first();

        if ($blockingStatement === null) {
            return;
        }

        $requestedPeriod = sprintf('%02d/%d', $month, $year);
        $blockingPeriod = sprintf('%02d/%d', $blockingStatement->month, $blockingStatement->year);

        throw new PayoutChronologyException(
            "Die Abrechnung für {$requestedPeriod} ist gesperrt: Für den späteren Monat {$blockingPeriod} existiert bereits eine finale Abrechnung. Bitte rechne die Monate chronologisch ab.",
        );
    }
}
