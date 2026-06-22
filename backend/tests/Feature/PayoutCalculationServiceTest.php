<?php

namespace Tests\Feature;

use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\PayoutPool;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use App\Models\User;
use App\Services\PayoutCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BK-05 · P1 — Feature-Tests für PayoutCalculationService.
 *
 * Service wird im setUp() als `new PayoutCalculationService()` instanziiert.
 * Geld-Werte sind Cents. bcmath mit expliziter Skalierung (4 Dez. für Shares,
 * 0 Dez. für Cents). Kein tearDown (RefreshDatabase übernimmt Cleanup).
 */
class PayoutCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PayoutCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PayoutCalculationService();
    }

    /**
     * DownloadLog mit kontrollierbarem created_at anlegen.
     *
     * Eloquent setzt created_at beim Insert immer auf now() (Timestamp-Trait),
     * deshalb setzen wir den gewünschten Zeitpunkt per Query-Builder nachträglich.
     * Der PayoutCalculationService filtert Logs nach whereBetween('created_at', ...),
     * daher ist ein korrektes created_at für die Monats-Zuordnung essenziell.
     */
    private function logDownload(array $attributes): DownloadLog
    {
        $createdAt = $attributes['created_at'] ?? null;
        $log = DownloadLog::factory()->create($attributes);
        if ($createdAt !== null) {
            DownloadLog::where('id', $log->id)->update(['created_at' => $createdAt]);
            return DownloadLog::find($log->id);
        }
        return $log;
    }

    // ============================================================
    // calculatePoolShares()
    // ============================================================

    public function test_calculate_pool_shares_skips_logs_without_user_id(): void
    {
        $pool = PayoutPool::factory()->forMonth(6, 2026)->withNetPool(10000)->create();
        $gallery = Gallery::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id]);

        $this->logDownload([
            'user_id' => null,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
            'photo_count' => 1,
        ]);

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('0.0000', $pool->total_shares);
        $this->assertSame(0, $pool->total_unique_downloads);
        $this->assertSame(0, $pool->value_per_share_cents);
    }

    public function test_calculate_pool_shares_skips_free_download_galleries(): void
    {
        $pool = PayoutPool::factory()->forMonth(6, 2026)->withNetPool(10000)->create();
        $gallery = Gallery::factory()->create(['is_free_download' => true]);
        Photo::factory()->create(['gallery_id' => $gallery->id]);

        $this->logDownload([
            'user_id' => User::factory()->create()->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'original',
            'photo_count' => 1,
        ]);

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('0.0000', $pool->total_shares);
        $this->assertSame(0, $pool->total_unique_downloads);
    }

    public function test_calculate_pool_shares_skips_when_photographer_id_is_null(): void
    {
        // Photo ohne user_id → galleryPhotographers->get($galleryId)?->user_id = null → skip.
        $pool = PayoutPool::factory()->forMonth(6, 2026)->withNetPool(10000)->create();
        $gallery = Gallery::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => null]);

        $this->logDownload([
            'user_id' => User::factory()->create()->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
            'photo_count' => 1,
        ]);

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('0.0000', $pool->total_shares);
    }

    public function test_calculate_pool_shares_single_image_uses_multiplier_and_floor(): void
    {
        // 1 Single-Download web (mult=1) → 1 share.
        // net=10000 / 1 share = 10000, floor == 10000.
        $pool = PayoutPool::factory()
            ->forMonth(6, 2026)
            ->withNetPool(10000)
            ->create(['photographer_share_percent' => 50]);
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        $this->logDownload([
            'user_id' => User::factory()->create()->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
            'photo_count' => 1,
            'created_at' => '2026-06-15 12:00:00',
        ]);

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('1.0000', $pool->total_shares);
        $this->assertSame(1, $pool->total_unique_downloads);
        $this->assertSame(10000, $pool->value_per_share_cents);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->first();
        $this->assertNotNull($stmt);
        $this->assertSame('1.0000', $stmt->total_shares_earned);
        // 1 share * 10000 vps * 50% = 5000 cents
        $this->assertSame(5000, $stmt->pool_earnings_cents);
    }

    public function test_calculate_pool_shares_floor_truncates_remainder(): void
    {
        // 3 shares, net=10000 → 10000/3 = 3333,33 → floor 3333.
        $pool = PayoutPool::factory()
            ->forMonth(6, 2026)
            ->withNetPool(10000)
            ->create(['photographer_share_percent' => 100]);
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        // 3 separate User-Gallery-Paare mit je 1 Single-Download web → 3 shares.
        for ($i = 0; $i < 3; $i++) {
            $g = Gallery::factory()->create();
            Photo::factory()->create(['gallery_id' => $g->id, 'user_id' => $photographer->id]);
            $this->logDownload([
                'user_id' => User::factory()->create()->id,
                'gallery_id' => $g->id,
                'item_type' => 'single_image',
                'resolution_tier' => 'web',
                'photo_count' => 1,
                'created_at' => '2026-06-15 12:00:00',
            ]);
        }

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('3.0000', $pool->total_shares);
        $this->assertSame(3, $pool->total_unique_downloads);
        // 10000 / 3 = 3333,33 → floor → 3333 (REVIEW: floor via bcdiv scale 0)
        $this->assertSame(3333, $pool->value_per_share_cents);
    }

    public function test_calculate_pool_shares_zip_uses_max_photo_count(): void
    {
        // full_zip mit photo_count=5, mult original=4 → 20 shares.
        $pool = PayoutPool::factory()
            ->forMonth(7, 2026)
            ->withNetPool(20000)
            ->create(['photographer_share_percent' => 100]);
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);
        $user = User::factory()->create();

        $this->logDownload([
            'user_id' => $user->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'full_zip',
            'resolution_tier' => 'original',
            'photo_count' => 5,
            'created_at' => '2026-07-10 12:00:00',
        ]);

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('20.0000', $pool->total_shares); // 5 * 4 = 20
        $this->assertSame(5, $pool->total_unique_downloads);
        $this->assertSame(1000, $pool->value_per_share_cents); // 20000 / 20
    }

    public function test_calculate_pool_shares_zip_takes_max_when_multiple_zips(): void
    {
        // Zwei ZIPs desselben Users in derselben Gallery: photo_count 3 und 8.
        // Erwartung: max(3, 8) = 8 → nicht Summe 11 (REVIEW: Service nimmt max, nicht sum).
        $pool = PayoutPool::factory()->forMonth(7, 2026)->withNetPool(8000)->create();
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);
        $user = User::factory()->create();

        foreach ([3, 8] as $count) {
            $this->logDownload([
                'user_id' => $user->id,
                'gallery_id' => $gallery->id,
                'item_type' => 'full_zip',
                'resolution_tier' => 'web',
                'photo_count' => $count,
                'created_at' => '2026-07-10 12:00:00',
            ]);
        }

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('8.0000', $pool->total_shares); // max(3,8) * mult 1 = 8
        $this->assertSame(8, $pool->total_unique_downloads);
    }

    public function test_calculate_pool_shares_mixed_zip_and_single_prefers_zip(): void
    {
        // REVIEW: Bei Mix ZIP + Single im selben User-Gallery-Paar gewinnt der ZIP
        // (finalPhotoCount = maxPhotoCount), die Single-Downloads werden ignoriert.
        $pool = PayoutPool::factory()->forMonth(7, 2026)->withNetPool(10000)->create();
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);
        $user = User::factory()->create();

        // 1 ZIP photo_count=4 + 2 Single-Downloads → nur ZIP zählt: 4 Fotos.
        $this->logDownload([
            'user_id' => $user->id, 'gallery_id' => $gallery->id,
            'item_type' => 'full_zip', 'resolution_tier' => 'web', 'photo_count' => 4,
            'created_at' => '2026-07-10 12:00:00',
        ]);
        $this->logDownload([
            'user_id' => $user->id, 'gallery_id' => $gallery->id,
            'item_type' => 'single_image', 'resolution_tier' => 'web', 'photo_count' => 1,
            'created_at' => '2026-07-10 12:01:00',
        ]);
        $this->logDownload([
            'user_id' => $user->id, 'gallery_id' => $gallery->id,
            'item_type' => 'single_image', 'resolution_tier' => 'web', 'photo_count' => 1,
            'created_at' => '2026-07-10 12:02:00',
        ]);

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        // 4 (ZIP, max) * mult 1 (web) = 4 shares. Single-Downloads fallen weg.
        $this->assertSame('4.0000', $pool->total_shares);
        $this->assertSame(4, $pool->total_unique_downloads);
    }

    public function test_calculate_pool_shares_uses_max_multiplier_across_logs(): void
    {
        // Zwei Logs selbes User-Gallery-Paar: web (1) + original (4) → maxMult=4.
        // photo_count je 1 (single) → finalCount=2 (Summe Single) → 2 * 4 = 8 shares.
        $pool = PayoutPool::factory()->forMonth(7, 2026)->withNetPool(8000)->create();
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);
        $user = User::factory()->create();

        $this->logDownload([
            'user_id' => $user->id, 'gallery_id' => $gallery->id,
            'item_type' => 'single_image', 'resolution_tier' => 'web', 'photo_count' => 1,
            'created_at' => '2026-07-10 12:00:00',
        ]);
        $this->logDownload([
            'user_id' => $user->id, 'gallery_id' => $gallery->id,
            'item_type' => 'single_image', 'resolution_tier' => 'original', 'photo_count' => 1,
            'created_at' => '2026-07-10 12:01:00',
        ]);

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('8.0000', $pool->total_shares); // (1+1) * max(1,4)
        $this->assertSame(2, $pool->total_unique_downloads);
    }

    public function test_calculate_pool_shares_groups_by_user_gallery_pair(): void
    {
        // Zwei User in derselben Gallery → zwei Gruppen → beide zählen.
        $pool = PayoutPool::factory()->forMonth(7, 2026)->withNetPool(20000)->create();
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        foreach ([User::factory()->create(), User::factory()->create()] as $user) {
            $this->logDownload([
                'user_id' => $user->id, 'gallery_id' => $gallery->id,
                'item_type' => 'single_image', 'resolution_tier' => 'web', 'photo_count' => 1,
                'created_at' => '2026-07-10 12:00:00',
            ]);
        }

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('2.0000', $pool->total_shares); // 2 Gruppen je 1 share
    }

    public function test_calculate_pool_shares_zero_net_cents_yields_zero_value_per_share(): void
    {
        $pool = PayoutPool::factory()->forMonth(7, 2026)->withNetPool(0)->create();
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        $this->logDownload([
            'user_id' => User::factory()->create()->id, 'gallery_id' => $gallery->id,
            'item_type' => 'single_image', 'resolution_tier' => 'web', 'photo_count' => 1,
            'created_at' => '2026-07-10 12:00:00',
        ]);

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('1.0000', $pool->total_shares);
        $this->assertSame(0, $pool->value_per_share_cents);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->first();
        $this->assertSame(0, $stmt->pool_earnings_cents); // 1 * 0 * 50% = 0
    }

    public function test_calculate_pool_shares_no_logs_results_in_zero_division_safe_behavior(): void
    {
        // REVIEW: totalShares = '0.0000' → Service guard (Z.92) verhindert Division-durch-Null.
        // value_per_share_cents = 0, kein PhotographerStatement wird erstellt.
        $pool = PayoutPool::factory()->forMonth(8, 2026)->withNetPool(10000)->create();

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('0.0000', $pool->total_shares);
        $this->assertSame(0, $pool->total_unique_downloads);
        $this->assertSame(0, $pool->value_per_share_cents);
        $this->assertSame(0, PhotographerStatement::count());
    }

    public function test_calculate_pool_shares_filters_logs_outside_month_window(): void
    {
        // Log in einem anderen Monat wird nicht berücksichtigt.
        $pool = PayoutPool::factory()->forMonth(6, 2026)->withNetPool(10000)->create();
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        $this->logDownload([
            'user_id' => User::factory()->create()->id, 'gallery_id' => $gallery->id,
            'item_type' => 'single_image', 'resolution_tier' => 'web', 'photo_count' => 1,
            'created_at' => '2026-05-15 12:00:00', // Mai, außerhalb des Juni-Pools
        ]);

        $this->service->calculatePoolShares($pool->fresh());

        $pool->refresh();
        $this->assertSame('0.0000', $pool->total_shares);
    }

    public function test_calculate_pool_shares_creates_statement_when_absent(): void
    {
        $pool = PayoutPool::factory()
            ->forMonth(6, 2026)->withNetPool(10000)
            ->create(['photographer_share_percent' => 100]);
        $gallery = Gallery::factory()->create();
        $photographer = User::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        $this->logDownload([
            'user_id' => User::factory()->create()->id, 'gallery_id' => $gallery->id,
            'item_type' => 'single_image', 'resolution_tier' => 'web', 'photo_count' => 1,
            'created_at' => '2026-06-15 12:00:00',
        ]);

        $this->assertSame(0, PhotographerStatement::count());
        $this->service->calculatePoolShares($pool->fresh());

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->first();
        $this->assertNotNull($stmt);
        $this->assertSame(6, $stmt->month);
        $this->assertSame(2026, $stmt->year);
        $this->assertNotEmpty($stmt->sequence_number); // auto-generated
    }

    public function test_calculate_pool_shares_accumulates_into_existing_statement(): void
    {
        // REVIEW: firstOrNew lädt bestehendes Statement und addiert Shares/Earnings.
        $pool = PayoutPool::factory()
            ->forMonth(6, 2026)->withNetPool(10000)
            ->create(['photographer_share_percent' => 100]);
        $photographer = User::factory()->create();

        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 6, 'year' => 2026,
            'total_shares_earned' => '5.0000', 'pool_earnings_cents' => 3000,
        ]);

        $gallery = Gallery::factory()->create();
        Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);
        $this->logDownload([
            'user_id' => User::factory()->create()->id, 'gallery_id' => $gallery->id,
            'item_type' => 'single_image', 'resolution_tier' => 'web', 'photo_count' => 1,
            'created_at' => '2026-06-15 12:00:00',
        ]);

        $this->service->calculatePoolShares($pool->fresh());

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->sole();
        $this->assertSame('6.0000', $stmt->total_shares_earned); // 5 + 1
        $this->assertSame(13000, $stmt->pool_earnings_cents); // 3000 + (1*10000*100%)
    }

    // ============================================================
    // calculatePowerUserDelta()
    // ============================================================

    public function test_calculate_power_user_delta_uses_db_stripe_fee_when_present(): void
    {
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);
        // price 1000, stripe_fee 100 → net 900 → 50% = 450 cents.
        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => 100,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web']],
            ],
            'total_net' => 1000, 'total_gross' => 1200, 'tax_rate' => 20.00,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->first();
        $this->assertSame(450, $stmt->delta_surcharge_earnings_cents);
    }

    public function test_calculate_power_user_delta_falls_back_to_percent_when_fee_null(): void
    {
        // REVIEW: config('services.stripe.fee_percent') existiert nicht → Default 0.04.
        // price 1000, fee = round(1000*0.04) = 40 → net 960 → 50% = 480 cents.
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);
        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => null,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web']],
            ],
            'total_net' => 1000, 'total_gross' => 1200, 'tax_rate' => 20.00,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->first();
        $this->assertSame(480, $stmt->delta_surcharge_earnings_cents);
    }

    public function test_calculate_power_user_delta_skips_quote_requests(): void
    {
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);
        $order = Order::factory()->paid()->quoteRequest()->create([
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web']],
            ],
            'total_net' => 0, 'total_gross' => 0, 'tax_rate' => 20.00,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $this->assertSame(0, PhotographerStatement::where('user_id', $photographer->id)->count());
    }

    public function test_calculate_power_user_delta_skips_non_paid_orders(): void
    {
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);
        $order = Order::factory()->create([ // default status 'pending'
            'status' => 'pending',
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web']],
            ],
            'total_net' => 1000, 'total_gross' => 1200, 'tax_rate' => 20.00,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $this->assertSame(0, PhotographerStatement::where('user_id', $photographer->id)->count());
    }

    public function test_calculate_power_user_delta_skips_order_without_snapshot(): void
    {
        $order = Order::factory()->paid()->create(['created_at' => '2026-06-10 12:00:00']);
        // Kein InvoiceSnapshot erstellt → Service continue.

        $this->service->calculatePowerUserDelta(6, 2026);

        $this->assertSame(0, PhotographerStatement::count());
    }

    public function test_calculate_power_user_delta_skips_items_without_photo_id(): void
    {
        $order = Order::factory()->paid()->create(['created_at' => '2026-06-10 12:00:00']);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [['price' => 1000, 'tier' => 'web']], // kein photoId
            ],
            'total_net' => 1000, 'total_gross' => 1200, 'tax_rate' => 20.00,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $this->assertSame(0, PhotographerStatement::count());
    }

    public function test_calculate_power_user_delta_skips_items_with_nonpositive_price(): void
    {
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);
        $order = Order::factory()->paid()->create(['created_at' => '2026-06-10 12:00:00']);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [['photoId' => $photo->id, 'price' => 0, 'tier' => 'web']],
            ],
            'total_net' => 0, 'total_gross' => 0, 'tax_rate' => 20.00,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $this->assertSame(0, PhotographerStatement::where('user_id', $photographer->id)->count());
    }

    public function test_calculate_power_user_delta_skips_photos_without_user_id(): void
    {
        $photo = Photo::factory()->create(['user_id' => null]);
        $order = Order::factory()->paid()->create(['created_at' => '2026-06-10 12:00:00']);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web']],
            ],
            'total_net' => 1000, 'total_gross' => 1200, 'tax_rate' => 20.00,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $this->assertSame(0, PhotographerStatement::count());
    }

    public function test_calculate_power_user_delta_no_paid_orders_creates_nothing(): void
    {
        $this->service->calculatePowerUserDelta(6, 2026);

        $this->assertSame(0, PhotographerStatement::count());
    }

    public function test_calculate_power_user_delta_accumulates_multiple_items(): void
    {
        // Zwei Items selbes Foto/Photographer → Deltas akkumulieren.
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);
        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => 0,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web'],
                    ['photoId' => $photo->id, 'price' => 2000, 'tier' => 'print'],
                ],
            ],
            'total_net' => 3000, 'total_gross' => 3600, 'tax_rate' => 20.00,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->sole();
        // (1000-0)*0.5 + (2000-0)*0.5 = 500 + 1000 = 1500 cents
        $this->assertSame(1500, $stmt->delta_surcharge_earnings_cents);
    }

    // ============================================================
    // finalizeStatements()
    // ============================================================

    public function test_finalize_statements_below_threshold_rolls_over(): void
    {
        // earned 4000 (< 5000) → status rollover.
        $photographer = User::factory()->create();
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 6, 'year' => 2026,
            'pool_earnings_cents' => 4000, 'delta_surcharge_earnings_cents' => 0,
        ]);

        $this->service->finalizeStatements(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->sole();
        $this->assertSame(4000, $stmt->earned_amount_cents);
        $this->assertSame(4000, $stmt->total_payable_cents);
        $this->assertSame(0, $stmt->rolled_over_amount_cents);
        $this->assertSame('rollover', $stmt->status);
    }

    public function test_finalize_statements_at_threshold_becomes_pending(): void
    {
        // total_payable == 5000 (>=) → pending.
        $photographer = User::factory()->create();
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 6, 'year' => 2026,
            'pool_earnings_cents' => 5000, 'delta_surcharge_earnings_cents' => 0,
        ]);

        $this->service->finalizeStatements(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->sole();
        $this->assertSame(5000, $stmt->total_payable_cents);
        $this->assertSame('pending', $stmt->status);
    }

    public function test_finalize_statements_above_threshold_becomes_pending(): void
    {
        $photographer = User::factory()->create();
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 6, 'year' => 2026,
            'pool_earnings_cents' => 3000, 'delta_surcharge_earnings_cents' => 3000,
        ]);

        $this->service->finalizeStatements(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->sole();
        $this->assertSame(6000, $stmt->earned_amount_cents);
        $this->assertSame('pending', $stmt->status);
    }

    public function test_finalize_statements_includes_rollover_from_previous_month(): void
    {
        // Vormonat (Mai) hat rollover-Statement mit total_payable 3000.
        $photographer = User::factory()->create();
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 5, 'year' => 2026,
            'status' => 'rollover', 'total_payable_cents' => 3000,
        ]);
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 6, 'year' => 2026,
            'pool_earnings_cents' => 3000, 'delta_surcharge_earnings_cents' => 0,
        ]);

        $this->service->finalizeStatements(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)
            ->where('month', 6)->sole();
        $this->assertSame(3000, $stmt->rolled_over_amount_cents);
        $this->assertSame(6000, $stmt->total_payable_cents); // 3000 + 3000 rollover
        $this->assertSame('pending', $stmt->status); // 6000 >= 5000
    }

    public function test_finalize_statements_ignores_previous_month_with_other_status(): void
    {
        // Vormonat-Statement hat status 'paid' (nicht 'rollover') → kein Rollover.
        $photographer = User::factory()->create();
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 5, 'year' => 2026,
            'status' => 'paid', 'total_payable_cents' => 99999,
        ]);
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 6, 'year' => 2026,
            'pool_earnings_cents' => 6000,
        ]);

        $this->service->finalizeStatements(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)
            ->where('month', 6)->sole();
        $this->assertSame(0, $stmt->rolled_over_amount_cents);
        $this->assertSame(6000, $stmt->total_payable_cents);
    }

    public function test_finalize_statements_no_previous_month_means_zero_rollover(): void
    {
        $photographer = User::factory()->create();
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 6, 'year' => 2026,
            'pool_earnings_cents' => 6000,
        ]);

        $this->service->finalizeStatements(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->sole();
        $this->assertSame(0, $stmt->rolled_over_amount_cents);
    }

    public function test_finalize_statements_handles_year_boundary(): void
    {
        // Januar 2026 → Vormonat Dezember 2025 (Carbon::subMonth kennt Jahreswechsel).
        $photographer = User::factory()->create();
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 12, 'year' => 2025,
            'status' => 'rollover', 'total_payable_cents' => 2000,
        ]);
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 1, 'year' => 2026,
            'pool_earnings_cents' => 4000,
        ]);

        $this->service->finalizeStatements(1, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)
            ->where('month', 1)->where('year', 2026)->sole();
        $this->assertSame(2000, $stmt->rolled_over_amount_cents);
        $this->assertSame(6000, $stmt->total_payable_cents);
        $this->assertSame('pending', $stmt->status);
    }

    public function test_finalize_statements_no_statements_is_noop(): void
    {
        // Keine Statements vorhanden → Methode läuft ohne Fehler durch.
        $this->service->finalizeStatements(6, 2026);

        $this->assertSame(0, PhotographerStatement::count());
    }

    public function test_finalize_statements_combines_pool_and_delta(): void
    {
        // earned = pool + delta; Schwelle via rollover überspringen.
        $photographer = User::factory()->create();
        PhotographerStatement::create([
            'user_id' => $photographer->id, 'month' => 6, 'year' => 2026,
            'pool_earnings_cents' => 2500, 'delta_surcharge_earnings_cents' => 1500,
        ]);

        $this->service->finalizeStatements(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->sole();
        $this->assertSame(4000, $stmt->earned_amount_cents); // 2500 + 1500
        $this->assertSame('rollover', $stmt->status); // 4000 < 5000
    }
}
