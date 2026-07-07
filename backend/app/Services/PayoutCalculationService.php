<?php

namespace App\Services;

use App\Models\DownloadLog;
use App\Models\PayoutPool;
use App\Models\PhotographerStatement;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\Photo;
use Carbon\Carbon;

class PayoutCalculationService
{
    public function getShareMultiplier($tier)
    {
        return match($tier) {
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

        $userGalleryLogs = $logs->groupBy(function($log) {
            return $log->user_id . '_' . $log->gallery_id;
        });

        // N+1 Fix: Load all required galleries and photographer IDs in one go
        $galleryIds = $logs->pluck('gallery_id')->unique()->filter()->toArray();
        $galleries = Gallery::whereIn('id', $galleryIds)->get()->keyBy('id');
        $galleryPhotographers = Photo::whereIn('gallery_id', $galleryIds)
            ->select('gallery_id', 'user_id')
            ->groupBy('gallery_id', 'user_id')
            ->get()
            ->keyBy('gallery_id');

        foreach ($userGalleryLogs as $key => $galleryLogs) {
            $galleryId = $galleryLogs->first()->gallery_id;
            $gallery = $galleries->get($galleryId);

            if (!$gallery || $gallery->effective_is_free_download) continue;

            $photographerId = $galleryPhotographers->get($galleryId)?->user_id;
            if (!$photographerId) continue;

            $maxMultiplier = 1;
            $maxPhotoCount = 0;
            $singleImageCount = 0;
            $hasZip = false;

            foreach ($galleryLogs as $log) {
                $mult = $this->getShareMultiplier($log->resolution_tier);
                if ($mult > $maxMultiplier) $maxMultiplier = $mult;

                if ($log->item_type === 'full_zip') {
                    $hasZip = true;
                    $maxPhotoCount = max($maxPhotoCount, $log->photo_count);
                } else {
                    $singleImageCount += $log->photo_count;
                }
            }

            $finalPhotoCount = $hasZip ? $maxPhotoCount : $singleImageCount;
            $shares = bcmul((string)$finalPhotoCount, (string)$maxMultiplier, 4);

            $totalShares = bcadd($totalShares, $shares, 4);
            $totalDownloads += $finalPhotoCount;

            if (!isset($photographerEarnings[$photographerId])) {
                $photographerEarnings[$photographerId] = '0.0000';
            }
            $photographerEarnings[$photographerId] = bcadd($photographerEarnings[$photographerId], $shares, 4);
        }

        $pool->total_shares = $totalShares;
        $pool->total_unique_downloads = $totalDownloads;
        // bcdiv mit Scale 0 verhält sich für positive Zahlen wie floor()
        $pool->value_per_share_cents = (float)$totalShares > 0 ? (int) bcdiv((string)$pool->net_pool_cents, $totalShares, 0) : 0;
        $pool->save();

        foreach ($photographerEarnings as $photogId => $shares) {
            $earnings = (int) bcmul($shares, (string)$pool->value_per_share_cents, 0);
            $earnings = (int) bcdiv(bcmul((string)$earnings, (string)$pool->photographer_share_percent, 0), '100', 0);

            $existingLocked = PhotographerStatement::where('user_id', $photogId)
                ->where('month', $pool->month)
                ->where('year', $pool->year)
                ->whereIn('status', ['approved', 'paid'])
                ->first();
            if ($existingLocked) {
                continue;
            }

            $stmt = PhotographerStatement::firstOrNew([
                'user_id' => $photogId, 'month' => $pool->month, 'year' => $pool->year
            ]);

            $stmt->total_shares_earned = bcadd((string)($stmt->total_shares_earned ?? '0.0000'), $shares, 4);
            $stmt->pool_earnings_cents = ($stmt->pool_earnings_cents ?? 0) + $earnings;
            $stmt->save();
        }

        return $pool;
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
            if (!$snapshot) continue;
            $items = $snapshot->customer_details['items'] ?? [];
            foreach ($items as $item) {
                if (isset($item['photoId'])) $photoIds[] = $item['photoId'];
            }
        }
        // Memory-Optimierung: Nur die benötigten Spalten laden, um das PHP Memory Limit zu schonen
        $photos = Photo::select('id', 'user_id')->whereIn('id', array_unique($photoIds))->get()->keyBy('id');

        $stripeFeePercent = config('services.stripe.fee_percent', 0.04);

        foreach ($orders as $order) {
            $snapshot = $order->invoiceSnapshot;
            if (!$snapshot) continue;

            $items = $snapshot->customer_details['items'] ?? [];
            foreach ($items as $item) {
                if (!isset($item['photoId']) || !isset($item['price']) || $item['price'] <= 0) continue;
                
                $photo = $photos->get($item['photoId']);
                if (!$photo || !$photo->user_id) continue;

                $priceCents = (int) $item['price'];
                
                // Nutze die exakte Gebühr aus der Datenbank (Fallback auf Prozentrechnung)
                $feeCents = $order->stripe_fee_cents ?? (int) round($priceCents * $stripeFeePercent);
                $netCents = $priceCents - $feeCents;
                
                // Fotografen-Anteil: 50% vom Netto-Aufpreis (exakt ueber bcmath)
                $photogShareCents = (int) bcmul((string)$netCents, '0.50', 0);

                $existingLocked = PhotographerStatement::where('user_id', $photo->user_id)
                    ->where('month', $month)
                    ->where('year', $year)
                    ->whereIn('status', ['approved', 'paid'])
                    ->first();
                if ($existingLocked) {
                    continue;
                }

                $stmt = PhotographerStatement::firstOrNew([
                    'user_id' => $photo->user_id, 'month' => $month, 'year' => $year
                ]);
                $stmt->delta_surcharge_earnings_cents = ($stmt->delta_surcharge_earnings_cents ?? 0) + $photogShareCents;
                $stmt->save();
            }
        }
    }

    public function finalizeStatements(int $month, int $year)
    {
        $statements = PhotographerStatement::where('month', $month)->where('year', $year)->get();
        
        foreach ($statements as $stmt) {
            $stmt->earned_amount_cents = $stmt->pool_earnings_cents + $stmt->delta_surcharge_earnings_cents;

            // Rollover vom Vormonat holen
            $prevDate = Carbon::create($year, $month, 1)->subMonth();
            $prevStmt = PhotographerStatement::where('user_id', $stmt->user_id)
                ->where('month', $prevDate->month)
                ->where('year', $prevDate->year)
                ->where('status', 'rollover')
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
    }
}
