<?php

namespace Tests\Feature\Checkout;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Services\CheckoutService;
use App\Services\OfferTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression tests for:
 *  - P0-B4: quote `custom_price` must be positive (zero/negative quote tokens must not
 *    create downloadable orders).
 *  - P0-B13: a cross-brand license modifier must yield a 4xx, not a 500.
 */
class QuoteCheckoutSecurityTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CheckoutService(new ScopeLicensingStrategy);

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'BIC']);
    }

    private function makeRequest(array $items, array $extra = []): Request
    {
        return Request::create('/', 'POST', array_merge([
            'items' => $items,
            'billing_name' => 'Tester',
            'billing_company' => null,
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
        ], $extra));
    }

    private function issueQuoteToken(int $price): string
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        return app(OfferTokenService::class)->issue([
            'photos' => [$photo->id],
            'price' => $price,
        ], now()->addDays(7));
    }

    private function checkoutWithToken(string $token)
    {
        $photo = Photo::first();

        return $this->service->processCheckout(
            $this->makeRequest(
                [['photoId' => $photo->id, 'isQuote' => false, 'tier' => 'original']],
                ['quote_token' => $token]
            ),
            User::factory()->create(),
            'invoice'
        );
    }

    public function test_quote_token_with_zero_price_is_rejected(): void
    {
        Mail::fake();

        $response = $this->checkoutWithToken($this->issueQuoteToken(0));

        $this->assertSame(422, $response->status());
        $this->assertSame('Angebot ist ungültig.', $response->getData(true)['error']);
        $this->assertSame(0, Order::count());
    }

    public function test_quote_token_with_negative_price_is_rejected(): void
    {
        Mail::fake();

        $response = $this->checkoutWithToken($this->issueQuoteToken(-5000));

        $this->assertSame(422, $response->status());
        $this->assertSame('Angebot ist ungültig.', $response->getData(true)['error']);
        $this->assertSame(0, Order::count());
    }

    public function test_generate_quote_link_rejects_zero_and_negative_prices(): void
    {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($photographer);

        foreach ([0, -500] as $price) {
            $this->withHeaders(['Authorization' => "Bearer $token"])
                ->postJson('/api/management/orders/quote-link', [
                    'photo_ids' => ['uuid-1'],
                    'custom_price' => $price,
                ])
                ->assertStatus(422);
        }
    }

    public function test_cross_brand_license_modifier_is_rejected_with_422_not_500(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create(['name' => 'Web', 'base_price' => 5000, 'flatrate_tier' => 'web']);
        $foreignModifier = LicenseModifier::factory()->create(['brand' => 'srp']);

        $user = User::factory()->create();
        Mail::fake();

        $response = $this->service->processCheckout(
            $this->makeRequest([[
                'photoId' => $photo->id,
                'useCaseId' => $useCase->id,
                'modifierIds' => [$foreignModifier->id],
                'tier' => 'web',
            ]]),
            $user,
            'invoice'
        );

        $this->assertSame(422, $response->status());
        $this->assertSame('Ungültige Lizenz-Auswahl.', $response->getData(true)['error']);
        $this->assertSame(0, Order::count());
    }
}
