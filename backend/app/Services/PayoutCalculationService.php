<?php

namespace App\Services;

use App\Models\DownloadLog;
use App\Models\Gallery;
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
        $explicitSingleDownloads = [];

        // Mixed-gallery ZIPs have no single gallery_id, so reconcile their
        // explicit photo payload independently. Explicit single-photo rows
        // still deduplicate by customer/photo; legacy ownerless rows are
        // intentionally skipped rather than assigned to an arbitrary owner.
        foreach ($logs->filter(fn ($log): bool => $log->gallery_id === null && $this->hasExplicitPhotoPayload($log)) as $log) {
            if ($log->item_type !== 'full_zip') {
                $this->recordExplicitSingleDownload($log, $photosById, $galleries, null, $explicitSingleDownloads);

                continue;
            }

            $attribution = $this->calculateExplicitMixedLog($log, $photosById, $galleries);
            $totalDownloads += $attribution['downloads'];
            foreach ($attribution['shares'] as $photographerId => $shares) {
                $totalShares = bcadd($totalShares, (string) $shares, 4);
                $photographerEarnings[$photographerId] = bcadd(
                    $photographerEarnings[$photographerId] ?? '0.0000',
                    (string) $shares,
                    4,
                );
            }
        }

        $userGalleryLogs = $logs->filter(fn ($log): bool => $log->gallery_id !== null)
            ->groupBy(fn ($log): string => $log->user_id.'|'.$log->gallery_id);

        foreach ($userGalleryLogs as $galleryLogs) {
            $galleryId = (string) $galleryLogs->first()->gallery_id;
            $gallery = $galleries->get($galleryId);

            if (! $gallery || $gallery->effective_is_free_download) {
                continue;
            }

            $galleryPhotos = $photosByGallery->get($galleryId) ?? collect();
            $maxMultiplier = 1;
            $zipLog = null;

            foreach ($galleryLogs as $log) {
                if ($log->item_type === 'full_zip' && ! $this->hasPositivePhotoCount($log)) {
                    continue;
                }

                $multiplier = $this->getShareMultiplier($log->resolution_tier);
                $maxMultiplier = max($maxMultiplier, $multiplier);

                if (
                    $log->item_type === 'full_zip'
                    && (! $zipLog || (int) $log->photo_count > (int) $zipLog->photo_count)
                ) {
                    $zipLog = $log;
                }
            }

            $attributedShares = [];
            $attributedPhotoCount = 0;

            if ($zipLog) {
                $explicitOwnerPhotoCounts = $this->resolveExplicitPhotographerCounts(
                    $zipLog,
                    $photosById,
                    $galleryId,
                    true
                );

                if ($explicitOwnerPhotoCounts === null) {
                    // Current ZIP logs may only contain a count. In that case the
                    // full gallery archive is represented by the persisted photos,
                    // so distribute the logged shares by their actual ownership.
                    $ownerPhotoCounts = $galleryPhotos
                        ->whereNotNull('user_id')
                        ->countBy('user_id')
                        ->all();
                    $attributedPhotoCount = (int) $zipLog->photo_count;
                } else {
                    $ownerPhotoCounts = $explicitOwnerPhotoCounts;
                    $attributedPhotoCount = array_sum($ownerPhotoCounts);
                }

                $logShares = bcmul((string) $attributedPhotoCount, (string) $maxMultiplier, 4);
                $attributedShares = $this->allocateSharesByPhotoOwnership($ownerPhotoCounts, $logShares);
            } else {
                $hasExplicitSinglePayload = false;
                $legacySingleLogs = [];

                foreach ($galleryLogs as $log) {
                    if ($log->item_type === 'full_zip') {
                        continue;
                    }

                    $hasExplicitPhotoIdentity = $this->recordExplicitSingleDownload(
                        $log,
                        $photosById,
                        $galleries,
                        $galleryId,
                        $explicitSingleDownloads,
                    );
                    if (! $hasExplicitPhotoIdentity) {
                        $legacySingleLogs[] = $log;
                    }

                    $hasExplicitSinglePayload = $hasExplicitSinglePayload
                        || $hasExplicitPhotoIdentity;
                }

                // Historical single-image rows have no photo identity and
                // cannot be deduplicated. Keep their conservative legacy
                // attribution only when this customer/gallery group has no
                // identity-bearing rows that they might duplicate.
                if (! $hasExplicitSinglePayload) {
                    foreach ($legacySingleLogs as $log) {
                        $galleryPhotographerIds = $galleryPhotos
                            ->pluck('user_id')
                            ->filter()
                            ->unique()
                            ->values();

                        // A legacy single image can be attributed safely when
                        // every possible owner in the gallery is one person.
                        if ($galleryPhotographerIds->count() !== 1) {
                            continue;
                        }

                        $photoCount = (int) $log->photo_count;
                        $photographerId = (string) $galleryPhotographerIds->first();
                        $attributedShares[$photographerId] = bcadd(
                            $attributedShares[$photographerId] ?? '0.0000',
                            (string) ($photoCount * $maxMultiplier),
                            4
                        );
                        $attributedPhotoCount += $photoCount;
                    }
                }
            }

            if ($attributedPhotoCount <= 0 || $attributedShares === []) {
                continue;
            }

            $totalDownloads += $attributedPhotoCount;
            foreach ($attributedShares as $photographerId => $shares) {
                $totalShares = bcadd($totalShares, (string) $shares, 4);
                $photographerEarnings[$photographerId] = bcadd(
                    $photographerEarnings[$photographerId] ?? '0.0000',
                    (string) $shares,
                    4
                );
            }
        }

        foreach ($explicitSingleDownloads as $download) {
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
     * Reconcile one mixed-gallery order ZIP. The order log intentionally has
     * no single gallery_id, but its bounded photo payload is authoritative for
     * photographer attribution.
     *
     * @return array{downloads:int, shares:array<string, string>}
     */
    private function calculateExplicitMixedLog(DownloadLog $log, $photosById, $galleries): array
    {
        if ($log->item_type === 'full_zip' && ! $this->hasPositivePhotoCount($log)) {
            return ['downloads' => 0, 'shares' => []];
        }

        $photoIds = $this->explicitPhotoIds($log, $log->item_type === 'full_zip');
        if ($photoIds === null || $photoIds === []) {
            return ['downloads' => 0, 'shares' => []];
        }

        $payload = $log->payload;
        $allowedGalleryIds = null;
        if (array_key_exists('gallery_ids', $payload)) {
            if (! is_array($payload['gallery_ids'])) {
                return ['downloads' => 0, 'shares' => []];
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
                return ['downloads' => 0, 'shares' => []];
            }
        }

        $ownerPhotoCounts = [];
        foreach ($photoIds as $photoId) {
            $photo = $photosById->get($photoId);
            if (! $photo || ! $photo->user_id) {
                continue;
            }

            $galleryId = (string) $photo->gallery_id;
            if ($allowedGalleryIds !== null && ! in_array($galleryId, $allowedGalleryIds, true)) {
                continue;
            }

            $gallery = $galleries->get($galleryId);
            if (! $gallery || $gallery->effective_is_free_download) {
                continue;
            }

            $photographerId = (string) $photo->user_id;
            $ownerPhotoCounts[$photographerId] = ($ownerPhotoCounts[$photographerId] ?? 0) + 1;
        }

        $downloads = array_sum($ownerPhotoCounts);
        if ($downloads === 0) {
            return ['downloads' => 0, 'shares' => []];
        }

        $shares = bcmul((string) $downloads, (string) $this->getShareMultiplier($log->resolution_tier), 4);

        return [
            'downloads' => $downloads,
            'shares' => $this->allocateSharesByPhotoOwnership($ownerPhotoCounts, $shares),
        ];
    }

    /**
     * Resolve explicit photo IDs from a download log to photographer counts.
     * A null result means that the log has no usable IDs; an empty array means
     * valid photos were listed but none of them has a photographer.
     */
    private function resolveExplicitPhotographerCounts(
        DownloadLog $log,
        $photosById,
        string $galleryId,
        bool $isZip
    ): ?array {
        $photoIds = $this->explicitPhotoIds($log, $isZip);
        if ($photoIds === null) {
            return null;
        }

        $validPhotos = [];
        foreach ($photoIds as $photoId) {
            $photo = $photosById->get($photoId);
            if ($photo && (string) $photo->gallery_id === $galleryId) {
                $validPhotos[] = $photo;
            }
        }

        if ($validPhotos === []) {
            // A present but unusable explicit payload is not a license to fall
            // back to every photo in the gallery. Only a genuinely absent
            // payload (null) uses the legacy single-owner/count fallback.
            return [];
        }

        $ownerPhotoCounts = [];
        foreach ($validPhotos as $photo) {
            if (! $photo->user_id) {
                continue;
            }

            $photographerId = (string) $photo->user_id;
            $ownerPhotoCounts[$photographerId] = ($ownerPhotoCounts[$photographerId] ?? 0) + 1;
        }

        return $ownerPhotoCounts;
    }

    /**
     * Record the best known single-photo resolution for one customer/photo
     * pair. Returns true when the row carries an explicit photo identity, even
     * when that identity is invalid, so it cannot fall back to an ambiguous
     * legacy count.
     *
     * @param  array<string, array{photographer_id: string, multiplier: int}>  $downloads
     */
    private function recordExplicitSingleDownload(
        DownloadLog $log,
        $photosById,
        $galleries,
        ?string $requiredGalleryId,
        array &$downloads,
    ): bool {
        $photoIds = $this->explicitPhotoIds($log, false);
        if ($photoIds === null) {
            return false;
        }

        $multiplier = $this->getShareMultiplier($log->resolution_tier);
        foreach ($photoIds as $photoId) {
            $photo = $photosById->get($photoId);
            if (! $photo || ! $photo->user_id) {
                continue;
            }

            $galleryId = (string) $photo->gallery_id;
            if ($requiredGalleryId !== null && $galleryId !== $requiredGalleryId) {
                continue;
            }

            $gallery = $galleries->get($galleryId);
            if (! $gallery || $gallery->effective_is_free_download) {
                continue;
            }

            // A customer/photo pair contributes once per pool month, at the
            // best resolution downloaded for that photo.
            $identity = (string) $log->user_id.'|'.$photoId;
            if (! isset($downloads[$identity]) || $multiplier > $downloads[$identity]['multiplier']) {
                $downloads[$identity] = [
                    'photographer_id' => (string) $photo->user_id,
                    'multiplier' => $multiplier,
                ];
            }
        }

        return true;
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

            // Invoice line items retain their pre-discount photo prices and
            // record coupons/volume discounts as negative `row_total` lines.
            // Allocate the discounted snapshot net across all photo lines
            // proportionally before filtering by photographer ownership. This
            // is conservative for legacy/malformed snapshots and cannot assign
            // revenue that was not actually collected (or assign an unowned
            // line's amount to an eligible photographer).
            $photoItems = [];
            $totalPhotoGross = 0;
            $discountCents = 0;
            foreach ($items as $item) {
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

                $priceCents = (int) $item['price'];
                if ($priceCents <= 0) {
                    continue;
                }

                $photo = $photos->get((string) $item['photoId']);
                $photoItems[] = [
                    'user_id' => $photo?->user_id,
                    'price' => $priceCents,
                ];
                $totalPhotoGross += $priceCents;
            }

            if ($totalPhotoGross <= 0) {
                continue;
            }

            $snapshotNet = max(0, (int) ($snapshot->total_net ?? 0));
            $discountedPhotoRevenue = max(0, $totalPhotoGross - min($discountCents, $totalPhotoGross));
            $allocablePhotoRevenue = min($snapshotNet, $discountedPhotoRevenue);
            if ($allocablePhotoRevenue <= 0) {
                continue;
            }

            $photoRevenue = $this->allocateCentsProportionally(
                $allocablePhotoRevenue,
                array_column($photoItems, 'price'),
            );

            // Items without a resolvable photographer still participate in
            // discount allocation, but their revenue is never reassigned.
            $eligibleItems = [];
            $totalEligibleRevenue = 0;
            foreach ($photoItems as $index => $item) {
                if (! $item['user_id'] || ($photoRevenue[$index] ?? 0) <= 0) {
                    continue;
                }

                $netRevenue = $photoRevenue[$index];
                $eligibleItems[] = ['user_id' => $item['user_id'], 'price' => $netRevenue];
                $totalEligibleRevenue += $netRevenue;
            }

            if ($totalEligibleRevenue <= 0) {
                continue;
            }

            // Nutze die exakte Gebühr aus der Datenbank (Fallback auf Prozentrechnung).
            // Die Gebühr fällt pro Bestellung an und wird hier einmalig proportional
            // auf die Positionen verteilt — niemals je Position voll abgezogen.
            $feeCents = max(0, (int) ($order->stripe_fee_cents
                ?? round($totalEligibleRevenue * max(0, (float) $stripeFeePercent))));
            $feeCents = min($feeCents, $totalEligibleRevenue);
            $itemFees = $this->allocateCentsProportionally(
                $feeCents,
                array_column($eligibleItems, 'price'),
            );

            foreach ($eligibleItems as $index => $item) {
                $netCents = max(0, $item['price'] - ($itemFees[$index] ?? 0));

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
