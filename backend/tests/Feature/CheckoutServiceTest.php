<?php

namespace Tests\Feature;

use App\Contracts\PricingStrategy;
use App\Enums\Brand;
use App\Mail\InvoiceMail;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Org;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Services\CheckoutService;
use App\Services\PayoutCalculationService;
use App\Services\PurchaseService;
use App\Services\StripePaymentService;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\MailpitAssertions;
use Tests\TestCase;

#[Group('mailpit')]
class CheckoutServiceTest extends TestCase
{
    use MailpitAssertions, RefreshDatabase;

    private CheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CheckoutService(new ScopeLicensingStrategy);

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'BIC']);
    }

    protected function tearDown(): void
    {
        // Prozessglobalen Stripe-Client zurücksetzen, damit andere Tests nicht verunreinigt werden
        try {
            ApiRequestor::setHttpClient(null);
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
            'withdrawal_waived' => true,
        ], $billing);

        return Request::create('/', 'POST', $payload);
    }

    // ------------------------------------------------------------------
    // 1. Stripe-Erfolgspfad
    // ------------------------------------------------------------------
    public function test_process_checkout_stripe_path_returns_client_secret_and_pending_payment(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();

        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->once())->method('request')->willReturnCallback(function ($method, $absUrl, $headers, $params, $hasFile) {
            return [json_encode([
                'id' => 'pi_test_123',
                'status' => 'requires_payment_method',
                'client_secret' => 'sec_test_123',
                'amount' => (int) ($params['amount'] ?? 0),
                'currency' => $params['currency'] ?? 'eur',
                'created' => time(),
                'customer' => $params['customer'] ?? null,
                'metadata' => $params['metadata'] ?? [],
            ]), 200, []];
        });
        ApiRequestor::setHttpClient($clientMock);

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
            ApiRequestor::setHttpClient(null);
        }
    }

    // ------------------------------------------------------------------
    // 2. Quote: total 0 erlaubt, status pending, KEINE Mail
    // ------------------------------------------------------------------
    public function test_process_checkout_quote_request_allows_total_zero_and_sends_no_mail(): void
    {
        [$user, $photo] = $this->setupAccessibleItem();

        Mail::fake();

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
        Mail::assertNothingQueued();
    }

    // ------------------------------------------------------------------
    // 3. Lieferschein (Org invoice_frequency != immediate)
    // ------------------------------------------------------------------
    public function test_process_checkout_lieferschein_when_org_invoice_frequency_is_not_immediate(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();
        $org = Org::create(['name' => 'Firma AG', 'invoice_frequency' => 'monthly']);
        $user->org_id = $org->id;
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
        $this->assertSame(
            $org->id,
            $snapshot->customer_details[InvoiceSnapshot::PURCHASE_ORG_ID_KEY]
        );

        $this->assertMailpitSentTo($user->email);
    }

    // ------------------------------------------------------------------
    // 4. paymentMethod 'invoice' (ohne Org) -> status invoice_created + Mail
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

        Mail::fake();

        $response = $this->service->processCheckout(
            $this->makeRequest([['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web']]),
            $user,
            'stripe'
        );

        $this->assertEquals(403, $response->status());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, InvoiceSnapshot::count());
        Mail::assertNothingQueued();
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
    // 9. invoice_frequency 'immediate' + Org -> Stripe-Pfad (KEIN Lieferschein)
    // ------------------------------------------------------------------
    public function test_process_checkout_org_with_immediate_invoice_frequency_does_not_create_lieferschein(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();
        $org = Org::create(['name' => 'Immediate Co', 'invoice_frequency' => 'immediate']);
        $user->org_id = $org->id;
        $user->save();

        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->once())->method('request')->willReturnCallback(function ($method, $absUrl, $headers, $params, $hasFile) {
            return [json_encode([
                'id' => 'pi_test_imm',
                'status' => 'requires_payment_method',
                'client_secret' => 'sec_test_imm',
                'amount' => (int) ($params['amount'] ?? 0),
                'currency' => $params['currency'] ?? 'eur',
                'created' => time(),
                'customer' => $params['customer'] ?? null,
                'metadata' => $params['metadata'] ?? [],
            ]), 200, []];
        });
        ApiRequestor::setHttpClient($clientMock);

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
            ApiRequestor::setHttpClient(null);
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
    // 11. Stripe-Fehler nach abgeschlossener Transaktion:
    //     Order/Snapshot bleiben erhalten, nur kein PaymentIntent.
    // ------------------------------------------------------------------
    public function test_process_checkout_stripe_failure_keeps_order_and_snapshot(): void
    {
        [$user, $photo, $useCase] = $this->setupPaidItem();

        // Stripe-Client wirft Exception — passiert NACH dem DB::transaction-Commit
        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->once())->method('request')->willThrowException(new \RuntimeException('Stripe down'));
        ApiRequestor::setHttpClient($clientMock);

        try {
            $response = $this->service->processCheckout(
                $this->makeRequest([['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web']]),
                $user,
                'stripe'
            );

            $this->assertEquals(502, $response->status());
            $this->assertSame('Die Zahlung konnte nicht verarbeitet werden. Bitte versuche es später erneut.', $response->getData(true)['error']);
        } finally {
            ApiRequestor::setHttpClient(null);
        }

        // Order/Snapshot sind erhalten, da die DB-Transaktion vor dem Stripe-Call committed wurde
        $this->assertSame(1, Order::count());
        $this->assertSame(1, InvoiceSnapshot::count());

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertEquals('pending_payment', $order->status);
        $this->assertNull($order->stripe_payment_intent_id);
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

            $strategy = $this->createStub(PricingStrategy::class);
            $strategy->method('supportsCoupons')->willReturn(true);
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

    public function test_targeted_coupon_revenue_is_attributed_only_to_the_covered_photo(): void
    {
        BrandRegistry::set(Brand::B2B);

        try {
            $coupon = Coupon::factory()->create([
                'code' => 'ONEPHOTO50',
                'type' => 'percentage',
                'value' => 50,
                'max_items' => 1,
                'brand' => Brand::B2B->value,
                'active' => true,
            ]);
            $gallery = Gallery::factory()->create(['is_public' => true]);
            $photographerA = User::factory()->create();
            $photographerB = User::factory()->create();
            $photoA = Photo::factory()->create([
                'gallery_id' => $gallery->id,
                'user_id' => $photographerA->id,
            ]);
            $photoB = Photo::factory()->create([
                'gallery_id' => $gallery->id,
                'user_id' => $photographerB->id,
            ]);
            $customer = User::factory()->create();

            $strategy = $this->createStub(PricingStrategy::class);
            $strategy->method('supportsCoupons')->willReturn(true);
            $strategy->method('calculateCart')->willReturn([
                'items' => [
                    ['itemId' => $photoA->id, 'priceCents' => 1001, 'tier' => 'web', 'useCaseName' => 'Web', 'modifierNames' => []],
                    ['itemId' => $photoB->id, 'priceCents' => 1001, 'tier' => 'web', 'useCaseName' => 'Web', 'modifierNames' => []],
                ],
                'totalCents' => 1501,
                'discountCents' => 501,
                'couponId' => $coupon->id,
                'couponType' => $coupon->type,
            ]);
            $this->service = new CheckoutService($strategy);

            $response = $this->service->processCheckout(
                $this->makeRequest(
                    [
                        ['photoId' => $photoA->id, 'tier' => 'web'],
                        ['photoId' => $photoB->id, 'tier' => 'web'],
                    ],
                    ['coupon_code' => $coupon->code],
                ),
                $customer,
                'invoice',
            );

            $this->assertSame(200, $response->status());
            $order = Order::sole();
            $snapshot = InvoiceSnapshot::sole();
            $payoutRevenue = $snapshot->customer_details[InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY];
            // The equal-price tie is resolved in invoice-line order. The
            // percentage rounds 1001 * 50% to 501 cents, all against photo A.
            $this->assertSame([500, 1001], array_values($payoutRevenue));
            $this->assertSame(1501, array_sum($payoutRevenue));
            $this->assertArrayNotHasKey(
                InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY,
                $snapshot->customerDetailsForPresentation(),
            );

            $order->forceFill([
                'status' => 'paid',
                'stripe_fee_cents' => 0,
                'created_at' => '2026-06-10 12:00:00',
            ])->save();
            app(PayoutCalculationService::class)->calculatePowerUserDelta(6, 2026);

            $this->assertSame(250, PhotographerStatement::where('user_id', $photographerA->id)->sole()->delta_surcharge_earnings_cents);
            $this->assertSame(500, PhotographerStatement::where('user_id', $photographerB->id)->sole()->delta_surcharge_earnings_cents);
        } finally {
            BrandRegistry::reset();
        }
    }

    public function test_volume_tier_and_targeted_coupon_are_attributed_to_their_effective_photo_revenue(): void
    {
        BrandRegistry::set(Brand::B2B);

        try {
            $presets = app(VolumePresetService::class);
            $preset = $presets->ensureDefaultPresetForBrand(Brand::B2B);
            $presets->update($preset, 'Standard', [
                ['min_quantity' => 0, 'price_cents' => 1000],
                ['min_quantity' => 2, 'price_cents' => 800],
            ]);
            $coupon = Coupon::factory()->create([
                'code' => 'VOLUME_ONE50',
                'type' => 'percentage',
                'value' => 50,
                'max_items' => 1,
                'brand' => Brand::B2B->value,
                'active' => true,
            ]);
            $gallery = Gallery::factory()->create([
                'is_public' => true,
                'licensing_mode' => 'volume_licensing',
                'volume_preset_id' => $preset->id,
            ]);
            $photographerA = User::factory()->create();
            $photographerB = User::factory()->create();
            $photoA = Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographerA->id]);
            $photoB = Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographerB->id]);
            $customer = User::factory()->create();

            $response = $this->service->processCheckout(
                $this->makeRequest(
                    [
                        ['photoId' => $photoA->id, 'tier' => 'original'],
                        ['photoId' => $photoB->id, 'tier' => 'original'],
                    ],
                    ['coupon_code' => $coupon->code],
                ),
                $customer,
                'invoice',
            );

            $this->assertSame(200, $response->status());
            $this->assertSame(1200, Order::sole()->total_amount);
            $metadata = InvoiceSnapshot::sole()->customer_details[InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY];
            // 2 × 800 effective volume price, less the 400-cent max_items
            // coupon on the first (equal-price) photo.
            $this->assertSame([400, 800], array_values($metadata));
            $this->assertSame(1200, array_sum($metadata));
        } finally {
            BrandRegistry::reset();
        }
    }

    public function test_photo_package_coupon_attributes_the_same_effective_photo_lines_it_discounts(): void
    {
        BrandRegistry::set(Brand::B2B);

        try {
            // Two volume groups with different effective prices: the package
            // must target the two cheapest payable lines, and the persisted
            // payout map must carry exactly that split.
            $presets = app(VolumePresetService::class);
            $standard = $presets->ensureDefaultPresetForBrand(Brand::B2B);
            $presets->update($standard, 'Standard', [
                ['min_quantity' => 0, 'price_cents' => 1000],
            ]);
            $premium = $presets->create('Premium', [
                ['min_quantity' => 0, 'price_cents' => 800],
            ]);

            $coupon = Coupon::factory()->photoPackage(2, 1000)->create([
                'code' => 'PACKAGE_TWO',
                'brand' => Brand::B2B->value,
                'active' => true,
            ]);
            $galleryStandard = Gallery::factory()->create([
                'is_public' => true,
                'licensing_mode' => 'volume_licensing',
                'volume_preset_id' => $standard->id,
            ]);
            $galleryPremium = Gallery::factory()->create([
                'is_public' => true,
                'licensing_mode' => 'volume_licensing',
                'volume_preset_id' => $premium->id,
            ]);
            $photographer = User::factory()->create();
            $photoA = Photo::factory()->create(['gallery_id' => $galleryStandard->id, 'user_id' => $photographer->id]);
            $photoB = Photo::factory()->create(['gallery_id' => $galleryPremium->id, 'user_id' => $photographer->id]);
            $photoC = Photo::factory()->create(['gallery_id' => $galleryPremium->id, 'user_id' => $photographer->id]);
            $customer = User::factory()->create();

            $response = $this->service->processCheckout(
                $this->makeRequest(
                    [
                        ['photoId' => $photoA->id, 'tier' => 'original'],
                        ['photoId' => $photoB->id, 'tier' => 'original'],
                        ['photoId' => $photoC->id, 'tier' => 'original'],
                    ],
                    ['coupon_code' => $coupon->code],
                ),
                $customer,
                'invoice',
            );

            $this->assertSame(200, $response->status());
            $order = Order::sole();
            // 1000 + 800 + 800 = 2600; the package covers both 800-cent lines
            // for 1000 cents instead of 1600 → 600 cents discount.
            $this->assertSame(600, $order->coupon_discount_cents);
            $this->assertSame(2000, $order->total_amount);

            $snapshot = InvoiceSnapshot::sole();
            $payoutRevenue = $snapshot->customer_details[InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY];
            $this->assertSame([1000, 500, 500], array_values($payoutRevenue));
            $this->assertSame(2000, array_sum($payoutRevenue));
            $this->assertSame((int) $snapshot->total_net, array_sum($payoutRevenue));
        } finally {
            BrandRegistry::reset();
        }
    }

    public function test_photo_package_coupon_skips_zero_price_lines_when_choosing_package_items(): void
    {
        BrandRegistry::set(Brand::B2B);

        try {
            // A zero-cent volume tier is a shipped state: the preset endpoint
            // validates `tiers.*.price_cents` with `min:0`. Such a line is
            // eligible for coupon math but not payable, so the package must
            // never be applied to it (that would swallow the discount).
            $presets = app(VolumePresetService::class);
            $paid = $presets->create('Paid', [
                ['min_quantity' => 0, 'price_cents' => 1000],
            ]);
            $free = $presets->create('Free', [
                ['min_quantity' => 0, 'price_cents' => 0],
            ]);
            $coupon = Coupon::factory()->photoPackage(1, 500)->create([
                'code' => 'PACKAGE_ONE',
                'brand' => Brand::B2B->value,
                'active' => true,
            ]);
            $galleryFree = Gallery::factory()->create([
                'is_public' => true,
                'licensing_mode' => 'volume_licensing',
                'volume_preset_id' => $free->id,
            ]);
            $galleryPaid = Gallery::factory()->create([
                'is_public' => true,
                'licensing_mode' => 'volume_licensing',
                'volume_preset_id' => $paid->id,
            ]);
            $photographer = User::factory()->create();
            $freePhoto = Photo::factory()->create(['gallery_id' => $galleryFree->id, 'user_id' => $photographer->id]);
            $photoA = Photo::factory()->create(['gallery_id' => $galleryPaid->id, 'user_id' => $photographer->id]);
            $photoB = Photo::factory()->create(['gallery_id' => $galleryPaid->id, 'user_id' => $photographer->id]);
            $customer = User::factory()->create();

            $response = $this->service->processCheckout(
                $this->makeRequest(
                    [
                        ['photoId' => $freePhoto->id, 'tier' => 'original'],
                        ['photoId' => $photoA->id, 'tier' => 'original'],
                        ['photoId' => $photoB->id, 'tier' => 'original'],
                    ],
                    ['coupon_code' => $coupon->code],
                ),
                $customer,
                'invoice',
            );

            $this->assertSame(200, $response->status());
            $order = Order::sole();
            // 0 + 1000 + 1000 = 2000; the package covers one payable 1000-cent
            // photo for 500 cents → 500 cents discount.
            $this->assertSame(500, $order->coupon_discount_cents);
            $this->assertSame(1500, $order->total_amount);

            $snapshot = InvoiceSnapshot::sole();
            $payoutRevenue = $snapshot->customer_details[InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY];
            // The discount lands on the first payable line; the zero-price line
            // keeps zero instead of receiving and swallowing the package.
            $this->assertSame([0, 500, 1000], array_values($payoutRevenue));
            $this->assertSame(1500, array_sum($payoutRevenue));
            $this->assertSame((int) $snapshot->total_net, array_sum($payoutRevenue));
        } finally {
            BrandRegistry::reset();
        }
    }

    // ------------------------------------------------------------------
    // 16. 100%-Coupon -> Warenkorb auf 0 -> kostenlose, settled Order
    //     (kein Stripe-Call), Download freigeschaltet.
    // ------------------------------------------------------------------
    public function test_process_checkout_with_full_discount_creates_settled_free_order(): void
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

            $strategy = $this->createStub(PricingStrategy::class);
            $strategy->method('calculateCart')->willReturn([
                'items' => [
                    ['itemId' => $photo1->id, 'priceCents' => 3000, 'tier' => 'original', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
                    ['itemId' => $photo2->id, 'priceCents' => 3000, 'tier' => 'original', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
                ],
                'totalCents' => 0,
                'discountCents' => 6000,
                'couponId' => $coupon->id,
                'couponType' => 'fixed',
            ]);

            // Amount 0 must never produce a PaymentIntent.
            $stripe = $this->createMock(StripePaymentService::class);
            $stripe->expects($this->never())->method('createPaymentIntent');

            Mail::fake();

            $this->service = new CheckoutService($strategy, $stripe);

            $response = $this->service->processCheckout(
                $this->makeRequest(
                    [
                        ['photoId' => $photo1->id, 'tier' => 'original'],
                        ['photoId' => $photo2->id, 'tier' => 'original'],
                    ],
                    ['coupon_code' => 'ZERO60']
                ),
                $user,
                'stripe'
            );

            $this->assertEquals(200, $response->status());

            $order = Order::first();
            $this->assertNotNull($order);
            $this->assertSame(0, $order->total_amount);
            $this->assertSame('paid', $order->status);
            $this->assertNull($order->stripe_payment_intent_id);
            $this->assertSame(1, InvoiceSnapshot::count());
            $this->assertSame(
                [0, 0],
                array_values(InvoiceSnapshot::first()->customer_details[InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY]),
            );

            Mail::assertQueued(InvoiceMail::class);

            $purchaseService = app(PurchaseService::class);
            $this->assertTrue($purchaseService->isOrderDownloadEligible($order));
            $this->assertTrue($purchaseService->hasPurchasedPhoto($user, $photo1->id, 'original'));

            app(PayoutCalculationService::class)->calculatePowerUserDelta((int) now()->month, (int) now()->year);
            $this->assertSame(0, PhotographerStatement::count());
        } finally {
            BrandRegistry::reset();
        }
    }

    // ------------------------------------------------------------------
    // 17. Negativer Total -> 400 (keine Order).
    // ------------------------------------------------------------------
    public function test_process_checkout_negative_total_is_rejected(): void
    {
        BrandRegistry::set(Brand::B2B);

        try {
            $gallery = Gallery::factory()->create(['is_public' => true]);
            $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
            $user = User::factory()->create();

            $strategy = $this->createStub(PricingStrategy::class);
            $strategy->method('calculateCart')->willReturn([
                'items' => [
                    ['itemId' => $photo->id, 'priceCents' => -100, 'tier' => 'srp', 'useCaseName' => 'SRP Lizenz', 'modifierNames' => []],
                ],
                'totalCents' => -100,
                'discountCents' => 0,
                'couponId' => null,
                'couponType' => null,
            ]);

            Mail::fake();

            $this->service = new CheckoutService($strategy);

            $response = $this->service->processCheckout(
                $this->makeRequest([['photoId' => $photo->id, 'tier' => 'srp']]),
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
