<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\PayoutPool;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Services\CheckoutService;
use App\Services\OfferTokenService;
use App\Services\PayoutCalculationService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\StreamedResponseException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderDownloadFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutService $checkoutService;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
        BrandRegistry::clearCache();
        BrandRegistry::set(Brand::B2B);
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => Brand::B2B->value], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => Brand::B2B->value], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => Brand::B2B->value], ['value' => 'BIC']);

        $this->checkoutService = new CheckoutService(new ScopeLicensingStrategy);
    }

    public function test_private_gallery_access_is_rechecked_after_checkout(): void
    {
        $buyer = $this->buyer();
        [$gallery, $photo] = $this->deliveryPhoto($buyer, false);
        $order = $this->paidOrder($buyer, [$photo]);

        $buyer->galleries()->detach($gallery->id);

        $this->actingAs($buyer, 'api')
            ->getJson("/api/orders/{$order->id}/download-zip")
            ->assertForbidden();

        $this->assertDatabaseCount('download_logs', 0);
    }

    public function test_gallery_and_photo_hidden_state_are_rechecked_after_checkout(): void
    {
        $buyer = $this->buyer();
        [$gallery, $photo] = $this->deliveryPhoto($buyer, false);
        $order = $this->paidOrder($buyer, [$photo]);

        $gallery->update(['is_hidden' => true]);
        $this->actingAs($buyer, 'api')
            ->getJson("/api/orders/{$order->id}/download-zip")
            ->assertForbidden();
        $this->assertDatabaseCount('download_logs', 0);

        $gallery->update(['is_hidden' => false]);
        $photo->update(['is_hidden' => true]);
        $this->actingAs($buyer, 'api')
            ->getJson("/api/orders/{$order->id}/download-zip")
            ->assertForbidden();
        $this->assertDatabaseCount('download_logs', 0);
    }

    public function test_gallery_expiry_is_rechecked_after_checkout(): void
    {
        $buyer = $this->buyer();
        [$gallery, $photo] = $this->deliveryPhoto($buyer, false);
        $order = $this->paidOrder($buyer, [$photo]);

        $gallery->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($buyer, 'api')
            ->getJson("/api/orders/{$order->id}/download-zip")
            ->assertForbidden();

        $this->assertDatabaseCount('download_logs', 0);
    }

    public function test_legacy_null_brand_order_and_snapshot_fail_closed(): void
    {
        $buyer = $this->buyer();
        [, $photo] = $this->deliveryPhoto($buyer, false);
        $order = Order::factory()->paid()->create([
            'user_id' => $buyer->id,
            'brand' => null,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-NULL-BRAND-'.strtoupper(Str::random(8)),
            'brand' => null,
            'customer_details' => [
                'items' => [[
                    'photoId' => $photo->id,
                    'tier' => 'original',
                    'price' => 1000,
                ]],
            ],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => null,
        ]);

        $this->actingAs($buyer, 'api')
            ->getJson("/api/orders/{$order->id}/download-zip")
            ->assertNotFound();

        $this->assertDatabaseCount('download_logs', 0);
    }

    public function test_late_hidden_order_zip_removes_log_and_emits_no_bytes(): void
    {
        $buyer = $this->buyer();
        [, $photo] = $this->deliveryPhoto($buyer, false);
        $order = $this->paidOrder($buyer, [$photo]);

        $response = $this->actingAs($buyer, 'api')
            ->get("/api/orders/{$order->id}/download-zip")
            ->assertOk();
        $this->assertDatabaseCount('download_logs', 1);

        $photo->update(['is_hidden' => true]);
        $this->expectException(StreamedResponseException::class);

        ob_start();
        try {
            $response->sendContent();
        } finally {
            $this->assertSame('', (string) ob_get_clean());
            $this->assertDatabaseCount('download_logs', 0);
        }
    }

    public function test_mixed_gallery_order_zip_persists_actual_item_ids(): void
    {
        $buyer = $this->buyer();
        $photographerA = User::factory()->create(['brand' => Brand::B2B]);
        $photographerB = User::factory()->create(['brand' => Brand::B2B]);
        [$galleryA, $photoA] = $this->deliveryPhoto($buyer, false, $photographerA);
        [$galleryB, $photoB] = $this->deliveryPhoto($buyer, false, $photographerB);
        $order = $this->paidOrder($buyer, [$photoA, $photoB]);

        $response = $this->actingAs($buyer, 'api')
            ->get("/api/orders/{$order->id}/download-zip")
            ->assertOk();

        $log = DownloadLog::sole();
        $this->assertSame($order->id, $log->order_id);
        $this->assertNull($log->gallery_id);
        $this->assertSame(2, $log->photo_count);
        $this->assertEqualsCanonicalizing(
            [$photoA->id, $photoB->id],
            $log->payload['photo_ids'],
        );
        $this->assertEqualsCanonicalizing(
            [$galleryA->id, $galleryB->id],
            $log->payload['gallery_ids'],
        );

        ob_start();
        $response->sendContent();
        $bytes = (string) ob_get_clean();
        $this->assertStringStartsWith('PK', $bytes);
    }

    public function test_quote_checkout_order_can_be_fulfilled_and_is_audited(): void
    {
        $buyer = $this->buyer();
        [, $photo] = $this->deliveryPhoto($buyer, false);
        $token = app(OfferTokenService::class)->issueQuote(
            [$photo->id],
            5000,
            brand: Brand::B2B->value,
            expiresAt: now()->addDays(7),
        );

        $checkoutResponse = $this->checkoutService->processCheckout(
            Request::create('/api/orders/checkout', 'POST', [
                'items' => [[
                    'photoId' => $photo->id,
                    'isQuote' => false,
                    'tier' => 'original',
                ]],
                'quote_token' => $token,
                'billing_name' => 'Test Buyer',
                'billing_street' => 'Test Street 1',
                'billing_zip' => '1010',
                'billing_city' => 'Vienna',
                'withdrawal_waived' => true,
            ]),
            $buyer,
            'invoice',
        );

        $this->assertSame(200, $checkoutResponse->status());
        $order = Order::query()->where('user_id', $buyer->id)->sole();

        $this->actingAs($buyer, 'api')
            ->get("/api/orders/{$order->id}/download-zip")
            ->assertOk();

        $log = DownloadLog::sole();
        $this->assertSame($order->id, $log->order_id);
        $this->assertSame([$photo->id], $log->payload['photo_ids']);
        $this->assertSame('original', $log->resolution_tier);
    }

    public function test_single_and_gallery_zip_logs_persist_real_photo_ids(): void
    {
        [, $photo] = $this->deliveryPhoto(null, true);

        $this->getJson("/api/photos/{$photo->id}/download?tier=original")
            ->assertOk();
        $singleLog = DownloadLog::sole();
        $this->assertSame($photo->id, $singleLog->payload['photo_id']);

        $this->getJson("/api/galleries/{$photo->gallery_id}/download-zip?tier=original")
            ->assertOk();
        $zipLog = DownloadLog::where('item_type', 'full_zip')->sole();
        $this->assertSame([$photo->id], $zipLog->payload['photo_ids']);
        $this->assertSame([$photo->gallery_id], $zipLog->payload['gallery_ids']);
    }

    public function test_actual_order_payload_drives_multi_photographer_payout_attribution(): void
    {
        $buyer = $this->buyer();
        $photographerA = User::factory()->create(['brand' => Brand::B2B]);
        $photographerB = User::factory()->create(['brand' => Brand::B2B]);
        [, $photoA] = $this->deliveryPhoto($buyer, false, $photographerA);
        [, $photoB] = $this->deliveryPhoto($buyer, false, $photographerB);
        $order = $this->paidOrder($buyer, [$photoA, $photoB]);

        $this->actingAs($buyer, 'api')
            ->get("/api/orders/{$order->id}/download-zip")
            ->assertOk();

        $pool = PayoutPool::factory()
            ->forMonth((int) now()->month, (int) now()->year)
            ->withNetPool(800)
            ->create(['photographer_share_percent' => 100]);

        app(PayoutCalculationService::class)->calculatePoolShares($pool->fresh());

        $this->assertSame('8.0000', $pool->fresh()->total_shares);
        $this->assertSame(2, $pool->fresh()->total_unique_downloads);
        $this->assertSame('4.0000', PhotographerStatement::where('user_id', $photographerA->id)->sole()->total_shares_earned);
        $this->assertSame('4.0000', PhotographerStatement::where('user_id', $photographerB->id)->sole()->total_shares_earned);
        $this->assertSame(400, PhotographerStatement::where('user_id', $photographerA->id)->sole()->pool_earnings_cents);
        $this->assertSame(400, PhotographerStatement::where('user_id', $photographerB->id)->sole()->pool_earnings_cents);
    }

    private function buyer(): User
    {
        return User::factory()->create(['brand' => Brand::B2B]);
    }

    /**
     * @return array{0: Gallery, 1: Photo}
     */
    private function deliveryPhoto(?User $buyer, bool $public, ?User $photographer = null): array
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'delivery',
            'is_public' => $public,
            'is_free_download' => $public,
            'is_hidden' => false,
        ]);
        if ($buyer) {
            $buyer->galleries()->attach($gallery);
        }

        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'user_id' => $photographer?->id,
            'is_hidden' => false,
        ]);
        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            (string) file_get_contents(base_path('tests/Fixtures/sample.jpg')),
        );

        return [$gallery, $photo];
    }

    /**
     * @param  array<int, Photo>  $photos
     */
    private function paidOrder(User $buyer, array $photos): Order
    {
        $items = [];
        foreach ($photos as $photo) {
            $items[] = [
                'photoId' => $photo->id,
                'tier' => 'original',
                'price' => 1000,
            ];
        }

        $order = Order::factory()->paid()->create([
            'user_id' => $buyer->id,
            'brand' => Brand::B2B,
            'is_quote_request' => false,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-'.strtoupper(Str::random(12)),
            'brand' => Brand::B2B,
            'customer_details' => ['items' => $items],
            'total_net' => count($items) * 1000,
            'total_gross' => count($items) * 1000,
            'tax_rate' => null,
        ]);

        return $order->fresh();
    }
}
