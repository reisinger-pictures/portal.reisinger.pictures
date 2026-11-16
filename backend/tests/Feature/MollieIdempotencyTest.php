<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Gallery;
use App\Models\LicenseUseCase;
use App\Models\MolliePaymentAttempt;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Tests\Support\FakesMollie;
use Tests\TestCase;

/**
 * The portal's checkout Idempotency-Key must reach Mollie unchanged: it is the
 * provider-side guarantee that a lost create response never produces a second
 * payment for the same attempt.
 */
class MollieIdempotencyTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    private MockMollieClient $mollieClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMollie();
        $this->mollieClient = $this->fakeMollie(
            [CreatePaymentRequest::class => $this->mollieCreateResponder('open')],
            retainRequests: true,
        );
        $this->withoutMiddleware(BrandContextMiddleware::class);
        BrandRegistry::set(Brand::B2B);

        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'volume_licensing']);
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'company_street', 'brand' => 'rp'], ['value' => 'Teststreet 1']);

        LicenseUseCase::forceCreate(['id' => '00000000-0000-0000-0000-000000000000', 'name' => 'Web', 'base_price' => 3000, 'flatrate_tier' => 'web', 'brand' => 'rp']);
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(null);
        parent::tearDown();
    }

    public function test_checkout_sends_the_attempt_idempotency_key_to_mollie(): void
    {
        [$user, $photo] = $this->createCheckoutItem();
        $token = auth('api')->login($user);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'mollie-checkout-request-0001',
        ])->postJson('/api/orders/checkout', $this->checkoutPayload($photo));

        $response->assertOk();
        $response->assertJsonStructure(['checkout_url', 'order_id']);

        $attempt = MolliePaymentAttempt::query()->sole();
        $keys = $this->mollieRecordedIdempotencyKeys($this->mollieClient);

        $this->assertCount(1, $keys, 'A new checkout must create exactly one Mollie payment.');
        $this->assertSame(
            $attempt->idempotency_key,
            $keys[0],
            'Mollie must receive the attempt idempotency key, not the browser key or a random value.',
        );
    }

    public function test_two_checkout_sessions_use_distinct_mollie_idempotency_keys(): void
    {
        [$user, $photo] = $this->createCheckoutItem();
        $token = auth('api')->login($user);

        $first = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'mollie-independent-checkout-0001',
        ])->postJson('/api/orders/checkout', $this->checkoutPayload($photo));
        $first->assertOk();

        // A different server fingerprint is a separate checkout session, so a
        // new key must produce its own attempt and provider payment.
        $payload = $this->checkoutPayload($photo);
        $payload['billing_street'] = 'Other Street 9';

        $second = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'mollie-independent-checkout-0002',
        ])->postJson('/api/orders/checkout', $payload);
        $second->assertOk();

        $attemptKeys = MolliePaymentAttempt::query()
            ->orderBy('generation')
            ->pluck('idempotency_key')
            ->all();
        $recordedKeys = $this->mollieRecordedIdempotencyKeys($this->mollieClient);

        $this->assertCount(2, $attemptKeys);
        $this->assertSame($attemptKeys, $recordedKeys);
        $this->assertNotEquals(
            $attemptKeys[0],
            $attemptKeys[1],
            'Two independent checkout sessions must not share a Mollie idempotency key.',
        );
    }

    public function test_retrying_the_same_session_resumes_without_a_second_create(): void
    {
        [$user, $photo] = $this->createCheckoutItem();
        $token = auth('api')->login($user);
        $payload = $this->checkoutPayload($photo);
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'mollie-repeatable-checkout-0001',
        ];

        $first = $this->withHeaders($headers)->postJson('/api/orders/checkout', $payload);
        $first->assertOk();

        $second = $this->withHeaders($headers)->postJson('/api/orders/checkout', $payload);
        $second->assertOk();
        $this->assertSame($first->json('checkout_url'), $second->json('checkout_url'));

        $this->assertCount(1, $this->mollieRecordedIdempotencyKeys($this->mollieClient));
        $this->assertSame(1, MolliePaymentAttempt::query()->count());
    }

    /**
     * @return array{0: User, 1: Photo}
     */
    private function createCheckoutItem(): array
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        return [User::factory()->create(), $photo];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(Photo $photo): array
    {
        return [
            'items' => [['photoId' => $photo->id, 'tier' => 'web']],
            'billing_name' => 'Tester',
            'billing_street' => 'Street 1',
            'billing_zip' => '1010',
            'billing_city' => 'Wien',
            'payment_method' => 'mollie',
            'withdrawal_waived' => true,
        ];
    }
}
