<?php

namespace Tests\Feature;

use App\Mail\CustomMail;
use App\Models\Gallery;
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
use Illuminate\Support\Facades\Mail;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class CheckoutStripeErrorTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        $this->service = new CheckoutService(new VolumeLicensingStrategy(new SettingResolver()));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        BrandRegistry::set(null);
        parent::tearDown();
    }

    public function test_stripe_failure_sets_order_cancelled_and_sends_accounting_mail(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->once())->method('request')
            ->willThrowException(new \RuntimeException('Stripe down'));
        ApiRequestor::setHttpClient($clientMock);

        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => $photo->id, 'tier' => 'srp']],
            'billing_name' => 'Test User',
            'billing_company' => null,
            'billing_street' => 'Teststr. 1',
            'billing_zip' => '1010',
            'billing_city' => 'Wien',
        ]);

        $response = $this->service->processCheckout($request, $user, 'stripe');

        $this->assertEquals(502, $response->status());
        $payload = $response->getData(true);
        $this->assertArrayHasKey('error', $payload);

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame('cancelled', $order->status);

        Mail::assertQueued(CustomMail::class, 1);
    }
}
