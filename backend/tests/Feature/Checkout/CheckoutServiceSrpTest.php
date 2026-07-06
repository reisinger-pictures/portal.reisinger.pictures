<?php

namespace Tests\Feature\Checkout;

use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CheckoutService;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use App\Enums\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutServiceSrpTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Set brand context to SRP so VolumeLicensingStrategy is used
        BrandRegistry::set(Brand::SRP);

        $this->service = new CheckoutService(new VolumeLicensingStrategy(new SettingResolver()));

        // Bankdaten-Settings für InvoiceMail-PDF-Build
        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'srp'], ['value' => 'SRP Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'srp'], ['value' => 'AT987654321']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'srp'], ['value' => 'SRPBIC']);
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(null);
        parent::tearDown();
    }

    private function makeRequest(array $items, array $billing = []): Request
    {
        $payload = array_merge([
            'items' => $items,
            'billing_name' => 'SRP Tester',
            'billing_company' => null,
            'billing_street' => 'Teststr. 1',
            'billing_zip' => '1010',
            'billing_city' => 'Wien',
        ], $billing);

        return Request::create('/', 'POST', $payload);
    }

    public function test_srp_checkout_stripe_path_with_volume_pricing(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photos = Photo::factory()->count(12)->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        $items = [];
        foreach ($photos as $photo) {
            $items[] = ['photoId' => $photo->id, 'tier' => 'srp'];
        }

        $clientMock = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $clientMock->expects($this->once())->method('request')->willReturnCallback(function ($method, $absUrl, $headers, $params, $hasFile) {
            return [json_encode(['id' => 'pi_srp_test', 'client_secret' => 'sec_srp_test']), 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($clientMock);

        try {
            $response = $this->service->processCheckout(
                $this->makeRequest($items),
                $user,
                'stripe'
            );

            $this->assertEquals(200, $response->status());
            $payload = $response->getData(true);
            $this->assertTrue($payload['requires_action']);
            $this->assertSame('sec_srp_test', $payload['client_secret']);

            $order = Order::first();
            $this->assertNotNull($order);
            $this->assertEquals('pending_payment', $order->status);
            $this->assertEquals(Brand::SRP, $order->brand);

            // 12 Bilder à 3000 (Tier 1) = 36000, abzgl. Mengenrabatt -6000 = 30000 Cents
            $this->assertSame(30000, $order->total_amount);

            $snapshot = InvoiceSnapshot::first();
            $this->assertNotNull($snapshot);
            $this->assertCount(13, $snapshot->customer_details['items']);

            // First 12 items are the individual SRP items at tier1 base price
            $regularItems = array_slice($snapshot->customer_details['items'], 0, 12);
            foreach ($regularItems as $lineItem) {
                $this->assertSame(3000, $lineItem['price']);
                $this->assertSame('srp', $lineItem['tier']);
                $this->assertSame('SRP Lizenz', $lineItem['useCaseName']);
                $this->assertSame([], $lineItem['modifierNames']);
            }

            // 13th item is the tier breakdown discount line
            $discountLine = $snapshot->customer_details['items'][12];
            $this->assertSame('discount_fixed', $discountLine['type']);
            $this->assertTrue(str_contains($discountLine['filename'], 'Mengenrabatt'));
        } finally {
            \Stripe\ApiRequestor::setHttpClient(null);
        }
    }

    public function test_srp_checkout_10_items_volume_pricing_tier2(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photos = Photo::factory()->count(10)->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        $items = [];
        foreach ($photos as $photo) {
            $items[] = ['photoId' => $photo->id, 'tier' => 'srp'];
        }

        $response = $this->service->processCheckout(
            $this->makeRequest($items),
            $user,
            'invoice'
        );

        $this->assertEquals(200, $response->status());
        $order = Order::first();

        // 10 Bilder à 2500 (Tier 2) = 25000 Cents
        $this->assertSame(25000, $order->total_amount);
    }

    public function test_srp_checkout_20_items_volume_pricing_tier3(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photos = Photo::factory()->count(20)->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        $items = [];
        foreach ($photos as $photo) {
            $items[] = ['photoId' => $photo->id, 'tier' => 'srp'];
        }

        $response = $this->service->processCheckout(
            $this->makeRequest($items),
            $user,
            'invoice'
        );

        $this->assertEquals(200, $response->status());
        $order = Order::first();

        // 20 Bilder à 2000 (Tier 3) = 40000 Cents
        $this->assertSame(40000, $order->total_amount);
    }

    public function test_srp_checkout_with_quote_items(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photos = Photo::factory()->count(3)->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        $items = [];
        foreach ($photos as $photo) {
            $items[] = ['photoId' => $photo->id, 'isQuote' => true, 'tier' => 'srp'];
        }

        $response = $this->service->processCheckout(
            $this->makeRequest($items, ['quote_message' => 'SRP Quote']),
            $user,
            'stripe'
        );

        $this->assertEquals(200, $response->status());
        $order = Order::first();

        $this->assertTrue($order->is_quote_request);
        $this->assertSame(0, $order->total_amount);
        $this->assertEquals('pending', $order->status);
    }

    public function test_srp_checkout_mixed_quotes_and_paid_items(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photos = Photo::factory()->count(10)->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        $items = [];
        // 8 paid items + 2 quote items
        for ($i = 0; $i < 8; $i++) {
            $items[] = ['photoId' => $photos[$i]->id, 'tier' => 'srp'];
        }
        for ($i = 8; $i < 10; $i++) {
            $items[] = ['photoId' => $photos[$i]->id, 'isQuote' => true, 'tier' => 'srp'];
        }

        $response = $this->service->processCheckout(
            $this->makeRequest($items),
            $user,
            'invoice'
        );

        $this->assertEquals(200, $response->status());
        $order = Order::first();

        // 8 paid items → Tier 1 (3000 each) → 24000 Cents
        // Quotes are NOT counted toward volume tier
        $this->assertSame(24000, $order->total_amount);
    }

    public function test_srp_checkout_empty_cart_returns_400(): void
    {
        $user = User::factory()->create();

        $response = $this->service->processCheckout(
            $this->makeRequest([]),
            $user,
            'stripe'
        );

        $this->assertEquals(400, $response->status());
        $this->assertSame(0, Order::count());
    }
}
