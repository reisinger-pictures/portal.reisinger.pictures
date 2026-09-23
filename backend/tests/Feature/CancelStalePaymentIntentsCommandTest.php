<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Services\StripePaymentService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class CancelStalePaymentIntentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_cancels_only_stale_incomplete_intents_and_never_processing_or_succeeded(): void
    {
        $cancelable = $this->staleOrder('pi_cancelable');
        $succeeded = $this->staleOrder('pi_succeeded');
        $processing = $this->staleOrder('pi_processing');
        $alreadyCanceled = $this->staleOrder('pi_already_canceled');
        $fresh = Order::factory()->create([
            'status' => 'pending_payment',
            'stripe_payment_intent_id' => 'pi_fresh',
            'created_at' => now()->subMinutes(30),
        ]);

        $intents = [
            'pi_cancelable' => $this->paymentIntent('pi_cancelable', 'requires_action', $cancelable),
            'pi_succeeded' => $this->paymentIntent('pi_succeeded', 'succeeded', $succeeded),
            'pi_processing' => $this->paymentIntent('pi_processing', 'processing', $processing),
            'pi_already_canceled' => $this->paymentIntent('pi_already_canceled', 'canceled', $alreadyCanceled),
        ];

        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->exactly(4))
            ->method('retrievePaymentIntent')
            ->willReturnCallback(fn (string $paymentIntentId): array => $intents[$paymentIntentId]);
        $stripePayment->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_cancelable')
            ->willReturn($this->paymentIntent('pi_cancelable', 'canceled', $cancelable));
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntentWithFee')
            ->with('pi_succeeded')
            ->willReturn(null);
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->artisan('stripe:cancel-stale-payment-intents')->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $cancelable->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('orders', ['id' => $alreadyCanceled->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('orders', ['id' => $succeeded->id, 'status' => 'paid']);
        $this->assertDatabaseHas('orders', ['id' => $processing->id, 'status' => 'pending_payment']);
        $this->assertDatabaseHas('orders', ['id' => $fresh->id, 'status' => 'pending_payment']);
    }

    public function test_remote_succeeded_candidate_is_reconciled_and_paid_with_fee_and_mail(): void
    {
        Mail::fake();
        $user = \App\Models\User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 1000,
            'stripe_payment_intent_id' => 'pi_succeeded_reconcile',
            'checkout_idempotency_key' => 'checkout-succeeded-reconcile',
            'checkout_fingerprint' => str_repeat('9', 64),
            'payment_intent_generation' => 4,
            'created_at' => now()->subHours(3),
        ]);
        InvoiceSnapshot::factory()->for($order)->create();

        $remote = $this->paymentIntent('pi_succeeded_reconcile', 'succeeded', $order);
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_succeeded_reconcile')
            ->willReturn($remote);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntentWithFee')
            ->with('pi_succeeded_reconcile')
            ->willReturn(42);
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->artisan('stripe:cancel-stale-payment-intents')->assertExitCode(0);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'stripe_payment_intent_id' => 'pi_succeeded_reconcile',
            'stripe_fee_cents' => 42,
            'payment_intent_generation' => 4,
        ]);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_old_order_row_does_not_cancel_a_fresh_replacement_intent(): void
    {
        $order = $this->staleOrder('pi_fresh_replacement');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_fresh_replacement')
            ->willReturn($this->paymentIntent(
                'pi_fresh_replacement',
                'requires_action',
                $order,
                now()->subMinutes(10)->getTimestamp(),
            ));
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->artisan('stripe:cancel-stale-payment-intents')->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    public function test_failure_is_logged_without_exception_message_or_client_secret(): void
    {
        $order = $this->staleOrder('pi_failure');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->willThrowException(new RuntimeException('client_secret=pi_secret_must_not_be_logged'));
        $this->app->instance(StripePaymentService::class, $stripePayment);
        Log::spy();

        $this->artisan('stripe:cancel-stale-payment-intents')->assertExitCode(1);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($order): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'stripe.stale_payment_intent.failed'
                    && $context['order_id'] === (string) $order->getKey()
                    && $context['exception_type'] === RuntimeException::class
                    && ! str_contains($encoded, 'client_secret')
                    && ! str_contains($encoded, 'pi_secret_must_not_be_logged');
            })
            ->once();
    }

    public function test_limit_bounds_the_scan_and_null_payment_intents_are_logged_without_remote_calls(): void
    {
        $missingIntent = Order::factory()->create(['status' => 'pending_payment']);
        Order::query()->whereKey($missingIntent->getKey())->update([
            'created_at' => now()->subHours(3),
            'stripe_payment_intent_id' => null,
        ]);
        $notInspected = Order::factory()->create([
            'status' => 'pending_payment',
            'stripe_payment_intent_id' => 'pi_beyond_limit',
        ]);
        Order::query()->whereKey($notInspected->getKey())->update(['created_at' => now()->subHours(2)]);

        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->never())->method('retrievePaymentIntent');
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);
        Log::spy();

        $this->artisan('stripe:cancel-stale-payment-intents', ['--limit' => 1])
            ->assertExitCode(0);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($missingIntent): bool {
                return $message === 'stripe.stale_payment_intent.missing_payment_intent'
                    && $context['order_id'] === (string) $missingIntent->getKey();
            })
            ->once();
        $this->assertDatabaseHas('orders', ['id' => $missingIntent->id, 'status' => 'pending_payment']);
        $this->assertDatabaseHas('orders', ['id' => $notInspected->id, 'status' => 'pending_payment']);
    }

    public function test_matching_v036_remote_identity_allows_cancel(): void
    {
        $order = Order::factory()->create([
            'status' => 'pending_payment',
            'total_amount' => 1000,
            'stripe_payment_intent_id' => 'pi_v036_matching',
            'checkout_idempotency_key' => 'checkout-v036-matching',
            'checkout_fingerprint' => str_repeat('e', 64),
            'payment_intent_generation' => 6,
            'created_at' => now()->subHours(3),
        ]);
        $remote = $this->paymentIntent('pi_v036_matching', 'requires_action', $order);

        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_v036_matching')
            ->willReturn($remote);
        $stripePayment->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_v036_matching')
            ->willReturn(['id' => 'pi_v036_matching', 'status' => 'canceled']);
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->artisan('stripe:cancel-stale-payment-intents')->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_v036_remote_metadata_is_required_before_cancel(): void
    {
        $order = Order::factory()->create([
            'status' => 'pending_payment',
            'total_amount' => 1000,
            'stripe_payment_intent_id' => 'pi_v036_missing_metadata',
            'checkout_idempotency_key' => 'checkout-v036-missing-metadata',
            'checkout_fingerprint' => str_repeat('d', 64),
            'payment_intent_generation' => 2,
            'created_at' => now()->subHours(3),
        ]);
        $remote = $this->paymentIntent('pi_v036_missing_metadata', 'requires_action', $order);
        $remote['metadata'] = [
            'order_id' => (string) $order->id,
            'checkout_idempotency_key' => $order->checkout_idempotency_key,
            'checkout_fingerprint' => $order->checkout_fingerprint,
            'generation' => (string) $order->payment_intent_generation,
        ];

        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_v036_missing_metadata')
            ->willReturn($remote);
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->artisan('stripe:cancel-stale-payment-intents')->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    public function test_v036_remote_customer_mismatch_is_skipped_without_cancel(): void
    {
        $user = \App\Models\User::factory()->create(['stripe_customer_id' => 'cus_command_expected']);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 1000,
            'stripe_payment_intent_id' => 'pi_v036_customer_mismatch',
            'checkout_idempotency_key' => 'checkout-v036-customer-mismatch',
            'checkout_fingerprint' => str_repeat('8', 64),
            'payment_intent_generation' => 2,
            'created_at' => now()->subHours(3),
        ]);
        $remote = $this->paymentIntent('pi_v036_customer_mismatch', 'requires_action', $order);
        $remote['customer'] = 'cus_command_attacker';

        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_v036_customer_mismatch')
            ->willReturn($remote);
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->artisan('stripe:cancel-stale-payment-intents')->assertExitCode(0);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
        ]);
    }

    public function test_corrupted_remote_pi_linkage_is_skipped_without_cancel(): void
    {
        $idMismatch = Order::factory()->create([
            'status' => 'pending_payment',
            'total_amount' => 1000,
            'stripe_payment_intent_id' => 'pi_stored_id',
            'checkout_idempotency_key' => 'checkout-linkage-id',
            'checkout_fingerprint' => str_repeat('a', 64),
            'payment_intent_generation' => 3,
            'created_at' => now()->subHours(3),
        ]);
        $metadataMismatch = Order::factory()->create([
            'status' => 'pending_payment',
            'total_amount' => 1000,
            'stripe_payment_intent_id' => 'pi_stored_metadata',
            'checkout_idempotency_key' => 'checkout-linkage-metadata',
            'checkout_fingerprint' => str_repeat('b', 64),
            'payment_intent_generation' => 4,
            'created_at' => now()->subHours(3),
        ]);

        $remoteIdMismatch = $this->paymentIntent('pi_remote_other_id', 'requires_action', $idMismatch);
        $remoteMetadataMismatch = $this->paymentIntent('pi_stored_metadata', 'requires_action', $metadataMismatch);
        $remoteMetadataMismatch['metadata']['checkout_fingerprint'] = str_repeat('c', 64);

        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->exactly(2))
            ->method('retrievePaymentIntent')
            ->willReturnCallback(fn (string $paymentIntentId): array => match ($paymentIntentId) {
                'pi_stored_id' => $remoteIdMismatch,
                'pi_stored_metadata' => $remoteMetadataMismatch,
                default => throw new RuntimeException('unexpected PaymentIntent id'),
            });
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);
        Log::spy();

        $this->artisan('stripe:cancel-stale-payment-intents')->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $idMismatch->id, 'status' => 'pending_payment']);
        $this->assertDatabaseHas('orders', ['id' => $metadataMismatch->id, 'status' => 'pending_payment']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'stripe.stale_payment_intent.remote_identity_mismatch'
                && in_array($context['order_id'], [(string) $idMismatch->id, (string) $metadataMismatch->id], true))
            ->twice();
    }

    public function test_replacement_generation_prevents_final_local_cancellation(): void
    {
        $order = $this->staleOrder('pi_old_generation');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_old_generation')
            ->willReturnCallback(function () use ($order): array {
                Order::query()->whereKey($order->getKey())->update([
                    'stripe_payment_intent_id' => 'pi_replacement_generation',
                    'payment_intent_generation' => 2,
                ]);

                return $this->paymentIntent(
                    'pi_old_generation',
                    'requires_action',
                    $order,
                    now()->subHours(3)->getTimestamp(),
                );
            });
        $stripePayment->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_old_generation')
            ->willReturn(['id' => 'pi_old_generation', 'status' => 'canceled']);
        $this->app->instance(StripePaymentService::class, $stripePayment);
        Log::spy();

        $this->artisan('stripe:cancel-stale-payment-intents')->assertExitCode(0);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
            'stripe_payment_intent_id' => 'pi_replacement_generation',
            'payment_intent_generation' => 2,
        ]);
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message): bool => $message === 'stripe.stale_payment_intent.identity_changed_before_local_update')
            ->once();
    }

    public function test_legacy_order_can_use_remote_pi_without_metadata(): void
    {
        $order = $this->staleOrder('pi_legacy_missing_metadata');
        $remote = $this->paymentIntent('pi_legacy_missing_metadata', 'requires_action', $order);
        $remote['metadata'] = [];

        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_legacy_missing_metadata')
            ->willReturn($remote);
        $stripePayment->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_legacy_missing_metadata')
            ->willReturn([
                'id' => 'pi_legacy_missing_metadata',
                'status' => 'canceled',
            ]);
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->artisan('stripe:cancel-stale-payment-intents')->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_command_is_scheduled_hourly_with_single_server_guards(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'stripe:cancel-stale-payment-intents'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    private function staleOrder(string $paymentIntentId): Order
    {
        return Order::factory()->create([
            'status' => 'pending_payment',
            'total_amount' => 1000,
            'stripe_payment_intent_id' => $paymentIntentId,
            'created_at' => now()->subHours(3),
        ]);
    }

    /**
     * @return array{
     *     id: string,
     *     status: string,
     *     client_secret: ?string,
     *     amount: int,
     *     currency: string,
     *     metadata: array<string, string|null>
     * }
     */
    private function paymentIntent(string $id, string $status, Order $order, ?int $created = null): array
    {
        $metadata = ['order_id' => (string) $order->getKey()];
        if ($order->checkout_idempotency_key !== null || $order->checkout_fingerprint !== null) {
            $metadata += [
                'portal_user_id' => (string) $order->user_id,
                'account_created_at' => (string) $order->user->created_at->getTimestamp(),
                'checkout_idempotency_key' => $order->checkout_idempotency_key,
                'checkout_fingerprint' => $order->checkout_fingerprint,
                'generation' => (string) $order->payment_intent_generation,
                'amount_cents' => (string) $order->total_amount,
                'currency' => 'eur',
            ];
        }

        return [
            'id' => $id,
            'status' => $status,
            'client_secret' => $id.'_secret_test',
            'amount' => (int) $order->total_amount,
            'amount_received' => (int) $order->total_amount,
            'currency' => 'eur',
            'created' => $created ?? now()->subHours(3)->getTimestamp(),
            'metadata' => $metadata,
        ];
    }
}
