<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\DownloadLog;
use App\Models\PayoutPool;
use App\Services\PayoutCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class PayoutSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_weighted_share_calculation_and_deduplication()
    {
        $photog1 = User::factory()->create();
        $photog2 = User::factory()->create();
        $client = User::factory()->create();

        $gallery1 = Gallery::factory()->create(['type' => 'delivery', 'is_free_download' => false]);
        $gallery2 = Gallery::factory()->create(['type' => 'delivery', 'is_free_download' => false]);
        $freeGallery = Gallery::factory()->create(['type' => 'delivery', 'is_free_download' => true]);

        Photo::factory()->count(10)->create(['gallery_id' => $gallery1->id, 'user_id' => $photog1->id]);
        Photo::factory()->count(5)->create(['gallery_id' => $gallery2->id, 'user_id' => $photog2->id]);
        Photo::factory()->count(2)->create(['gallery_id' => $freeGallery->id, 'user_id' => $photog1->id]);

        $now = Carbon::now();

        // Gal1: 2 single images @ Web res (1 * 2 = 2 shares)
        DownloadLog::create(['user_id' => $client->id, 'gallery_id' => $gallery1->id, 'item_type' => 'single_image', 'resolution_tier' => 'web', 'photo_count' => 1, 'created_at' => $now]);
        DownloadLog::create(['user_id' => $client->id, 'gallery_id' => $gallery1->id, 'item_type' => 'single_image', 'resolution_tier' => 'web', 'photo_count' => 1, 'created_at' => $now]);

        // Gal2: FULL ZIP @ Original res (5 photos * 4 mult = 20 shares). Single image gets deduplicated by "Best Share" Zip.
        DownloadLog::create(['user_id' => $client->id, 'gallery_id' => $gallery2->id, 'item_type' => 'single_image', 'resolution_tier' => 'print', 'photo_count' => 1, 'created_at' => $now]);
        DownloadLog::create(['user_id' => $client->id, 'gallery_id' => $gallery2->id, 'item_type' => 'full_zip', 'resolution_tier' => 'original', 'photo_count' => 5, 'created_at' => $now]);

        // Free Gallery: Excluded = 0 shares
        DownloadLog::create(['user_id' => $client->id, 'gallery_id' => $freeGallery->id, 'item_type' => 'full_zip', 'resolution_tier' => 'original', 'photo_count' => 2, 'created_at' => $now]);

        $pool = PayoutPool::create([
            'month' => $now->month,
            'year' => $now->year,
            'net_pool_cents' => 10000,
            'photographer_share_percent' => 50,
        ]);

        $service = app(PayoutCalculationService::class);
        $service->calculatePoolShares($pool);

        $pool->refresh();

        // 2 (gal1) + 5 (gal2) = 7 unique downloads
        // (2 * 1) + (5 * 4) = 22 total shares
        $this->assertEquals(22, $pool->total_shares);
        $this->assertEquals(7, $pool->total_unique_downloads);

        // 10000 cents / 22 shares = 454 cents per share
        $this->assertEquals(454, $pool->value_per_share_cents);

        $stmt1 = \App\Models\PhotographerStatement::where('user_id', $photog1->id)->first();
        $stmt2 = \App\Models\PhotographerStatement::where('user_id', $photog2->id)->first();

        // Photog1: 2 shares * 454 = 908 cents * 50% = 454 cents
        $this->assertEquals(2, $stmt1->total_shares_earned);
        $this->assertEquals(454, $stmt1->pool_earnings_cents);

        // Photog2: 20 shares * 454 = 9080 cents * 50% = 4540 cents
        $this->assertEquals(20, $stmt2->total_shares_earned);
        $this->assertEquals(4540, $stmt2->pool_earnings_cents);
    }

    public function test_power_user_delta_surcharge_logic()
    {
        $photog = User::factory()->create();
        $client = User::factory()->create(['flatrate_level' => 'web']);
        
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photog->id]);

        $now = Carbon::now();

        // Bezahlte Bestellung (10€ Aufpreis / 1000 Cents)
        $order = \App\Models\Order::create([
            'user_id' => $client->id,
            'status' => 'paid',
            'is_quote_request' => false,
            'total_amount' => 1000,
            'created_at' => $now
        ]);

        \App\Models\InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'RE-TEST',
            'customer_details' => [
                'items' => [
                    ['photoId' => $photo->id, 'tier' => 'original', 'price' => 1000]
                ]
            ],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
            'created_at' => $now
        ]);

        $service = app(PayoutCalculationService::class);
        $service->calculatePowerUserDelta($now->month, $now->year);

        $stmt = \App\Models\PhotographerStatement::where('user_id', $photog->id)->first();
        
        // Assert: 1000 Cents - 40 Cents Fee = 960 Netto -> 50% Share = 480 Cents (4,80€)
        $this->assertEquals(480, $stmt->delta_surcharge_earnings_cents);
    }

    public function test_rollover_and_payout_threshold()
    {
        $photog = User::factory()->create();
        
        $now = Carbon::now();
        $prev = $now->copy()->subMonth();

        // Rollover Statement aus dem Vormonat (30€)
        \App\Models\PhotographerStatement::create([
            'user_id' => $photog->id,
            'month' => $prev->month,
            'year' => $prev->year,
            'total_payable_cents' => 3000,
            'status' => 'rollover'
        ]);

        // Aktuelles Statement (25€ Pool Earnings)
        $stmt = \App\Models\PhotographerStatement::create([
            'user_id' => $photog->id,
            'month' => $now->month,
            'year' => $now->year,
            'pool_earnings_cents' => 2500,
            'delta_surcharge_earnings_cents' => 0,
            'status' => 'pending' // Temp state
        ]);

        $service = app(PayoutCalculationService::class);
        $service->finalizeStatements($now->month, $now->year);

        $stmt->refresh();

        // Assert: 30€ (Rollover) + 25€ (Earned) = 55€ (Total) -> >= 50€ -> pending
        $this->assertEquals(3000, $stmt->rolled_over_amount_cents);
        $this->assertEquals(2500, $stmt->earned_amount_cents);
        $this->assertEquals(5500, $stmt->total_payable_cents);
        $this->assertEquals('pending', $stmt->status);

        // Assert: Unter Auszahlungsschwelle
        $stmt2 = \App\Models\PhotographerStatement::create([
            'user_id' => User::factory()->create()->id,
            'month' => $now->month,
            'year' => $now->year,
            'pool_earnings_cents' => 1000, // 10€
            'delta_surcharge_earnings_cents' => 0,
            'status' => 'pending'
        ]);
        
        $service->finalizeStatements($now->month, $now->year);
        $stmt2->refresh();
        $this->assertEquals(1000, $stmt2->total_payable_cents);
        $this->assertEquals('rollover', $stmt2->status); // Da < 50€
    }
}

