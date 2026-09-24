<?php

namespace Tests\Feature;

use App\Contracts\PricingStrategy;
use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\InvoiceMail;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Org;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\PurchaseService;
use App\Services\StripePaymentService;
use App\Support\ActorIdentity;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentFindingsRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These regressions exercise persistence and state transitions only;
        // do not make a local search service a prerequisite.
        Config::set('scout.driver', 'null');
        BrandRegistry::set(Brand::B2B);
        Cache::flush();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        BrandRegistry::reset();
        parent::tearDown();
    }

    public function test_photographer_cannot_create_a_coupon_for_a_foreign_gallery_without_writing(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER->value);
        $ownGallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'restricted_photographers' => true,
        ]);
        $foreignGallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'restricted_photographers' => true,
        ]);
        $photographer->photographerGalleries()->attach($ownGallery->id);

        $before = Coupon::count();
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($photographer)])
            ->postJson('/api/management/coupons', $this->couponPayload([
                'code' => 'FOREIGN-GALLERY',
                'scope_type' => 'gallery',
                'scope_id' => $foreignGallery->id,
            ]));

        $response->assertForbidden();
        $this->assertSame($before, Coupon::count());
        $this->assertDatabaseMissing('coupons', ['code' => 'FOREIGN-GALLERY']);
    }

    public function test_photographer_can_create_a_generic_coupon_for_an_assigned_gallery(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER->value);
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'restricted_photographers' => true,
        ]);
        $photographer->photographerGalleries()->attach($gallery->id);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($photographer)])
            ->postJson('/api/management/coupons', $this->couponPayload([
                'code' => 'OWNED-GALLERY',
                'scope_type' => 'gallery',
                'scope_id' => $gallery->id,
            ]));

        $response->assertCreated();
        $this->assertDatabaseHas('coupons', [
            'code' => 'OWNED-GALLERY',
            'scope_type' => 'gallery',
            'scope_id' => $gallery->id,
            'created_by' => $photographer->id,
        ]);
    }

    public function test_photographer_cannot_create_a_coupon_for_a_foreign_gallery_group_without_writing(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER->value);
        $ownGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B]);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B]);
        $photographer->photographerGalleryGroups()->attach($ownGroup->id);

        $before = Coupon::count();
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($photographer)])
            ->postJson('/api/management/coupons', $this->couponPayload([
                'code' => 'FOREIGN-GROUP',
                'scope_type' => 'meta_gallery',
                'scope_id' => $foreignGroup->id,
            ]));

        $response->assertForbidden();
        $this->assertSame($before, Coupon::count());
        $this->assertDatabaseMissing('coupons', ['code' => 'FOREIGN-GROUP']);
    }

    public function test_photographer_cannot_update_a_coupon_to_a_foreign_gallery_scope(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER->value);
        $foreignGallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'restricted_photographers' => true,
        ]);
        $coupon = Coupon::factory()->create([
            'brand' => Brand::B2B->value,
            'code' => 'UPDATE-FOREIGN-GALLERY',
            'created_by' => $photographer->id,
            'scope_type' => 'global',
            'active' => true,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($photographer)])
            ->putJson('/api/management/coupons/'.$coupon->id, [
                'scope_type' => 'gallery',
                'scope_id' => $foreignGallery->id,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'scope_type' => 'global',
            'scope_id' => null,
        ]);
    }

    public function test_photographer_cannot_update_a_coupon_to_a_foreign_gallery_group_scope(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER->value);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B]);
        $coupon = Coupon::factory()->create([
            'brand' => Brand::B2B->value,
            'code' => 'UPDATE-FOREIGN-GROUP',
            'created_by' => $photographer->id,
            'scope_type' => 'global',
            'active' => true,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($photographer)])
            ->putJson('/api/management/coupons/'.$coupon->id, [
                'scope_type' => 'meta_gallery',
                'scope_id' => $foreignGroup->id,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'scope_type' => 'global',
            'scope_id' => null,
        ]);
    }

    public function test_client_updates_cannot_reset_used_count_or_max_uses_on_a_maxed_coupon(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER->value);
        $coupon = Coupon::factory()->create([
            'brand' => Brand::B2B->value,
            'code' => 'MAXED-IMMUTABLE',
            'created_by' => $photographer->id,
            'max_uses_global' => 1,
            'used_count' => 1,
            'active' => true,
        ]);
        $headers = ['Authorization' => 'Bearer '.$this->token($photographer)];

        $this->withHeaders($headers)
            ->putJson('/api/management/coupons/'.$coupon->id, ['used_count' => 0])
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->putJson('/api/management/coupons/'.$coupon->id, ['max_uses_global' => 99])
            ->assertStatus(422);

        $coupon->refresh();
        $this->assertSame(1, $coupon->used_count);
        $this->assertSame(1, $coupon->max_uses_global);

        [$found, $error] = app(CouponService::class)->findValidCoupon(
            $coupon->code,
            Brand::B2B,
        );
        $this->assertNull($found);
        $this->assertStringContainsString('usage limit', (string) $error);

        $this->withHeaders($headers)
            ->deleteJson('/api/management/coupons/'.$coupon->id)
            ->assertStatus(422);
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }

    public function test_manual_status_transition_invalidates_the_positive_purchase_cache(): void
    {
        Storage::fake('photos');

        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'flatrate_level' => 'none',
        ]);
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'delivery',
            'is_public' => false,
            'is_hidden' => false,
            'gallery_group_id' => null,
        ]);
        $user->galleries()->attach($gallery->id);
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'is_hidden' => false,
        ]);
        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            file_get_contents(base_path('tests/Fixtures/sample.jpg'))
        );
        $photoId = (string) $photo->getKey();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'paid',
            'brand' => Brand::B2B,
            'total_amount' => 1000,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'CACHE-'.$order->getKey(),
            'brand' => Brand::B2B,
            'customer_details' => [
                'items' => [[
                    'photoId' => $photoId,
                    'tier' => 'original',
                    'price' => 1000,
                ]],
            ],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);

        $purchaseService = app(PurchaseService::class);
        $cacheKey = ActorIdentity::purchaseCacheKeyForActor($user, $photoId, 'original');
        $this->assertNotNull($cacheKey);
        $this->assertTrue($purchaseService->hasPurchasedPhoto($user, $photoId, 'original'));
        $this->assertTrue(Cache::get($cacheKey));

        $admin = $this->userWithRole(UserRole::ADMIN->value);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($admin)])
            ->putJson('/api/management/orders/'.$order->id.'/status', [
                'status' => 'refunded',
            ])
            ->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        $this->assertNull(Cache::get($cacheKey));
        $this->assertFalse($purchaseService->hasPurchasedPhoto($user, $photoId, 'original'));
    }

    public function test_late_dispute_does_not_regress_a_refunded_order(): void
    {
        $order = Order::factory()->create([
            'status' => 'refunded',
            'stripe_payment_intent_id' => 'pi-terminal-refunded',
        ]);
        $secret = 'whsec-terminal-refunded';
        Config::set('services.stripe.webhook_secret', $secret);

        $this->sendSignedStripeEvent([
            'id' => 'evt-terminal-refunded',
            'type' => 'charge.dispute.created',
            'data' => ['object' => ['payment_intent' => 'pi-terminal-refunded']],
        ], $secret)->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        Mail::assertNothingSent();
    }

    public function test_dispute_and_refund_events_do_not_mutate_cancelled_orders(): void
    {
        $disputeOrder = Order::factory()->create([
            'status' => 'cancelled',
            'stripe_payment_intent_id' => 'pi-terminal-cancelled-dispute',
        ]);
        $refundOrder = Order::factory()->create([
            'status' => 'cancelled',
            'stripe_payment_intent_id' => 'pi-terminal-cancelled-refund',
        ]);
        $secret = 'whsec-terminal-cancelled';
        Config::set('services.stripe.webhook_secret', $secret);

        $this->sendSignedStripeEvent([
            'id' => 'evt-terminal-cancelled-dispute',
            'type' => 'charge.dispute.created',
            'data' => ['object' => ['payment_intent' => 'pi-terminal-cancelled-dispute']],
        ], $secret)->assertOk();
        $this->sendSignedStripeEvent([
            'id' => 'evt-terminal-cancelled-refund',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'payment_intent' => 'pi-terminal-cancelled-refund',
                'refunded' => true,
            ]],
        ], $secret)->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $disputeOrder->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('orders', ['id' => $refundOrder->id, 'status' => 'cancelled']);
        Mail::assertNothingSent();
    }

    public function test_dispute_and_refund_events_do_not_mutate_pending_orders(): void
    {
        $quoteOrder = Order::factory()->create([
            'status' => 'pending',
            'is_quote_request' => true,
            'stripe_payment_intent_id' => 'pi-terminal-pending-dispute',
        ]);
        $paymentOrder = Order::factory()->create([
            'status' => 'pending_payment',
            'stripe_payment_intent_id' => 'pi-terminal-pending-refund',
        ]);
        $secret = 'whsec-terminal-pending';
        Config::set('services.stripe.webhook_secret', $secret);

        $this->sendSignedStripeEvent([
            'id' => 'evt-terminal-pending-dispute',
            'type' => 'charge.dispute.created',
            'data' => ['object' => ['payment_intent' => 'pi-terminal-pending-dispute']],
        ], $secret)->assertOk();
        $this->sendSignedStripeEvent([
            'id' => 'evt-terminal-pending-refund',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'payment_intent' => 'pi-terminal-pending-refund',
                'refunded' => true,
            ]],
        ], $secret)->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $quoteOrder->id, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['id' => $paymentOrder->id, 'status' => 'pending_payment']);
        Mail::assertNothingSent();
    }

    public function test_full_refund_can_complete_a_disputed_order(): void
    {
        $order = Order::factory()->create([
            'status' => 'disputed',
            'stripe_payment_intent_id' => 'pi-disputed-refund',
        ]);
        $secret = 'whsec-disputed-refund';
        Config::set('services.stripe.webhook_secret', $secret);

        $this->sendSignedStripeEvent([
            'id' => 'evt-disputed-refund',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'payment_intent' => 'pi-disputed-refund',
                'refunded' => true,
            ]],
        ], $secret)->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
    }

    public function test_zero_value_invoice_coupon_checkout_is_settled_paid_without_stripe(): void
    {
        [$user, $photo, $coupon] = $this->zeroValueCheckoutFixtures('FREE-INVOICE');
        $strategy = $this->zeroValueStrategy($photo, $coupon);
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('createPaymentIntent');

        $response = (new CheckoutService($strategy, $stripe))->processCheckout(
            $this->checkoutRequest($photo, $coupon->code),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame(0, $order->total_amount);
        $this->assertSame('paid', $order->status);
        $this->assertNull($order->stripe_payment_intent_id);
        $this->assertSame(1, $coupon->fresh()->used_count);
        Mail::assertQueued(InvoiceMail::class);
    }

    public function test_zero_value_delivery_note_checkout_keeps_the_intentional_delivery_note_status(): void
    {
        [$user, $photo, $coupon] = $this->zeroValueCheckoutFixtures('FREE-DELIVERY');
        $org = Org::create([
            'name' => 'Delivery Test Org',
            'brand' => Brand::B2B,
            'invoice_frequency' => 'monthly',
        ]);
        $user->org()->associate($org)->save();

        $strategy = $this->zeroValueStrategy($photo, $coupon);
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('createPaymentIntent');

        $response = (new CheckoutService($strategy, $stripe))->processCheckout(
            $this->checkoutRequest($photo, $coupon->code),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        $this->assertDatabaseHas('orders', [
            'id' => Order::first()->id,
            'status' => 'delivery_note',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function token(User $user): string
    {
        return auth('api')->login($user);
    }

    private function couponPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'SCOPE-'.uniqid(),
            'type' => 'fixed',
            'value' => 5,
            'scope_type' => 'global',
            'active' => true,
        ], $overrides);
    }

    /**
     * @return array{0: User, 1: Photo, 2: Coupon}
     */
    private function zeroValueCheckoutFixtures(string $code): array
    {
        $coupon = Coupon::factory()->create([
            'brand' => Brand::B2B->value,
            'code' => $code,
            'type' => 'fixed',
            'value' => 100,
            'scope_type' => 'global',
            'active' => true,
        ]);
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'is_public' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        return [User::factory()->create(), $photo, $coupon];
    }

    private function zeroValueStrategy(Photo $photo, Coupon $coupon): PricingStrategy
    {
        $strategy = $this->createStub(PricingStrategy::class);
        $strategy->method('supportsCoupons')->willReturn(true);
        $strategy->method('calculateCart')->willReturn([
            'items' => [[
                'itemId' => $photo->id,
                'priceCents' => 0,
                'tier' => 'web',
                'useCaseName' => 'Web',
                'modifierNames' => [],
            ]],
            'totalCents' => 0,
            'discountCents' => 10000,
            'couponId' => $coupon->id,
            'couponType' => 'fixed',
        ]);

        return $strategy;
    }

    private function checkoutRequest(Photo $photo, string $couponCode): Request
    {
        return Request::create('/', 'POST', [
            'items' => [[
                'photoId' => $photo->id,
                'tier' => 'web',
            ]],
            'billing_name' => 'Tester',
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Vienna',
            'withdrawal_waived' => true,
            'coupon_code' => $couponCode,
        ]);
    }

    private function sendSignedStripeEvent(array $payload, string $secret)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->postJson('/api/webhooks/stripe', $payload, [
            'Stripe-Signature' => 't='.$timestamp.',v1='.$signature,
        ]);
    }
}
