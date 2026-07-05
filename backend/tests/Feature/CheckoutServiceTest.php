<?php

namespace Tests\Feature;

use App\Contracts\PricingStrategy;
use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Tenant;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Services\CheckoutService;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MailpitAssertions;
use Tests\TestCase;

class CheckoutServiceTest extends TestCase
{
    use RefreshDatabase, MailpitAssertions;

    private CheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CheckoutService(new ScopeLicensingStrategy());

        $this->clearMailpit();

        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'BIC']);
    }

    protected function tearDown(): void
    {
        // Prozessglobalen Stripe-Client zurücksetzen, damit andere Tests nicht verunreinigt werden
        try {
            \Stripe\ApiRequestor::setHttpClient(null);
        } catch (\Throwable $e) {
            // ignore
        }
        BrandRegistry::reset();
        parent::tearDown();
    }

    /**
     * Erzeugt einen Request mit Items + Billing (analog CheckoutRequest-Shape).
     * Illuminate\Http\Request::__get() mapt auf all() → Items/Billing als Properties verfügbar.
     */
    private function makeRequest(array $items, array $billing = [], ?string $quoteMessage = null): Request
    {
        $payload = array_merge([
            'items' => $items,
            'billing_name' => 'Tester',
            'billing_company' => null,
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Wien',
            'quote_message' => $quoteMessage,
        ], $billing);

        return Request::create('/', 'POST', $payload);
    }

    // ------------------------------------------------------------------
    // 1. Stripe-Erfolgspfad
    // ------------------------------------------------------------------
    public function test_process_checkout_stripe_path_returns_client_secret_and_pending_payment(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();

        $clientMock = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $clientMock->expects($this->once())->method('request')->willReturnCallback(function ($method, $absUrl, $headers, $params, $hasFile) {
            return [json_encode(['id' => 'pi_test_123', 'client_secret' => 'sec_test_123']), 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($clientMock);

        try {
            $response = $this->service->processCheckout(
                $this->makeRequest([['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web']]),
                $user,
                'stripe'
            );

            $this->assertEquals(200, $response->status());
            $payload = $response->getData(true);
            $this->assertTrue($payload['requires_action']);
            $this->assertSame('sec_test_123', $payload['client_secret']);
            $this->assertSame(Order::first()->id, $payload['order_id']);

            $order = Order::first();
            $this->assertNotNull($order);
            $this->assertEquals('pending_payment', $order->status);
            $this->assertSame('pi_test_123', $order->stripe_payment_intent_id);
            $this->assertNotNull($order->ip_address);
        } finally {
            \Stripe\ApiRequestor::setHttpClient(null);
        }
    }

    // ------------------------------------------------------------------
    // 2. Quote: total 0 erlaubt, status pending, KEINE Mail
    // ------------------------------------------------------------------
    public function test_process_checkout_quote_request_allows_total_zero_and_sends_no_mail(): void
    {
        [$user, $photo] = $this->setupAccessibleItem();

        $response = $this->service->processCheckout(
            $this->makeRequest(
                [['photoId' => $photo->id, 'isQuote' => true, 'tier' => 'web']],
                [],
                'Anfrage für Verlag'
            ),
            $user,
            'stripe'
        );

        $this->assertEquals(200, $response->status());
        $payload = $response->getData(true);
        $this->assertTrue($payload['success']);
        $this->assertNotEmpty($payload['invoice_number']);

        $order = Order::first();
        $this->assertTrue($order->is_quote_request);
        $this->assertEquals('pending', $order->status);
        $this->assertSame(0, $order->total_amount);

        // Quote -> KEINE Mail
        $this->assertMailpitEmpty();
    }

    // ------------------------------------------------------------------
    // 3. Lieferschein (Tenant invoice_frequency != immediate)
    // ------------------------------------------------------------------
    public function test_process_checkout_lieferschein_when_tenant_invoice_frequency_is_not_immediate(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();
        $tenant = Tenant::create(['name' => 'Firma AG', 'invoice_frequency' => 'monthly']);
        $user->tenant_id = $tenant->id;
        $user->save();

        $response = $this->service->processCheckout(
            $this->makeRequest([['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web']]),
            $user,
            'stripe'
        );

        $this->assertEquals(200, $response->status());
        $order = Order::first();
        $this->assertEquals('delivery_note', $order->status);

        $snapshot = InvoiceSnapshot::first();
        $this->assertNotNull($snapshot);
        $this->assertStringStartsWith('L-', $snapshot->invoice_number);

        $this->assertMailpitSentTo($user->email);
    }

    // ------------------------------------------------------------------
    // 4. paymentMethod 'invoice' (ohne Tenant) -> status invoice_created + Mail
    // ------------------------------------------------------------------
    public function test_process_checkout_invoice_payment_method_creates_invoice_and_sends_mail(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();

        $response = $this->service->processCheckout(
            $this->makeRequest([['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web']]),
            $user,
            'invoice'
        );

        $this->assertEquals(200, $response->status());
        $order = Order::first();
        $this->assertEquals('invoice_created', $order->status);

        $snapshot = InvoiceSnapshot::first();
        $this->assertStringStartsWith('P-', $snapshot->invoice_number);

        $this->assertMailpitSentTo($user->email);
    }

    // ------------------------------------------------------------------
    // 5. IDOR: private Galerie, User ohne Zugriff -> abort(403) + Rollback
    // ------------------------------------------------------------------
    public function test_process_checkout_idor_private_gallery_without_access_returns_403_json_and_rolls_back(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create(['name' => 'Web', 'base_price' => 7500, 'flatrate_tier' => 'web']);
        $user = User::factory()->create(); // KEIN galleries()->attach

        $response = $this->service->processCheckout(
            $this->makeRequest([['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web']]),
            $user,
            'stripe'
        );
        $this->assertEquals(403, $response->status());
        $this->assertSame('Zugriff verweigert', $response->getData(true)['error']);

        // Transaktions-Rollback
        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, InvoiceSnapshot::count());
    }

    // ------------------------------------------------------------------
    // 6. Kommerziell × editorial -> JsonResponse 403 (KEIN abort!)
    // ------------------------------------------------------------------
    public function test_process_checkout_commercial_use_case_on_editorial_photo_returns_403_json(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true, 'is_editorial_only' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create([
            'name' => 'Werbung Kampagne', 'base_price' => 20000, 'flatrate_tier' => 'web', 'is_commercial' => true,
        ]);
        $user = User::factory()->create();

        $response = $this->service->processCheckout(
            $this->makeRequest([['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web']]),
            $user,
            'stripe'
        );

        $this->assertEquals(403, $response->status());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, InvoiceSnapshot::count());
        $this->assertMailpitEmpty();
    }

    // ------------------------------------------------------------------
    // 7. Nicht-Quote total 0 (flatrate gedeckt) -> JsonResponse 400
    // ------------------------------------------------------------------
    public function test_process_checkout_non_quote_zero_total_returns_400(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        // original-Tier durch original-Flatrate gedeckt -> 0 Cents
        $useCase = LicenseUseCase::create(['name' => 'Original', 'base_price' => 50000, 'flatrate_tier' => 'original']);
        $user = User::factory()->create(['flatrate_level' => 'original']);

        $response = $this->service->processCheckout(
            $this->makeRequest([['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'original']]),
            $user,
            'stripe'
        );

        $this->assertEquals(400, $response->status());
        $this->assertSame('Warenkorb hat keinen Wert.', $response->getData(true)['error']);
        $this->assertSame(0, Order::count());
    }

    // ------------------------------------------------------------------
    // 8. Foto 404 -> ModelNotFoundException + Rollback
    // ------------------------------------------------------------------
    public function test_process_checkout_missing_photo_throws_model_not_found_and_rolls_back(): void
    {
        $user = User::factory()->create();
        $useCase = LicenseUseCase::create(['name' => 'Web', 'base_price' => 7500, 'flatrate_tier' => 'web']);

        try {
            $this->service->processCheckout(
                $this->makeRequest([['photoId' => 'nonexistent-id', 'useCaseId' => $useCase->id, 'tier' => 'web']]),
                $user,
                'stripe'
            );
            $this->fail('Expected ModelNotFoundException was not thrown.');
        } catch (ModelNotFoundException $e) {
            $this->assertStringContainsString('No query results for model', $e->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, InvoiceSnapshot::count());
    }

    // ------------------------------------------------------------------
    // 9. invoice_frequency 'immediate' + Tenant -> Stripe-Pfad (KEIN Lieferschein)
    // ------------------------------------------------------------------
    public function test_process_checkout_tenant_with_immediate_invoice_frequency_does_not_create_lieferschein(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();
        $tenant = Tenant::create(['name' => 'Immediate Co', 'invoice_frequency' => 'immediate']);
        $user->tenant_id = $tenant->id;
        $user->save();

        $clientMock = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $clientMock->expects($this->once())->method('request')->willReturnCallback(function () {
            return [json_encode(['id' => 'pi_test_imm', 'client_secret' => 'sec_test_imm']), 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($clientMock);

        try {
            $response = $this->service->processCheckout(
                $this->makeRequest([['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web']]),
                $user,
                'stripe'
            );

            $this->assertEquals(200, $response->status());
            $order = Order::first();
            // immediate -> kein Lieferschein -> Stripe-Pfad -> pending_payment
            $this->assertEquals('pending_payment', $order->status);
            $this->assertSame('pi_test_imm', $order->stripe_payment_intent_id);
        } finally {
            \Stripe\ApiRequestor::setHttpClient(null);
        }
    }

    // ------------------------------------------------------------------
    // 10. Leere items -> total 0, nicht-quote -> 400
    // ------------------------------------------------------------------
    public function test_process_checkout_empty_items_returns_400(): void
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

    // ------------------------------------------------------------------
    // 11. Transaktions-Rollback: Stripe schlägt fehl -> kein Order/Snapshot persistiert
    // ------------------------------------------------------------------
    public function test_process_checkout_stripe_failure_rolls_back_order_and_snapshot(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();

        // Stripe-Client wirft Exception -> DB::transaction muss rollbacken
        $clientMock = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $clientMock->expects($this->once())->method('request')->willThrowException(new \RuntimeException('Stripe down'));
        \Stripe\ApiRequestor::setHttpClient($clientMock);

        try {
            $this->service->processCheckout(
                $this->makeRequest([['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web']]),
                $user,
                'stripe'
            );
            $this->fail('Expected RuntimeException from Stripe client to trigger rollback.');
        } catch (\RuntimeException $e) {
            // erwartet — Exception fliegt aus der Transaktion → Rollback
        } finally {
            \Stripe\ApiRequestor::setHttpClient(null);
        }

        // Alles zurückgerollt
        $this->assertSame(0, Order::count());
        $this->assertSame(0, InvoiceSnapshot::count());
    }

    // ------------------------------------------------------------------
    // 12. notes / useCaseName / modifierNames werden ins Snapshot durchgereicht
    // ------------------------------------------------------------------
    public function test_process_checkout_passes_item_notes_and_pricing_into_snapshot_items(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();

        $modifier = LicenseModifier::create([
            'name' => 'Exklusiv',
            'percent_surcharge' => 100,
            'is_included_in_flatrate' => false,
        ]);

        $response = $this->service->processCheckout(
            $this->makeRequest([
                [
                    'photoId' => $photo->id,
                    'useCaseId' => $useCase->id,
                    'modifierIds' => [$modifier->id],
                    'tier' => 'web',
                    'notes' => 'Sondernutzung Konzert',
                ],
            ]),
            $user,
            'invoice'
        );

        $this->assertEquals(200, $response->status());

        $snapshot = InvoiceSnapshot::first();
        $items = $snapshot->customer_details['items'];
        $this->assertCount(1, $items);
        $this->assertSame($photo->id, $items[0]['photoId']);
        $this->assertSame('Sondernutzung Konzert', $items[0]['notes']);
        $this->assertSame('Web', $items[0]['useCaseName']);
        $this->assertContains('Exklusiv', $items[0]['modifierNames']);
        // base 7500 + 100% surcharge = 15000 Cents
        $this->assertSame(15000, $items[0]['price']);
        // Snapshot-Summe = total
        $this->assertSame(15000, $snapshot->total_net);
    }

    // ------------------------------------------------------------------
    // 13. customer_details enthalten Billing-Daten + Quote-Message
    // ------------------------------------------------------------------
    public function test_process_checkout_quote_request_stores_quote_message_in_snapshot(): void
    {
        [$user, $photo] = $this->setupAccessibleItem();

        $response = $this->service->processCheckout(
            $this->makeRequest(
                [['photoId' => $photo->id, 'isQuote' => true, 'tier' => 'web']],
                ['billing_name' => 'Anna Musterfrau', 'billing_company' => 'Muster GmbH'],
                'Bitte um Angebot für Print-Ausgabe'
            ),
            $user,
            'stripe'
        );

        $this->assertEquals(200, $response->status());

        $snapshot = InvoiceSnapshot::first();
        $this->assertSame('Anna Musterfrau', $snapshot->customer_details['name']);
        $this->assertSame('Muster GmbH', $snapshot->customer_details['company']);
        $this->assertSame('Bitte um Angebot für Print-Ausgabe', $snapshot->customer_details['quote_message']);
        $this->assertSame($user->email, $snapshot->customer_details['email']);
    }

    // ------------------------------------------------------------------
    // 14. Bug-Fix: Billing wird nach Checkout am User persistiert
    //     (vorher No-Op, da billing_* nicht in User::$fillable)
    // ------------------------------------------------------------------
    public function test_process_checkout_persists_billing_data_on_user(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();

        $response = $this->service->processCheckout(
            $this->makeRequest(
                [['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web']],
                [
                    'billing_name' => 'Zahlender Kunde',
                    'billing_company' => 'Kunde GmbH',
                    'billing_street' => 'Zahlweg 5',
                    'billing_zip' => '8010',
                    'billing_city' => 'Graz',
                ]
            ),
            $user,
            'invoice'
        );

        $this->assertEquals(200, $response->status());

        // Bug-Fix: billing_* ist nun in User::$fillable → update() persists (vorher No-Op)
        $user->refresh();
        $this->assertSame('Zahlender Kunde', $user->billing_name);
        $this->assertSame('Kunde GmbH', $user->billing_company);
        $this->assertSame('Zahlweg 5', $user->billing_street);
        $this->assertSame('8010', $user->billing_zip);
        $this->assertSame('Graz', $user->billing_city);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    /**
     * @return array{0: User, 1: Photo, 2: LicenseUseCase}
     */
    private function setupPaidItem(): array
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create(['name' => 'Web', 'base_price' => 7500, 'flatrate_tier' => 'web']);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        return [$user, $photo, $useCase];
    }

    /**
     * @return array{0: User, 1: Photo}
     */
    private function setupAccessibleItem(): array
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        return [$user, $photo];
    }

    // ------------------------------------------------------------------
    // 15. Checkout mit percentage + max_items Coupon
    // ------------------------------------------------------------------
    public function test_process_checkout_with_percentage_max_items_coupon(): void
    {
        BrandRegistry::set(Brand::B2B);

        try {
            $coupon = Coupon::factory()->create([
                'code' => 'PCT50_2',
                'type' => 'percentage',
                'value' => 50,
                'max_items' => 2,
                'brand' => Brand::B2B->value,
                'active' => true,
            ]);

            $gallery = Gallery::factory()->create(['is_public' => true]);
            $photo1 = Photo::factory()->create(['gallery_id' => $gallery->id]);
            $photo2 = Photo::factory()->create(['gallery_id' => $gallery->id]);
            $photo3 = Photo::factory()->create(['gallery_id' => $gallery->id]);
            $user = User::factory()->create();

            $strategy = $this->createMock(PricingStrategy::class);
            $strategy->method('calculateCart')->willReturn([
                'items' => [
                    ['itemId' => $photo1->id, 'priceCents' => 3000, 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
                    ['itemId' => $photo2->id, 'priceCents' => 2000, 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
                    ['itemId' => $photo3->id, 'priceCents' => 1000, 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
                ],
                'totalCents' => 4500,
                'discountCents' => 1500,
                'couponId' => $coupon->id,
                'couponType' => 'percentage',
            ]);

            $this->service = new CheckoutService($strategy);

            $response = $this->service->processCheckout(
                $this->makeRequest(
                    [
                        ['photoId' => $photo1->id, 'tier' => 'srp'],
                        ['photoId' => $photo2->id, 'tier' => 'srp'],
                        ['photoId' => $photo3->id, 'tier' => 'srp'],
                    ],
                    ['coupon_code' => 'PCT50_2']
                ),
                $user,
                'invoice'
            );

            $this->assertEquals(200, $response->status());

            $order = Order::first();
            $this->assertNotNull($order);
            $this->assertEquals($coupon->id, $order->coupon_id);
            $this->assertSame(4500, $order->total_amount);

            $coupon->refresh();
            $this->assertSame(1, $coupon->used_count);
        } finally {
            BrandRegistry::reset();
        }
    }

    // ------------------------------------------------------------------
    // 16. Coupon-Rabatt macht Warenkorb wertlos -> 400
    // ------------------------------------------------------------------
    public function test_process_checkout_with_coupon_that_makes_total_zero(): void
    {
        BrandRegistry::set(Brand::B2B);

        try {
            $coupon = Coupon::factory()->create([
                'code' => 'ZERO60',
                'type' => 'fixed',
                'value' => 60,
                'brand' => Brand::B2B->value,
                'active' => true,
            ]);

            $gallery = Gallery::factory()->create(['is_public' => true]);
            $photo1 = Photo::factory()->create(['gallery_id' => $gallery->id]);
            $photo2 = Photo::factory()->create(['gallery_id' => $gallery->id]);
            $user = User::factory()->create();

            $strategy = $this->createMock(PricingStrategy::class);
            $strategy->method('calculateCart')->willReturn([
                'items' => [
                    ['itemId' => $photo1->id, 'priceCents' => 0, 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
                    ['itemId' => $photo2->id, 'priceCents' => 0, 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
                ],
                'totalCents' => 0,
                'discountCents' => 6000,
                'couponId' => $coupon->id,
                'couponType' => 'fixed',
            ]);

            $this->service = new CheckoutService($strategy);

            $response = $this->service->processCheckout(
                $this->makeRequest(
                    [
                        ['photoId' => $photo1->id, 'tier' => 'srp'],
                        ['photoId' => $photo2->id, 'tier' => 'srp'],
                    ],
                    ['coupon_code' => 'ZERO60']
                ),
                $user,
                'invoice'
            );

            $this->assertEquals(400, $response->status());
            $this->assertSame('Warenkorb hat keinen Wert.', $response->getData(true)['error']);
            $this->assertSame(0, Order::count());
        } finally {
            BrandRegistry::reset();
        }
    }

}
