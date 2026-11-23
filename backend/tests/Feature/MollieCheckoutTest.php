<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Gallery;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseUseCase;
use App\Models\MolliePaymentAttempt;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mollie\Api\Exceptions\RetryableNetworkRequestException;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Tests\Support\AuthTokenFactory;
use Tests\Support\FakesMollie;
use Tests\TestCase;

/**
 * New online checkouts are Mollie-only: one payment per order, the hosted
 * checkout link is the only redirect target, and a failed provider call never
 * grants access or silently replaces an unresolved attempt.
 */
class MollieCheckoutTest extends TestCase
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

    public function test_checkout_creates_one_attempt_with_an_immutable_snapshot_and_a_hosted_link(): void
    {
        [$user, $photo] = $this->createCheckoutItem();

        $response = $this->checkout($user, $photo);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'payment_pending' => true,
            'status' => 'pending_payment',
        ]);
        $response->assertJsonStructure(['checkout_url', 'order_id', 'invoice_number', 'poll_url']);

        $order = Order::query()->sole();
        $this->assertSame('pending_payment', $order->status);
        $this->assertSame(3000, (int) $order->total_amount);
        $this->assertNotNull($order->ip_address);
        $this->assertNotNull(InvoiceSnapshot::query()->where('order_id', $order->id)->first());

        $attempt = MolliePaymentAttempt::query()->sole();
        $this->assertSame(1, $attempt->generation);
        $this->assertSame(3000, $attempt->amount_cents);
        $this->assertSame('EUR', $attempt->currency);
        $this->assertSame('test', $attempt->mode);
        $this->assertSame(MolliePaymentAttempt::STATUS_OPEN, $attempt->status);
        $this->assertSame($response->json('order_id'), (string) $attempt->order_id);
        $this->assertSame($response->json('checkout_url'), $attempt->checkout_url);
        $this->assertSame(3000, $attempt->snapshot['gross_cents']);
        $this->assertSame(3000, $attempt->snapshot['order_total_cents']);
        $this->assertSame('EUR', $attempt->snapshot['currency']);
        $this->assertTrue($attempt->snapshot['withdrawal_waived']);
        $this->assertSame('creditcard', $attempt->snapshot['payment_method']);

        $this->assertSame(3000, (int) $order->total_amount, 'The persisted gross is the charged amount.');
    }

    public function test_checkout_defaults_to_mollie_when_the_client_omits_the_provider(): void
    {
        [$user, $photo] = $this->createCheckoutItem();

        $payload = $this->payload($photo);
        unset($payload['payment_method']);

        $this->withHeaders(['Authorization' => 'Bearer '.AuthTokenFactory::forUser($user)])
            ->postJson('/api/orders/checkout', $payload)
            ->assertOk()
            ->assertJsonStructure(['checkout_url']);

        $this->assertSame(1, MolliePaymentAttempt::query()->count());
    }

    public function test_stripe_is_no_longer_an_accepted_payment_method(): void
    {
        [$user, $photo] = $this->createCheckoutItem();

        $payload = $this->payload($photo);
        $payload['payment_method'] = 'stripe';

        $this->withHeaders(['Authorization' => 'Bearer '.AuthTokenFactory::forUser($user)])
            ->postJson('/api/orders/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, MolliePaymentAttempt::query()->count());
    }

    public function test_checkout_fails_closed_without_a_configured_mollie_method(): void
    {
        $this->disablesMollie();
        [$user, $photo] = $this->createCheckoutItem();

        $this->checkout($user, $photo)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60');

        $this->assertSame(0, MolliePaymentAttempt::query()->count());
        $this->assertSame([], $this->mollieRecordedIdempotencyKeys($this->mollieClient));
    }

    public function test_checkout_fails_closed_for_an_empty_allowlist_without_an_attempt(): void
    {
        // The key and webhook are usable; only the allowlist is empty. The
        // checkout must refuse before creating a provider attempt — and before
        // the order and its invoice number exist.
        config(['services.mollie.methods' => []]);
        [$user, $photo] = $this->createCheckoutItem();
        $sequenceBefore = InvoiceSequence::query()->value('current_value');

        $this->checkout($user, $photo)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60');

        $this->assertSame(0, MolliePaymentAttempt::query()->count());
        $this->assertSame([], $this->mollieRecordedCreatePayloads($this->mollieClient));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame($sequenceBefore, InvoiceSequence::query()->value('current_value'));
    }

    public function test_checkout_fails_closed_when_the_configured_list_contains_a_bnpl_id(): void
    {
        // A forbidden id must disable the whole list, not be dropped while the
        // remaining methods keep operating. Creation is refused before the
        // order exists, so the misconfiguration costs no invoice number either
        // — settlement of an already-collected payment still works
        // (`hasCredential()`), which the reconcile/webhook/status tests pin.
        $this->configureMollie(['paybybank', 'klarna', 'creditcard']);
        [$user, $photo] = $this->createCheckoutItem();
        $sequenceBefore = InvoiceSequence::query()->value('current_value');

        $this->checkout($user, $photo)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60');

        $this->assertSame(0, MolliePaymentAttempt::query()->count());
        $this->assertSame([], $this->mollieRecordedCreatePayloads($this->mollieClient));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InvoiceSnapshot::query()->count());
        $this->assertSame($sequenceBefore, InvoiceSequence::query()->value('current_value'));
    }

    public function test_checkout_sends_the_chosen_method_and_records_it_in_the_attempt_snapshot(): void
    {
        $this->configureMollie(['paybybank', 'eps', 'creditcard']);
        [$user, $photo] = $this->createCheckoutItem();

        $payload = $this->payload($photo);
        $payload['mollie_method'] = 'eps';

        $this->withHeaders(['Authorization' => 'Bearer '.AuthTokenFactory::forUser($user)])
            ->postJson('/api/orders/checkout', $payload)
            ->assertOk()
            ->assertJsonStructure(['checkout_url']);

        $attempt = MolliePaymentAttempt::query()->sole();
        $this->assertSame('eps', $attempt->snapshot['payment_method']);

        $payloads = $this->mollieRecordedCreatePayloads($this->mollieClient);
        $this->assertCount(1, $payloads);
        $this->assertSame('eps', $payloads[0]['method']);
    }

    public function test_checkout_defaults_to_the_first_allowlist_entry_when_the_client_omits_the_method(): void
    {
        $this->configureMollie(['paybybank', 'eps', 'creditcard']);
        [$user, $photo] = $this->createCheckoutItem();

        $payload = $this->payload($photo);
        $this->assertArrayNotHasKey('mollie_method', $payload);

        $this->withHeaders(['Authorization' => 'Bearer '.AuthTokenFactory::forUser($user)])
            ->postJson('/api/orders/checkout', $payload)
            ->assertOk()
            ->assertJsonStructure(['checkout_url']);

        $attempt = MolliePaymentAttempt::query()->sole();
        $this->assertSame('paybybank', $attempt->snapshot['payment_method']);

        $payloads = $this->mollieRecordedCreatePayloads($this->mollieClient);
        $this->assertCount(1, $payloads);
        $this->assertSame('paybybank', $payloads[0]['method']);
    }

    public function test_checkout_rejects_a_method_outside_the_allowlist_before_any_order(): void
    {
        $this->configureMollie(['paybybank', 'eps', 'creditcard']);
        [$user, $photo] = $this->createCheckoutItem();

        $payload = $this->payload($photo);
        $payload['mollie_method'] = 'klarna';

        $this->withHeaders(['Authorization' => 'Bearer '.AuthTokenFactory::forUser($user)])
            ->postJson('/api/orders/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('mollie_method');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, MolliePaymentAttempt::query()->count());
        $this->assertSame([], $this->mollieRecordedCreatePayloads($this->mollieClient));
    }

    public function test_changing_the_mollie_method_does_not_resume_the_earlier_payment(): void
    {
        // The chosen method is part of the checkout identity. A retry with the
        // same Idempotency-Key and another allowed method must not hand out the
        // payment the customer did not pick.
        $this->configureMollie(['paybybank', 'eps', 'creditcard']);
        [$user, $photo] = $this->createCheckoutItem();
        $headers = [
            'Authorization' => 'Bearer '.AuthTokenFactory::forUser($user),
            'Idempotency-Key' => 'mollie-method-identity-0001',
        ];

        $first = $this->payload($photo);
        $first['mollie_method'] = 'eps';
        $this->withHeaders($headers)->postJson('/api/orders/checkout', $first)->assertOk();

        $attempt = MolliePaymentAttempt::query()->sole();
        $this->assertSame('eps', $attempt->snapshot['payment_method']);

        $second = $this->payload($photo);
        $second['mollie_method'] = 'creditcard';
        $this->withHeaders($headers)->postJson('/api/orders/checkout', $second)
            ->assertStatus(409)
            ->assertJsonPath('idempotency_conflict', true);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, MolliePaymentAttempt::query()->count());
        $this->assertSame($attempt->checkout_url, MolliePaymentAttempt::query()->sole()->checkout_url);
    }

    public function test_checkout_fails_closed_without_a_webhook_url(): void
    {
        // The webhook URL is mandatory in every environment. Mollie validates it
        // at create time and answers HTTP 422 when it cannot reach it, so the
        // checkout refuses before an attempt exists instead of creating a
        // payment whose settlement nobody delivers.
        config(['services.mollie.webhook_url' => '']);

        [$user, $photo] = $this->createCheckoutItem();
        $sequenceBefore = InvoiceSequence::query()->value('current_value');

        $this->checkout($user, $photo)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertExactJson([
                'error' => 'Die Zahlung ist vorübergehend nicht verfügbar. Bitte versuche es in wenigen Minuten erneut.',
            ]);

        $this->assertSame(0, MolliePaymentAttempt::query()->count());
        $this->assertSame([], $this->mollieRecordedIdempotencyKeys($this->mollieClient));
        // The refusal precedes the order transaction: no order, no invoice
        // snapshot and no consumed invoice number.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InvoiceSnapshot::query()->count());
        $this->assertSame($sequenceBefore, InvoiceSequence::query()->value('current_value'));
    }

    public function test_checkout_fails_closed_with_a_plain_http_webhook_url(): void
    {
        // Mollie cannot reach an HTTP endpoint, so a plain-HTTP value is as
        // unusable as a missing one — in local/testing as well, where the
        // redirect URL may still be HTTP.
        config(['services.mollie.webhook_url' => 'http://localhost:8001/api/webhooks/mollie']);

        [$user, $photo] = $this->createCheckoutItem();
        $sequenceBefore = InvoiceSequence::query()->value('current_value');

        $this->checkout($user, $photo)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60');

        $this->assertSame(0, MolliePaymentAttempt::query()->count());
        $this->assertSame([], $this->mollieRecordedIdempotencyKeys($this->mollieClient));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InvoiceSnapshot::query()->count());
        $this->assertSame($sequenceBefore, InvoiceSequence::query()->value('current_value'));
    }

    public function test_an_open_attempt_resumes_its_persisted_link_without_a_usable_webhook(): void
    {
        // The payment already exists at Mollie. Handing back its persisted
        // hosted link creates nothing, so a broken webhook must not answer 503
        // to a customer who could otherwise still pay.
        [$user, $photo] = $this->createCheckoutItem();
        $headers = [
            'Authorization' => 'Bearer '.AuthTokenFactory::forUser($user),
            'Idempotency-Key' => 'mollie-resume-without-webhook-0001',
        ];

        $first = $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo));
        $first->assertOk();
        $checkoutUrl = $first->json('checkout_url');
        $this->assertIsString($checkoutUrl);
        $this->assertNotSame('', $checkoutUrl);

        config(['services.mollie.webhook_url' => '']);

        $retry = $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo));

        $retry->assertOk()->assertJson([
            'success' => true,
            'payment_pending' => true,
            'status' => 'pending_payment',
            'checkout_url' => $checkoutUrl,
        ]);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, MolliePaymentAttempt::query()->count());
        $this->assertCount(
            1,
            $this->mollieRecordedIdempotencyKeys($this->mollieClient),
            'A resume must not create a second payment at the provider.',
        );
    }

    public function test_a_paid_attempt_replays_the_paid_response_without_a_usable_webhook(): void
    {
        Mail::fake();
        [$user, $photo] = $this->createCheckoutItem();
        $headers = [
            'Authorization' => 'Bearer '.AuthTokenFactory::forUser($user),
            'Idempotency-Key' => 'mollie-paid-replay-without-webhook-0001',
        ];

        $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo))->assertOk();

        $order = Order::query()->sole();
        $attempt = MolliePaymentAttempt::query()->sole();
        // A crash after the provider verification but before the order
        // transition: the attempt is paid, the order still pending.
        $attempt->update(['status' => MolliePaymentAttempt::STATUS_PAID, 'paid_at' => now()]);

        config(['services.mollie.webhook_url' => '']);

        $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'paid',
                'order_id' => (string) $order->getKey(),
            ]);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, MolliePaymentAttempt::query()->count());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_the_same_key_recovers_once_the_configuration_is_fixed(): void
    {
        config(['services.mollie.webhook_url' => '']);
        [$user, $photo] = $this->createCheckoutItem();
        $headers = [
            'Authorization' => 'Bearer '.AuthTokenFactory::forUser($user),
            'Idempotency-Key' => 'mollie-config-recovery-0001',
        ];
        $sequenceBefore = InvoiceSequence::query()->value('current_value');

        $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo))
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, MolliePaymentAttempt::query()->count());
        $this->assertSame($sequenceBefore, InvoiceSequence::query()->value('current_value'));

        // The operation fixes the configuration; the browser retries with the
        // key it already holds. That must be a new checkout, not a 409 and not
        // a second order.
        $this->configureMollie();

        $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo))
            ->assertOk()
            ->assertJsonStructure(['checkout_url', 'order_id']);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, MolliePaymentAttempt::query()->count());
    }

    public function test_checkout_kill_switch_blocks_a_mollie_payment_before_any_order(): void
    {
        config(['checkout.enabled' => false]);
        [$user, $photo] = $this->createCheckoutItem();

        $this->checkout($user, $photo)
            ->assertStatus(503)
            ->assertJsonPath('error', 'Der Checkout ist vorübergehend nicht verfügbar. Bitte versuche es später erneut.');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_timeout_keeps_the_attempt_creating_and_the_retry_reuses_it(): void
    {
        // A real transport failure: the SDK's retryable network error.
        $this->fakeMollie([
            CreatePaymentRequest::class => function ($pendingRequest) {
                throw new RetryableNetworkRequestException($pendingRequest, 'timeout');
            },
        ]);

        [$user, $photo] = $this->createCheckoutItem();
        $headers = ['Authorization' => 'Bearer '.AuthTokenFactory::forUser($user), 'Idempotency-Key' => 'mollie-timeout-retry-0001'];

        $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo))
            ->assertStatus(503);

        $attempt = MolliePaymentAttempt::query()->sole();
        $this->assertSame(MolliePaymentAttempt::STATUS_CREATING, $attempt->status);
        $this->assertNull($attempt->mollie_payment_id);
        $originalKey = $attempt->idempotency_key;
        $this->assertSame('pending_payment', Order::query()->sole()->status);

        $this->fakeMollie(
            [CreatePaymentRequest::class => $this->mollieCreateResponder('open')],
            retainRequests: true,
        );

        $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo))
            ->assertOk()
            ->assertJsonStructure(['checkout_url']);

        $this->assertSame(1, MolliePaymentAttempt::query()->count());
        $this->assertSame(1, (int) MolliePaymentAttempt::query()->sole()->generation);
        $this->assertSame($originalKey, MolliePaymentAttempt::query()->sole()->idempotency_key);
    }

    public function test_a_definitive_rejection_moves_to_the_next_generation(): void
    {
        $this->fakeMollie([
            CreatePaymentRequest::class => MockResponse::unprocessableEntity(),
        ]);

        [$user, $photo] = $this->createCheckoutItem();
        $headers = ['Authorization' => 'Bearer '.AuthTokenFactory::forUser($user), 'Idempotency-Key' => 'mollie-retry-generation-0001'];

        $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo))
            ->assertStatus(503);

        $first = MolliePaymentAttempt::query()->sole();
        $this->assertSame(MolliePaymentAttempt::STATUS_FAILED, $first->status);
        $this->assertNull($first->mollie_payment_id);

        $this->fakeMollie(
            [CreatePaymentRequest::class => $this->mollieCreateResponder('open')],
            retainRequests: true,
        );

        $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo))
            ->assertOk();

        $attempts = MolliePaymentAttempt::query()->orderBy('generation')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame([1, 2], $attempts->pluck('generation')->all());
        $this->assertNotSame($attempts[0]->idempotency_key, $attempts[1]->idempotency_key);
        $this->assertSame(MolliePaymentAttempt::STATUS_OPEN, $attempts[1]->status);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_the_attempt_ceiling_stops_new_generations(): void
    {
        config(['services.mollie.max_attempts' => 1]);
        $this->fakeMollie([
            CreatePaymentRequest::class => MockResponse::unprocessableEntity(),
        ]);

        [$user, $photo] = $this->createCheckoutItem();
        $headers = ['Authorization' => 'Bearer '.AuthTokenFactory::forUser($user), 'Idempotency-Key' => 'mollie-attempt-ceiling-0001'];

        $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo))
            ->assertStatus(503);

        $this->withHeaders($headers)->postJson('/api/orders/checkout', $this->payload($photo))
            ->assertStatus(409)
            ->assertJsonPath('idempotency_conflict', true);

        $this->assertSame(1, MolliePaymentAttempt::query()->count());
    }

    public function test_an_amount_mismatch_in_the_create_response_never_redirects_or_grants_access(): void
    {
        $this->fakeMollie([
            CreatePaymentRequest::class => fn ($pendingRequest) => $this->molliePaymentResponse(
                id: 'tr_amountmismatch12',
                status: 'open',
                amount: new Money('EUR', '0.01'),
                metadata: [],
                mode: 'test',
            ),
        ]);

        [$user, $photo] = $this->createCheckoutItem();

        $response = $this->checkout($user, $photo);
        $response->assertStatus(503);
        $this->assertArrayNotHasKey('checkout_url', $response->json());

        $attempt = MolliePaymentAttempt::query()->sole();
        $this->assertSame(MolliePaymentAttempt::STATUS_AMBIGUOUS, $attempt->status);
        $this->assertSame('pending_payment', Order::query()->sole()->status);
    }

    public function test_a_quote_request_creates_no_mollie_attempt(): void
    {
        [$user, $photo] = $this->createCheckoutItem();

        $payload = $this->payload($photo);
        $payload['items'] = [['photoId' => $photo->id, 'tier' => 'web', 'isQuote' => true]];
        $payload['quote_message'] = 'Bitte um Angebot';

        $this->withHeaders(['Authorization' => 'Bearer '.AuthTokenFactory::forUser($user)])
            ->postJson('/api/orders/checkout', $payload)
            ->assertOk();

        $this->assertSame(0, MolliePaymentAttempt::query()->count());
        $this->assertSame('pending', Order::query()->sole()->status);
    }

    public function test_a_checkout_link_on_a_mollie_subdomain_is_accepted(): void
    {
        $this->fakeMollie([
            CreatePaymentRequest::class => fn ($pendingRequest) => $this->molliePaymentResponse(
                id: 'tr_subdomaincheck123',
                status: 'open',
                amount: new Money('EUR', '30.00'),
                metadata: ['attempt_id' => 'x'],
                mode: 'test',
                checkoutUrl: 'https://pay.mollie.com/checkout/select-method/tr_subdomaincheck123',
            ),
        ]);

        [$user, $photo] = $this->createCheckoutItem();

        $this->checkout($user, $photo)
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://pay.mollie.com/checkout/select-method/tr_subdomaincheck123');

        $this->assertSame(
            'https://pay.mollie.com/checkout/select-method/tr_subdomaincheck123',
            MolliePaymentAttempt::query()->sole()->checkout_url,
        );
    }

    public function test_a_checkout_link_on_a_lookalike_host_is_never_redirected(): void
    {
        $this->fakeMollie([
            CreatePaymentRequest::class => fn ($pendingRequest) => $this->molliePaymentResponse(
                id: 'tr_lookalikehost123',
                status: 'open',
                amount: new Money('EUR', '30.00'),
                metadata: ['attempt_id' => 'x'],
                mode: 'test',
                checkoutUrl: 'https://notmollie.com/checkout/select-method/tr_lookalikehost123',
            ),
        ]);

        [$user, $photo] = $this->createCheckoutItem();

        $response = $this->checkout($user, $photo);
        $response->assertStatus(503);
        $this->assertArrayNotHasKey('checkout_url', $response->json());

        $attempt = MolliePaymentAttempt::query()->sole();
        $this->assertSame(MolliePaymentAttempt::STATUS_AMBIGUOUS, $attempt->status);
        $this->assertNull($attempt->checkout_url);
        $this->assertSame('pending_payment', Order::query()->sole()->status);
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
    private function payload(Photo $photo): array
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

    private function checkout(User $user, Photo $photo)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.AuthTokenFactory::forUser($user)])
            ->postJson('/api/orders/checkout', $this->payload($photo));
    }
}
