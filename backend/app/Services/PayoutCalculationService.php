<?php

namespace App\Services;

use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\PayoutPool;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use Carbon\Carbon;
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
                if (in_array($statement->status, ['approved', 'paid'], true)) {
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

                if (in_array($statement->status, ['approved', 'paid'], true)) {
                    continue;
                }

                $statement->total_shares_earned = $contribution['shares'];
                $statement->pool_earnings_cents = $contribution['earnings_cents'];
                $statement->save();
            }
        }, 3);
    }

    /**
     * Add one surcharge contribution to the statement for a photographer/month.
     *
     * The natural-key index makes the insert race-safe; the row lock makes the
     * read/modify/write arithmetic atomic and also serializes approval. A
     * locked statement is checked only after the lock is acquired, so an
     * approval cannot be overwritten by a concurrent calculation.
     */
    private function addStatementDelta(
        string $userId,
        int $month,
        int $year,
        int $deltaEarningsCents,
    ): void {
        DB::transaction(function () use (
            $userId,
            $month,
            $year,
            $deltaEarningsCents,
        ): void {
            $identity = [
                'user_id' => $userId,
                'year' => $year,
                'month' => $month,
            ];

            $statement = PhotographerStatement::query()
                ->where($identity)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($statement === null) {
                // createOrFirst uses a savepoint to turn a concurrent unique
                // insert into a read of the winning row. The V040 index is the
                // final authority across independent application processes.
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

            if (in_array($statement->status, ['approved', 'paid'], true)) {
                return;
            }

            $statement->delta_surcharge_earnings_cents =
                ($statement->delta_surcharge_earnings_cents ?? 0) + $deltaEarningsCents;
            $statement->save();
        }, 3);
    }

    public function calculatePowerUserDelta(int $month, int $year)
    {
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // Wir werten alle bezahlten Shop-Bestellungen in diesem Monat aus (Keine manuellen Angebote)
        $orders = Order::where('status', 'paid')
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

        $stripeFeePercent = config('services.stripe.fee_percent', 0.04);

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

            // New checkout snapshots carry exact post-volume/post-coupon photo
            // revenue separately from their customer-facing aggregate discount
            // lines. Historical snapshots cannot reveal which photos a targeted
            // coupon covered, so retain a capped, proportional fallback instead
            // of pretending that their original item-level coverage is known.
            $photoItems = [];
            $totalPhotoGross = 0;
            $discountCents = 0;
            $payoutAttribution = is_array($customerDetails)
                ? ($customerDetails[InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY] ?? null)
                : null;
            $hasExplicitAttribution = is_array($payoutAttribution);
            $explicitAttributionTotal = 0;
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
                $lineRevenue = $hasLineAttribution
                    ? (int) $payoutAttribution[(string) $lineIndex]
                    : null;
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
                continue;
            }

            $snapshotNet = max(0, (int) ($snapshot->total_net ?? 0));
            if ($hasExplicitAttribution) {
                $photoRevenue = array_map(
                    fn (array $item): int => (int) $item['explicit_net_revenue'],
                    $photoItems,
                );
                $explicitAttributionTotal = array_sum($photoRevenue);
                if ($explicitAttributionTotal > $snapshotNet) {
                    $hasExplicitAttribution = false;
                }
            }

            if (! $hasExplicitAttribution) {
                // Aggregate-only legacy snapshots do not identify targeted
                // coupon coverage. A proportional split is the conservative
                // best-effort fallback, capped by both their itemized discounts
                // and the immutable amount actually recorded as collected.
                $discountedPhotoRevenue = max(0, $totalPhotoGross - min($discountCents, $totalPhotoGross));
                $allocablePhotoRevenue = min($snapshotNet, $discountedPhotoRevenue);
                $photoRevenue = $this->allocateCentsProportionally(
                    $allocablePhotoRevenue,
                    array_column($photoItems, 'price'),
                );
            }

            $totalPhotoRevenue = array_sum($photoRevenue);
            if ($totalPhotoRevenue <= 0) {
                continue;
            }

            // Allocate the order fee against the amount collected for the
            // whole order, then prorate only the photo-backed portion. Fees
            // belonging to non-photo lines and unowned photos are never shifted
            // onto another photographer's revenue.
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

            foreach ($photoItems as $index => $item) {
                if (! $item['user_id'] || ($photoRevenue[$index] ?? 0) <= 0) {
                    continue;
                }

                $netCents = max(0, $photoRevenue[$index] - ($itemFees[$index] ?? 0));

                // Fotografen-Anteil: 50% vom Netto-Aufpreis (exakt ueber bcmath)
                $photogShareCents = (int) bcmul((string) $netCents, '0.50', 0);

                $this->addStatementDelta(
                    (string) $item['user_id'],
                    $month,
                    $year,
                    $photogShareCents,
                );
            }
        }
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
            $statements = PhotographerStatement::query()
                ->where('month', $month)
                ->where('year', $year)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($statements as $stmt) {
                // Locked statements (approved/paid) are immutable: never recompute or
                // downgrade their status — they are the payout audit trail.
                if (in_array($stmt->status, ['approved', 'paid'], true)) {
                    continue;
                }

                $stmt->earned_amount_cents = $stmt->pool_earnings_cents + $stmt->delta_surcharge_earnings_cents;

                // Rollover vom Vormonat holen
                $prevDate = Carbon::create($year, $month, 1)->subMonth();
                $prevStmt = PhotographerStatement::query()
                    ->where('user_id', $stmt->user_id)
                    ->where('month', $prevDate->month)
                    ->where('year', $prevDate->year)
                    ->where('status', 'rollover')
                    ->lockForUpdate()
                    ->first();

                $stmt->rolled_over_amount_cents = $prevStmt ? $prevStmt->total_payable_cents : 0;
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
}
