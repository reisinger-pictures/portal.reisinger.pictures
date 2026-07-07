<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Photo;
use App\Models\Gallery;
use App\Models\Order;
use App\Services\OfferTokenService;
use App\Services\CheckoutService;
use App\Pricing\ScopeLicensingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class QuoteLinkCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CheckoutService(new ScopeLicensingStrategy());

        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'BIC']);
    }

    private function makeRequest(array $items, array $extra = []): Request
    {
        $payload = array_merge([
            'items' => $items,
            'billing_name' => 'Tester',
            'billing_company' => null,
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Wien',
        ], $extra);

        return Request::create('/', 'POST', $payload);
    }

    public function test_checkout_with_quote_token_uses_token_price(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photos = Photo::factory()->count(3)->create(['gallery_id' => $gallery->id]);
        $photoIds = $photos->pluck('id')->toArray();

        $offerTokenService = app(OfferTokenService::class);
        $token = $offerTokenService->issue([
            'photos' => $photoIds,
            'price' => 5000,
        ], now()->addDays(7));

        $user = User::factory()->create();

        Mail::fake();

        $response = $this->service->processCheckout(
            $this->makeRequest(
                array_map(fn($pid) => [
                    'photoId' => $pid,
                    'isQuote' => false,
                    'tier' => 'original',
                    'price' => 99999,
                ], $photoIds),
                ['quote_token' => $token]
            ),
            $user,
            'invoice'
        );

        $this->assertEquals(200, $response->status());

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame(5000, $order->total_amount);
    }

    public function test_expired_quote_token_returns_422(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $offerTokenService = app(OfferTokenService::class);
        $token = $offerTokenService->issue([
            'photos' => [$photo->id],
            'price' => 1000,
        ], now()->subDay());

        $user = User::factory()->create();

        $response = $this->service->processCheckout(
            $this->makeRequest(
                [['photoId' => $photo->id, 'isQuote' => false, 'tier' => 'original', 'price' => 999]],
                ['quote_token' => $token]
            ),
            $user,
            'invoice'
        );

        $this->assertEquals(422, $response->status());
        $this->assertSame('Angebot ist abgelaufen oder ungültig.', $response->getData(true)['error']);
    }

    public function test_invalid_quote_token_returns_422(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();

        $response = $this->service->processCheckout(
            $this->makeRequest(
                [['photoId' => $photo->id, 'isQuote' => false, 'tier' => 'original', 'price' => 999]],
                ['quote_token' => 'invalid-token']
            ),
            $user,
            'invoice'
        );

        $this->assertEquals(422, $response->status());
        $this->assertSame('Angebot ist abgelaufen oder ungültig.', $response->getData(true)['error']);
    }

    public function test_quote_token_custom_conditions_in_invoice(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $offerTokenService = app(OfferTokenService::class);
        $token = $offerTokenService->issue([
            'photos' => [$photo->id],
            'price' => 10000,
            'rights_text' => 'Nutzung für Marketingkampagne in Österreich, Laufzeit 2 Jahre',
        ], now()->addDays(7));

        $user = User::factory()->create();

        Mail::fake();

        $response = $this->service->processCheckout(
            $this->makeRequest(
                [['photoId' => $photo->id, 'isQuote' => false, 'tier' => 'original', 'price' => 999]],
                ['quote_token' => $token]
            ),
            $user,
            'invoice'
        );

        $this->assertEquals(200, $response->status());

        $order = Order::first();
        $this->assertNotNull($order);

        $snapshot = $order->invoiceSnapshot;
        $this->assertNotNull($snapshot);

        $details = $snapshot->customer_details;
        $this->assertArrayHasKey('custom_conditions', $details);
        $this->assertSame('Nutzung für Marketingkampagne in Österreich, Laufzeit 2 Jahre', $details['custom_conditions']);
    }
}
