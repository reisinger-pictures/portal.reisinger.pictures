<?php

namespace Tests\Feature\Checkout;

use App\Enums\Brand;
use App\Http\Resources\InvoiceSnapshotResource;
use App\Mail\InvoiceMail;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Org;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CheckoutIdempotencyService;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\InvoiceMailDispatcher;
use App\Services\OfferTokenService;
use App\Services\StripePaymentService;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Non-immediate checkout identity regressions.
 *
 * Mail::fake() and the local mail-factory double below deliberately cover
 * deterministic enqueue/rollback and retry boundaries only. They are not SMTP
 * delivery evidence; the separate Mailpit integration suite owns that claim.
 */
class CheckoutNonImmediateIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        BrandRegistry::set(Brand::B2B);

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => Brand::B2B->value], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => Brand::B2B->value], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => Brand::B2B->value], ['value' => 'BIC']);
    }

    protected function tearDown(): void
    {
        BrandRegistry::reset();
        parent::tearDown();
    }

    public function test_duplicate_invoice_success_replays_the_same_order_and_snapshot(): void
    {
        Mail::fake();
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $service = $this->service();
        $request = $this->checkoutRequest($photo, $useCase, self::KEY);

        $first = $service->processCheckout($request, $user, 'invoice');
        $this->assertDatabaseHas('orders', [
            'checkout_idempotency_key' => self::KEY,
            'status' => 'invoice_created',
        ]);
        // The dispatch claim is durable in the snapshot, not an expiring cache
        // marker. Losing the cache must not make the replay enqueue again.
        Cache::flush();
        $snapshot = InvoiceSnapshot::query()->firstOrFail();
        $this->assertTrue($snapshot->invoiceMailDispatchClaimed());
        $resourceData = (new InvoiceSnapshotResource($snapshot))
            ->toArray(Request::create('/'));
        $this->assertArrayNotHasKey(InvoiceSnapshot::MAIL_DISPATCH_KEY, $resourceData['customer_details']);
        $second = $service->processCheckout($this->checkoutRequest($photo, $useCase, self::KEY), $user, 'invoice');

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());
        $this->assertSame(self::KEY, Order::first()->checkout_idempotency_key);
        $this->assertNotNull(Order::first()->checkout_fingerprint);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_lost_browser_key_is_an_accepted_new_order_boundary_for_non_immediate_checkout(): void
    {
        Mail::fake();
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $service = $this->service();

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, self::KEY),
            $user,
            'invoice',
        );
        $second = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, '22222222-2222-4222-8222-222222222222'),
            $user,
            'invoice',
        );

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertNotSame($first->getData(true)['order_id'], $second->getData(true)['order_id']);
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(2, InvoiceSnapshot::query()->count());
        $this->assertNotNull($first->getData(true)['order_id']);
        $this->assertNotNull(Order::query()->whereKey($first->getData(true)['order_id'])->value('checkout_fingerprint'));
        $this->assertNotNull(Order::query()->whereKey($second->getData(true)['order_id'])->value('checkout_fingerprint'));
        Mail::assertQueued(InvoiceMail::class, 2);
    }

    public function test_invoice_without_a_header_persists_a_generated_claim_and_replays_it(): void
    {
        Mail::fake();
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $service = $this->service();

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, null),
            $user,
            'invoice',
        );
        $order = Order::query()->firstOrFail();
        $second = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, null),
            $user,
            'invoice',
        );

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame('auto-'.$order->checkout_fingerprint, $order->checkout_idempotency_key);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_duplicate_delivery_note_success_replays_the_same_order_and_snapshot(): void
    {
        Mail::fake();
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $org = Org::query()->create([
            'name' => 'Idempotency GmbH',
            'invoice_frequency' => 'monthly',
            'brand' => Brand::B2B,
        ]);
        $user->org()->associate($org);
        $user->save();
        $service = $this->service();

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, self::KEY),
            $user,
            'stripe',
        );
        $second = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, self::KEY),
            $user,
            'stripe',
        );

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());
        $this->assertSame('delivery_note', Order::first()->status);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_unique_claim_race_reloads_the_winning_non_immediate_order(): void
    {
        Mail::fake();
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $service = app(CheckoutIdempotencyService::class);
        $request = $this->checkoutRequest($photo, $useCase, self::KEY);
        $identity = $service->identify($request, $user, 7500, 'invoice');

        $response = $service->executeNonImmediate(
            $user,
            $identity['key'],
            $identity['fingerprint'],
            7500,
            function (?Order $existingOrder) use ($user, $identity): JsonResponse {
                if ($existingOrder !== null) {
                    return response()->json([
                        'success' => true,
                        'order_id' => $existingOrder->id,
                    ]);
                }

                $winner = Order::factory()->create([
                    'user_id' => $user->id,
                    'brand' => Brand::B2B,
                    'status' => 'invoice_created',
                    'total_amount' => 7500,
                    'checkout_idempotency_key' => $identity['key'],
                    'checkout_fingerprint' => $identity['fingerprint'],
                ]);
                InvoiceSnapshot::factory()->for($winner)->create();

                // Force the same database unique constraint a concurrent
                // worker would hit after winning the identity race.
                Order::factory()->create([
                    'user_id' => $user->id,
                    'brand' => Brand::B2B,
                    'status' => 'invoice_created',
                    'total_amount' => 7500,
                    'checkout_idempotency_key' => $identity['key'],
                    'checkout_fingerprint' => $identity['fingerprint'],
                ]);

                return response()->json(['unexpected' => true]);
            },
        );

        $this->assertSame(200, $response->status());
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());
        $this->assertSame(Order::first()->id, $response->getData(true)['order_id']);
    }

    public function test_same_key_with_a_different_invoice_payload_is_a_conflict(): void
    {
        Mail::fake();
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $service = $this->service();

        $service->processCheckout($this->checkoutRequest($photo, $useCase, self::KEY), $user, 'invoice');
        $response = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, self::KEY, ['billing_city' => 'Graz']),
            $user,
            'invoice',
        );

        $this->assertSame(409, $response->status());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
        $this->assertSame(1, Order::query()->count());
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_invoice_retry_after_a_transient_response_failure_reuses_the_committed_order(): void
    {
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $service = $this->service();
        $pendingMail = new class
        {
            public int $queued = 0;

            public function queue(mixed $mailable): void
            {
                $this->queued++;
            }
        };
        $mailFactory = \Mockery::mock(Factory::class);
        $mailCalls = 0;
        $mailFactory->shouldReceive('to')
            ->twice()
            ->andReturnUsing(function () use (&$mailCalls, $pendingMail): object {
                if ($mailCalls++ === 0) {
                    throw new \RuntimeException('mail transport unavailable');
                }

                return $pendingMail;
            });
        Mail::swap($mailFactory);

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, self::KEY),
            $user,
            'invoice',
        );

        $this->assertSame(503, $first->status());
        $this->assertSame('Der Checkout konnte nicht abgeschlossen werden. Bitte versuche es erneut.', $first->getData(true)['error']);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());

        $retry = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, self::KEY),
            $user,
            'invoice',
        );

        $this->assertSame(200, $retry->status());
        $this->assertSame(Order::first()->id, $retry->getData(true)['order_id']);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());
        $this->assertSame(1, $pendingMail->queued);
    }

    public function test_marker_write_failure_rolls_back_the_claim_and_allows_one_retry_enqueue(): void
    {
        Mail::fake();
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $dispatcher = new class extends InvoiceMailDispatcher
        {
            public bool $failMarker = true;

            protected function claimMarker(InvoiceSnapshot $snapshot): void
            {
                if ($this->failMarker) {
                    $this->failMarker = false;
                    throw new \RuntimeException('durable mail marker write failed');
                }

                parent::claimMarker($snapshot);
            }
        };
        $service = $this->service($dispatcher);
        $request = $this->checkoutRequest($photo, $useCase, self::KEY);

        $first = $service->processCheckout($request, $user, 'invoice');

        $this->assertSame(503, $first->status());
        $this->assertSame(1, Order::query()->count());
        $this->assertFalse(InvoiceSnapshot::query()->firstOrFail()->invoiceMailDispatchClaimed());
        Mail::assertNothingQueued();

        $retry = $service->processCheckout($request, $user, 'invoice');

        $this->assertSame(200, $retry->status());
        $this->assertTrue(InvoiceSnapshot::query()->firstOrFail()->invoiceMailDispatchClaimed());
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_terminal_invoice_order_returns_a_conflict_instead_of_reopening_it(): void
    {
        Mail::fake();
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $service = $this->service();
        $service->processCheckout($this->checkoutRequest($photo, $useCase, self::KEY), $user, 'invoice');
        Order::query()->update(['status' => 'cancelled']);

        $response = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, self::KEY),
            $user,
            'invoice',
        );

        $this->assertSame(409, $response->status());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_archived_invoice_order_is_terminal_and_cannot_be_replayed(): void
    {
        Mail::fake();
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $service = $this->service();
        $service->processCheckout($this->checkoutRequest($photo, $useCase, self::KEY), $user, 'invoice');
        $order = Order::query()->firstOrFail();
        $order->update(['status' => 'archived_in_collective']);

        $response = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, self::KEY),
            $user,
            'invoice',
        );

        $this->assertSame(409, $response->status());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
        $this->assertSame('archived_in_collective', $order->fresh()->status);
        $this->assertSame(1, Order::query()->count());
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_duplicate_free_coupon_success_does_not_consume_the_coupon_or_enqueue_twice(): void
    {
        Mail::fake();
        [$user, $photo, $coupon] = $this->freeFixture();
        $service = $this->freeService();
        $request = $this->freeRequest($photo, $coupon->code, self::KEY);

        $first = $service->processCheckout($request, $user, 'stripe');
        $second = $service->processCheckout(
            $this->freeRequest($photo, $coupon->code, self::KEY),
            $user,
            'stripe',
        );

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());
        $this->assertSame('paid', Order::first()->status);
        $this->assertSame(self::KEY, Order::first()->checkout_idempotency_key);
        $this->assertNotNull(Order::first()->checkout_fingerprint);
        $this->assertSame(1, $coupon->fresh()->used_count);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_free_coupon_retry_replays_after_the_coupon_is_deactivated(): void
    {
        Mail::fake();
        [$user, $photo, $coupon] = $this->freeFixture();
        $service = $this->freeService();
        $request = $this->freeRequest($photo, $coupon->code, self::KEY);

        $first = $service->processCheckout($request, $user, 'stripe');
        $coupon->update(['active' => false]);
        $retry = $service->processCheckout(
            $this->freeRequest($photo, $coupon->code, self::KEY),
            $user,
            'stripe',
        );

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $retry->status());
        $this->assertSame($first->getData(true), $retry->getData(true));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_free_retry_completes_an_invoice_created_claim_without_reconsuming_the_coupon(): void
    {
        Mail::fake();
        [$user, $photo, $coupon] = $this->freeFixture();
        $service = $this->freeService();
        $first = $service->processCheckout(
            $this->freeRequest($photo, $coupon->code, self::KEY),
            $user,
            'stripe',
        );

        Order::query()->update(['status' => 'invoice_created']);
        $coupon->update(['active' => false]);
        $retry = $service->processCheckout(
            $this->freeRequest($photo, $coupon->code, self::KEY),
            $user,
            'stripe',
        );

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $retry->status());
        $this->assertSame($first->getData(true), $retry->getData(true));
        $this->assertSame('paid', Order::first()->status);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());
        $this->assertSame(1, $coupon->fresh()->used_count);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_terminal_free_order_returns_a_conflict(): void
    {
        Mail::fake();
        [$user, $photo, $coupon] = $this->freeFixture();
        $service = $this->freeService();
        $service->processCheckout($this->freeRequest($photo, $coupon->code, self::KEY), $user, 'stripe');
        Order::query()->update(['status' => 'refunded']);

        $response = $service->processCheckout(
            $this->freeRequest($photo, $coupon->code, self::KEY),
            $user,
            'stripe',
        );

        $this->assertSame(409, $response->status());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_duplicate_reactive_quote_success_replays_without_another_order_or_enqueue(): void
    {
        Mail::fake();
        [$user, $photo] = $this->quoteFixture();
        $service = $this->service();
        $request = $this->quoteRequest($photo, self::KEY);

        $first = $service->processCheckout($request, $user, 'stripe');
        $second = $service->processCheckout($this->quoteRequest($photo, self::KEY), $user, 'stripe');

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());
        $this->assertSame('pending', Order::first()->status);
        $this->assertSame(self::KEY, Order::first()->checkout_idempotency_key);
        $this->assertNotNull(Order::first()->checkout_fingerprint);
        Mail::assertNothingQueued();
    }

    public function test_terminal_reactive_quote_returns_a_conflict(): void
    {
        Mail::fake();
        [$user, $photo] = $this->quoteFixture();
        $service = $this->service();
        $service->processCheckout($this->quoteRequest($photo, self::KEY), $user, 'stripe');
        Order::query()->update(['status' => 'cancelled']);

        $response = $service->processCheckout(
            $this->quoteRequest($photo, self::KEY),
            $user,
            'stripe',
        );

        $this->assertSame(409, $response->status());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_signed_quote_invoice_replay_reuses_the_same_order(): void
    {
        Mail::fake();
        [$user, $photo] = $this->quoteFixture();
        $token = app(OfferTokenService::class)->issueQuote(
            [$photo->id],
            5000,
            brand: Brand::B2B->value,
            expiresAt: now()->addDay(),
        );
        $service = $this->service();
        $request = $this->quoteTokenRequest($photo, $token, self::KEY);

        $first = $service->processCheckout($request, $user, 'invoice');
        $second = $service->processCheckout(
            $this->quoteTokenRequest($photo, $token, self::KEY),
            $user,
            'invoice',
        );

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());
        $this->assertSame('invoice_created', Order::first()->status);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_signed_quote_retry_replays_after_the_token_expires(): void
    {
        Mail::fake();
        [$user, $photo] = $this->quoteFixture();
        $token = app(OfferTokenService::class)->issueQuote(
            [$photo->id],
            5000,
            brand: Brand::B2B->value,
            expiresAt: now()->addSecond(),
        );
        $service = $this->service();
        $request = $this->quoteTokenRequest($photo, $token, self::KEY);

        $first = $service->processCheckout($request, $user, 'invoice');
        $this->travel(2)->seconds();
        $retry = $service->processCheckout($request, $user, 'invoice');

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $retry->status());
        $this->assertSame($first->getData(true), $retry->getData(true));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, InvoiceSnapshot::query()->count());
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_delivery_note_classification_cannot_replay_a_payment_intent_claim(): void
    {
        Mail::fake();
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $request = $this->checkoutRequest($photo, $useCase, self::KEY);
        $idempotency = app(CheckoutIdempotencyService::class);
        $identity = $idempotency->identify($request, $user, 7500, 'stripe');
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => Brand::B2B,
            'status' => 'paid',
            'total_amount' => 7500,
            'is_quote_request' => false,
            'stripe_payment_intent_id' => 'pi_non_immediate_claim',
            'checkout_idempotency_key' => $identity['key'],
            'checkout_fingerprint' => $identity['fingerprint'],
        ]);
        InvoiceSnapshot::factory()->for($order)->create();
        $org = Org::query()->create([
            'name' => 'Changed Classification GmbH',
            'invoice_frequency' => 'monthly',
            'brand' => Brand::B2B,
        ]);
        $user->org()->associate($org);
        $user->save();

        $response = $this->service()->processCheckout($request, $user, 'stripe');

        $this->assertSame(409, $response->status());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, Order::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_same_key_is_isolated_between_registered_actors(): void
    {
        Mail::fake();
        [$firstUser, $photo, $useCase] = $this->invoiceFixture();
        $secondUser = User::factory()->create(['brand' => Brand::B2B]);
        $service = $this->service();

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, self::KEY),
            $firstUser,
            'invoice',
        );
        $second = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, self::KEY),
            $secondUser,
            'invoice',
        );

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertNotSame($first->getData(true)['order_id'], $second->getData(true)['order_id']);
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(2, InvoiceSnapshot::query()->count());
        Mail::assertQueued(InvoiceMail::class, 2);
    }

    public function test_non_immediate_replay_rejects_a_same_actor_order_from_another_brand(): void
    {
        [$user, $photo, $useCase] = $this->invoiceFixture();
        $service = app(CheckoutIdempotencyService::class);
        $request = $this->checkoutRequest($photo, $useCase, self::KEY);
        $identity = $service->identify($request, $user, 7500, 'invoice');
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => 'srp',
            'status' => 'invoice_created',
            'total_amount' => 7500,
            'checkout_idempotency_key' => $identity['key'],
            'checkout_fingerprint' => $identity['fingerprint'],
        ]);
        InvoiceSnapshot::factory()->for($order)->create();

        $response = $service->executeNonImmediate(
            $user,
            $identity['key'],
            $identity['fingerprint'],
            7500,
            fn () => $this->fail('A foreign-brand order must not be replayed.'),
        );

        $this->assertSame(409, $response->status());
        $this->assertTrue($response->getData(true)['idempotency_conflict']);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame('invoice_created', $order->fresh()->status);
    }

    public function test_checkout_fingerprint_binds_non_enum_brand_context(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => 'photo-brand-isolation']],
            'billing_name' => 'Tester',
        ]);
        $service = app(CheckoutIdempotencyService::class);

        $defaultIdentity = $service->identify($request, $user, 7500, 'invoice');

        BrandRegistry::set(new BrandConfig(
            id: 'srp',
            name: 'Second Brand',
            theme: 'srp',
            portalName: 'Second Portal',
            impressumUrl: null,
            logoPath: null,
        ));
        $secondBrandIdentity = $service->identify($request, $user, 7500, 'invoice');

        $this->assertNotSame($defaultIdentity['fingerprint'], $secondBrandIdentity['fingerprint']);
    }

    /**
     * @return array{0: User, 1: Photo, 2: LicenseUseCase}
     */
    private function invoiceFixture(): array
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'is_public' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create([
            'name' => 'Web',
            'base_price' => 7500,
            'flatrate_tier' => 'web',
            'brand' => Brand::B2B,
        ]);
        $user = User::factory()->create(['brand' => Brand::B2B]);

        return [$user, $photo, $useCase];
    }

    /**
     * @return array{0: User, 1: Photo}
     */
    private function quoteFixture(): array
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'is_public' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create(['brand' => Brand::B2B]);

        return [$user, $photo];
    }

    /**
     * @return array{0: User, 1: Photo, 2: Coupon}
     */
    private function freeFixture(): array
    {
        $coupon = Coupon::factory()->percentage(100)->create([
            'brand' => Brand::B2B->value,
            'code' => 'FREE-IDEMPOTENCY',
            'scope_type' => 'global',
            'active' => true,
        ]);
        [$user, $photo] = $this->quoteFixture();

        return [$user, $photo, $coupon];
    }

    private function service(?InvoiceMailDispatcher $invoiceMailDispatcher = null): CheckoutService
    {
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('createPaymentIntent');

        return new CheckoutService(
            new ScopeLicensingStrategy,
            $stripe,
            app(CouponService::class),
            null,
            null,
            null,
            null,
            $invoiceMailDispatcher,
        );
    }

    private function freeService(): CheckoutService
    {
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('createPaymentIntent');
        $preset = app(VolumePresetService::class)->resolveDefaultForBrand(Brand::B2B);

        return new CheckoutService(
            new VolumeLicensingStrategy($preset, app(CouponService::class)),
            $stripe,
            app(CouponService::class),
        );
    }

    private function checkoutRequest(
        Photo $photo,
        LicenseUseCase $useCase,
        ?string $key,
        array $overrides = [],
    ): Request {
        $request = Request::create('/', 'POST', array_merge([
            'items' => [[
                'photoId' => $photo->id,
                'tier' => 'web',
                'useCaseId' => $useCase->id,
            ]],
            'billing_name' => 'Tester',
            'billing_company' => null,
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
        ], $overrides));
        if ($key !== null) {
            $request->headers->set('Idempotency-Key', $key);
        }

        return $request;
    }

    private function freeRequest(Photo $photo, string $couponCode, string $key): Request
    {
        $request = Request::create('/', 'POST', [
            'items' => [[
                'photoId' => $photo->id,
                'tier' => 'volume',
            ]],
            'billing_name' => 'Tester',
            'billing_company' => null,
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
            'coupon_code' => $couponCode,
        ]);
        $request->headers->set('Idempotency-Key', $key);

        return $request;
    }

    private function quoteRequest(Photo $photo, string $key): Request
    {
        $request = Request::create('/', 'POST', [
            'items' => [[
                'photoId' => $photo->id,
                'isQuote' => true,
                'tier' => 'web',
            ]],
            'billing_name' => 'Tester',
            'billing_company' => null,
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Wien',
            'quote_message' => 'Bitte um Angebot',
            'withdrawal_waived' => false,
        ]);
        $request->headers->set('Idempotency-Key', $key);

        return $request;
    }

    private function quoteTokenRequest(Photo $photo, string $token, string $key): Request
    {
        $request = Request::create('/', 'POST', [
            'items' => [[
                'photoId' => $photo->id,
                'isQuote' => false,
                'tier' => 'original',
            ]],
            'quote_token' => $token,
            'billing_name' => 'Tester',
            'billing_company' => null,
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
        ]);
        $request->headers->set('Idempotency-Key', $key);

        return $request;
    }
}
