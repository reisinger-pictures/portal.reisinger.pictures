<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\InvoiceMail;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Services\CheckoutService;
use App\Services\OfferTokenService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MocksStripeClient;
use Tests\TestCase;

/**
 * WI-A + WI-B: Server-seitige Durchsetzung und Protokollierung des
 * Widerrufsverzichts beim Foto-Download sowie Widerruf-Absatz in
 * Kaufmail und Rechnungs-PDF.
 */
class WithdrawalConsentTest extends TestCase
{
    use MocksStripeClient;
    use RefreshDatabase;

    private CheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockStripePaymentIntentSuccess();
        $this->service = new CheckoutService(new ScopeLicensingStrategy);
        Mail::fake();

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'TESTAT11']);
        Setting::updateOrCreate(['key' => 'company_street', 'brand' => 'rp'], ['value' => 'Teststreet 1']);

        LicenseUseCase::forceCreate([
            'id' => '11111111-1111-1111-1111-111111111111',
            'name' => 'Tageszeitung',
            'base_price' => 8000,
            'flatrate_tier' => 'print',
            'brand' => 'rp',
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetStripeHttpClient();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // WI-A: Durchsetzung (Server-seitig)
    // ------------------------------------------------------------------

    public function test_purchase_with_withdrawal_waived_false_is_rejected(): void
    {
        [$user, $photo] = $this->createPurchasingUserAndPhoto();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders/checkout', $this->purchasePayload($photo, ['withdrawal_waived' => false]));

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Sie müssen auf Ihr Widerrufsrecht verzichten, um digitale Bilddaten zu kaufen.']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_purchase_without_withdrawal_waived_field_is_rejected(): void
    {
        [$user, $photo] = $this->createPurchasingUserAndPhoto();
        $token = auth('api')->login($user);

        $payload = $this->purchasePayload($photo);
        unset($payload['withdrawal_waived']);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders/checkout', $payload);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_purchase_with_consent_persists_waiver_flag_and_snapshot_evidence(): void
    {
        [$user, $photo] = $this->createPurchasingUserAndPhoto();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders/checkout', $this->purchasePayload($photo, ['payment_method' => 'invoice', 'withdrawal_waived' => true]));

        $response->assertStatus(200);
        $orderId = $response->json('order_id');

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'withdrawal_waived' => 1]);

        $order = Order::find($orderId);
        $this->assertNotNull($order);
        $this->assertTrue($order->withdrawal_waived);
        $this->assertNotNull($order->withdrawal_consent_at, 'withdrawal_consent_at muss gesetzt sein');

        $snapshot = $order->invoiceSnapshot;
        $this->assertNotNull($snapshot);
        $consent = $snapshot->customer_details['withdrawal_consent'] ?? null;
        $this->assertNotNull($consent, 'invoice_snapshot.customer_details.withdrawal_consent muss vorhanden sein');
        $this->assertTrue($consent['waived']);
        $this->assertNotEmpty($consent['at']);
        $this->assertSame(CheckoutService::WITHDRAWAL_CONSENT_TEXT, $consent['text']);
    }

    public function test_quote_request_without_consent_is_allowed_with_waiver_false(): void
    {
        [$user, $photo] = $this->createPurchasingUserAndPhoto();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders/checkout', [
                'items' => [
                    ['photoId' => $photo->id, 'isQuote' => true, 'tier' => 'original'],
                ],
                'billing_name' => 'Test',
                'billing_street' => 'Str 1',
                'billing_zip' => '1234',
                'billing_city' => 'City',
                'payment_method' => 'stripe',
                'withdrawal_waived' => false,
            ]);

        $response->assertStatus(200);

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertTrue($order->is_quote_request);
        $this->assertFalse((bool) $order->withdrawal_waived);
        $this->assertNull($order->withdrawal_consent_at);
    }

    public function test_quote_token_flow_requires_withdrawal_consent_service_level(): void
    {
        // Der Guard greift serverseitig auch im quote_token-Flow (isQuote=false).
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $token = app(OfferTokenService::class)->issue([
            'photos' => [$photo->id],
            'price' => 5000,
        ], now()->addDays(7));

        $user = User::factory()->create();

        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => $photo->id, 'isQuote' => false, 'tier' => 'original', 'price' => 999]],
            'billing_name' => 'Tester',
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Wien',
            'quote_token' => $token,
        ]);

        $response = $this->service->processCheckout($request, $user, 'invoice');

        $this->assertEquals(422, $response->status());
        $this->assertSame('Sie müssen auf Ihr Widerrufsrecht verzichten, um digitale Bilddaten zu kaufen.', $response->getData(true)['error']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quote_token_flow_with_consent_persists_waiver(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $token = app(OfferTokenService::class)->issue([
            'photos' => [$photo->id],
            'price' => 5000,
        ], now()->addDays(7));

        $user = User::factory()->create();

        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => $photo->id, 'isQuote' => false, 'tier' => 'original', 'price' => 999]],
            'billing_name' => 'Tester',
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Wien',
            'quote_token' => $token,
            'withdrawal_waived' => true,
        ]);

        $response = $this->service->processCheckout($request, $user, 'invoice');

        $this->assertEquals(200, $response->status());

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame(5000, $order->total_amount);
        $this->assertTrue($order->withdrawal_waived);
        $this->assertNotNull($order->withdrawal_consent_at);

        $consent = $order->invoiceSnapshot->customer_details['withdrawal_consent'] ?? null;
        $this->assertNotNull($consent);
        $this->assertTrue($consent['waived']);
        $this->assertNotEmpty($consent['at']);
    }

    // ------------------------------------------------------------------
    // WI-B: Widerruf-Absatz in Kaufmail
    // ------------------------------------------------------------------

    public function test_invoice_mail_contains_withdrawal_text_only_when_consented(): void
    {
        [$consentedOrder, $consentedSnapshot] = $this->buildConsentedOrder();
        $html = (new InvoiceMail($consentedOrder, $consentedSnapshot))->build()->render();

        $this->assertStringContainsString('kein Widerrufs- bzw. Rücktrittsrecht mehr', $html);
        $this->assertStringContainsString('erteilt am', $html);

        [$plainOrder, $plainSnapshot] = $this->buildPlainOrder();
        $htmlWithout = (new InvoiceMail($plainOrder, $plainSnapshot))->build()->render();

        $this->assertStringNotContainsString('kein Widerrufs- bzw. Rücktrittsrecht mehr', $htmlWithout);
        $this->assertStringNotContainsString('erteilt am', $htmlWithout);
    }

    // ------------------------------------------------------------------
    // WI-B: Widerruf-Abschnitt im Rechnungs-PDF
    // ------------------------------------------------------------------

    public function test_invoice_pdf_contains_withdrawal_section_only_when_consent_present(): void
    {
        [$consentedOrder, $consentedSnapshot] = $this->buildConsentedOrder();
        $viewData = $this->pdfViewData($consentedOrder, $consentedSnapshot);

        $html = view('pdf.invoice', $viewData)->render();
        $this->assertStringContainsString('Widerrufsrecht bei digitalen Inhalten', $html);

        $pdf = Pdf::loadView('pdf.invoice', $viewData)->output(['compress' => 0]);
        $this->assertStringStartsWith('%PDF-1.', $pdf);
        $this->assertStringContainsString('Widerrufsrecht bei digitalen Inhalten', $pdf);

        [$plainOrder, $plainSnapshot] = $this->buildPlainOrder();
        $plainViewData = $this->pdfViewData($plainOrder, $plainSnapshot);

        $plainHtml = view('pdf.invoice', $plainViewData)->render();
        $this->assertStringNotContainsString('Widerrufsrecht bei digitalen Inhalten', $plainHtml);

        $plainPdf = Pdf::loadView('pdf.invoice', $plainViewData)->output(['compress' => 0]);
        $this->assertStringNotContainsString('Widerrufsrecht bei digitalen Inhalten', $plainPdf);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0: User, 1: Photo} */
    private function createPurchasingUserAndPhoto(): array
    {
        $user = User::factory()->create(['flatrate_level' => 'none']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::POWER_USER->value]));

        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        return [$user, $photo];
    }

    private function purchasePayload(Photo $photo, array $overrides = []): array
    {
        return array_merge([
            'items' => [
                [
                    'photoId' => $photo->id,
                    'tier' => 'original',
                    'useCaseId' => '11111111-1111-1111-1111-111111111111',
                    'modifierIds' => [],
                ],
            ],
            'billing_name' => 'Test',
            'billing_street' => 'Str 1',
            'billing_zip' => '1234',
            'billing_city' => 'City',
            'payment_method' => 'stripe',
            'withdrawal_waived' => true,
        ], $overrides);
    }

    /** @return array{0: Order, 1: InvoiceSnapshot} */
    private function buildConsentedOrder(): array
    {
        $user = User::factory()->create(['email' => 'consent@example.com']);
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'invoice_created',
            'brand' => Brand::B2B->value,
            'total_amount' => 1000,
            'withdrawal_waived' => true,
            'withdrawal_consent_at' => now(),
        ]);

        $snapshot = $this->createSnapshotFor($order, [
            'name' => 'Konsent Kunde',
            'email' => $user->email,
            'withdrawal_consent' => [
                'waived' => true,
                'at' => now()->toISOString(),
                'text' => CheckoutService::WITHDRAWAL_CONSENT_TEXT,
            ],
        ]);

        return [$order, $snapshot];
    }

    /** @return array{0: Order, 1: InvoiceSnapshot} */
    private function buildPlainOrder(): array
    {
        $user = User::factory()->create(['email' => 'plain@example.com']);
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'invoice_created',
            'brand' => Brand::B2B->value,
            'total_amount' => 1000,
        ]);

        $snapshot = $this->createSnapshotFor($order, [
            'name' => 'Normal Kunde',
            'email' => $user->email,
        ]);

        return [$order, $snapshot];
    }

    private function createSnapshotFor(Order $order, array $customerDetails): InvoiceSnapshot
    {
        return InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-2026-'.str_pad((string) mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT),
            'brand' => $order->brand,
            'customer_details' => array_merge([
                'street' => 'Teststr 1',
                'zip' => '1010',
                'city' => 'Wien',
                'country' => 'Österreich',
                'items' => [
                    ['photoId' => 'p1', 'filename' => 'Foto A', 'tier' => 'original', 'price' => 1000, 'row_total' => 1000],
                ],
            ], $customerDetails),
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);
    }

    private function pdfViewData(Order $order, InvoiceSnapshot $snapshot): array
    {
        return [
            'order' => $order,
            'snapshot' => $snapshot,
            'items' => $snapshot->customer_details['items'] ?? [],
            'bankHolder' => 'Test Holder',
            'bankIban' => 'AT123456789',
            'bankBic' => 'TESTAT11',
            'pfx' => 'rp',
            'primaryColor' => '#1E5631',
            'secondaryColor' => '#A4B494',
        ];
    }
}
