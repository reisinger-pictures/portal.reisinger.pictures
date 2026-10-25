<?php

namespace Tests\Feature;

use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use App\Services\PurchaseService;
use App\Support\ActorIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `charge.dispute.closed` contract (PAY-4 / issue #26):
 * a WON dispute restores the settled `paid` state and download access, a LOST
 * dispute keeps the block. The transition must never undo a full refund.
 */
class DisputeClosedRestorationTest extends TestCase
{
    use RefreshDatabase;

    private const PHOTO_ID = 'photo-dispute-closed';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
    }

    private function signPayload(string $secret, array $data): array
    {
        $payload = json_encode($data);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return [$payload, "t={$timestamp},v1={$signature}"];
    }

    private function orderWithSnapshot(string $status, string $paymentIntentId): Order
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => $status,
            'total_amount' => 5000,
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'name' => $user->name,
                'email' => $user->email,
                'items' => [
                    ['photoId' => self::PHOTO_ID, 'tier' => 'original', 'price' => 5000],
                ],
            ],
            'total_net' => 5000,
            'total_gross' => 5000,
            'tax_rate' => 0,
        ]);

        return $order->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function disputeClosedPayload(string $paymentIntentId, string $disputeStatus, string $eventId): array
    {
        return [
            'id' => $eventId,
            'type' => 'charge.dispute.closed',
            'data' => ['object' => [
                'id' => 'dp_'.$eventId,
                'payment_intent' => $paymentIntentId,
                'status' => $disputeStatus,
            ]],
        ];
    }

    private function sendDisputeClosed(string $secret, array $payload)
    {
        [, $signature] = $this->signPayload($secret, $payload);

        return $this->postJson('/api/webhooks/stripe', $payload, [
            'Stripe-Signature' => $signature,
        ]);
    }

    /**
     * @return list<string>
     */
    private function seedPurchaseCache(Order $order): array
    {
        $keys = [];
        foreach (['web', 'print', 'original'] as $tier) {
            $key = ActorIdentity::purchaseCacheKeyForOrder($order, self::PHOTO_ID, $tier);
            $this->assertNotNull($key);
            // A cached negative result from the disputed window: exactly the
            // entry that would keep blocking the download after restoration.
            Cache::put($key, false, 3600);
            $keys[] = $key;
        }

        return $keys;
    }

    public function test_won_dispute_restores_paid_status_and_download_access(): void
    {
        $secret = 'whsec_dispute_closed_won';
        Config::set('services.stripe.webhook_secret', $secret);
        $order = $this->orderWithSnapshot('disputed', 'pi_dispute_closed_won');
        $cacheKeys = $this->seedPurchaseCache($order);

        $this->sendDisputeClosed(
            $secret,
            $this->disputeClosedPayload('pi_dispute_closed_won', 'won', 'evt_dispute_closed_won'),
        )->assertOk()->assertJson(['status' => 'success']);

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertTrue(app(PurchaseService::class)->isOrderDownloadEligible($fresh));

        foreach ($cacheKeys as $key) {
            $this->assertNull(Cache::get($key), 'Purchase cache must be cleared for a positive signal.');
        }
    }

    public function test_lost_dispute_keeps_the_order_disputed_and_download_blocked(): void
    {
        $secret = 'whsec_dispute_closed_lost';
        Config::set('services.stripe.webhook_secret', $secret);
        $order = $this->orderWithSnapshot('disputed', 'pi_dispute_closed_lost');

        $this->sendDisputeClosed(
            $secret,
            $this->disputeClosedPayload('pi_dispute_closed_lost', 'lost', 'evt_dispute_closed_lost'),
        )->assertOk()->assertJson(['status' => 'success']);

        $fresh = $order->fresh();
        $this->assertSame('disputed', $fresh->status);
        $this->assertFalse(app(PurchaseService::class)->isOrderDownloadEligible($fresh));
    }

    public function test_warning_closed_dispute_keeps_the_order_disputed_and_download_blocked(): void
    {
        $secret = 'whsec_dispute_closed_warning';
        Config::set('services.stripe.webhook_secret', $secret);
        $order = $this->orderWithSnapshot('disputed', 'pi_dispute_closed_warning');

        $this->sendDisputeClosed(
            $secret,
            $this->disputeClosedPayload('pi_dispute_closed_warning', 'warning_closed', 'evt_dispute_closed_warning'),
        )->assertOk()->assertJson(['status' => 'success']);

        $fresh = $order->fresh();
        $this->assertSame('disputed', $fresh->status);
        $this->assertFalse(app(PurchaseService::class)->isOrderDownloadEligible($fresh));
    }

    public function test_won_dispute_cannot_undo_a_refund(): void
    {
        $secret = 'whsec_dispute_closed_refunded';
        Config::set('services.stripe.webhook_secret', $secret);
        $order = $this->orderWithSnapshot('refunded', 'pi_dispute_closed_refunded');

        $this->sendDisputeClosed(
            $secret,
            $this->disputeClosedPayload('pi_dispute_closed_refunded', 'won', 'evt_dispute_closed_refunded'),
        )->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('refunded', $fresh->status);
        $this->assertFalse(app(PurchaseService::class)->isOrderDownloadEligible($fresh));
    }

    public function test_won_dispute_leaves_a_non_disputed_order_untouched(): void
    {
        $secret = 'whsec_dispute_closed_pending';
        Config::set('services.stripe.webhook_secret', $secret);
        $order = $this->orderWithSnapshot('pending_payment', 'pi_dispute_closed_pending');

        $this->sendDisputeClosed(
            $secret,
            $this->disputeClosedPayload('pi_dispute_closed_pending', 'won', 'evt_dispute_closed_pending'),
        )->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('pending_payment', $fresh->status);
        $this->assertFalse(app(PurchaseService::class)->isOrderDownloadEligible($fresh));
    }

    public function test_closed_dispute_without_payment_intent_is_warned_and_ignored(): void
    {
        $secret = 'whsec_dispute_closed_null_pi';
        Config::set('services.stripe.webhook_secret', $secret);
        $order = $this->orderWithSnapshot('disputed', 'pi_dispute_closed_null_pi');
        Log::spy();

        $payload = [
            'id' => 'evt_dispute_closed_null_pi',
            'type' => 'charge.dispute.closed',
            'data' => ['object' => [
                'id' => 'dp_dispute_closed_null_pi',
                'payment_intent' => null,
                'status' => 'won',
            ]],
        ];

        $this->sendDisputeClosed($secret, $payload)->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame('disputed', $order->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'Webhook: closed dispute with null payment_intent, skipping')
            ->once();
    }

    public function test_closed_dispute_delivered_twice_changes_nothing_the_second_time(): void
    {
        $secret = 'whsec_dispute_closed_idempotent';
        Config::set('services.stripe.webhook_secret', $secret);
        $order = $this->orderWithSnapshot('disputed', 'pi_dispute_closed_idempotent');
        $payload = $this->disputeClosedPayload(
            'pi_dispute_closed_idempotent',
            'won',
            'evt_dispute_closed_idempotent',
        );

        $this->sendDisputeClosed($secret, $payload)->assertOk();
        $this->assertSame('paid', $order->fresh()->status);

        // Re-seed the cache after the first delivery: the event claim must
        // short-circuit the duplicate before it touches the order or cache.
        $cacheKeys = $this->seedPurchaseCache($order->fresh());

        $this->sendDisputeClosed($secret, $payload)->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame('paid', $order->fresh()->status);
        foreach ($cacheKeys as $key) {
            $this->assertFalse(Cache::get($key), 'Duplicate delivery must not re-run the cache clearing.');
        }
    }

    public function test_unexpected_dispute_status_changes_nothing_and_still_answers_200(): void
    {
        $secret = 'whsec_dispute_closed_unknown';
        Config::set('services.stripe.webhook_secret', $secret);
        $order = $this->orderWithSnapshot('disputed', 'pi_dispute_closed_unknown');
        Log::spy();

        $this->sendDisputeClosed(
            $secret,
            $this->disputeClosedPayload('pi_dispute_closed_unknown', 'needs_response', 'evt_dispute_closed_unknown'),
        )->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame('disputed', $order->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'Webhook: closed dispute with unexpected status, no change applied')
            ->once();
    }
}
