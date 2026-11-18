<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CardTestingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backed_card_testing_fields_are_mass_assignable_and_cast(): void
    {
        $this->assertTrue(Schema::hasColumns('orders', [
            'checkout_idempotency_key',
            'checkout_fingerprint',
            'payment_failure_count',
            'last_payment_failure_at',
            'last_payment_decline_code',
        ]));

        $failureAt = now()->subMinutes(5)->startOfSecond();
        $order = Order::factory()->create([
            'checkout_idempotency_key' => 'checkout-key',
            'checkout_fingerprint' => hash('sha256', 'stable-checkout-fingerprint'),
            'payment_failure_count' => 3,
            'last_payment_failure_at' => $failureAt,
            'last_payment_decline_code' => 'do_not_honor',
        ]);

        $this->assertSame('checkout-key', $order->checkout_idempotency_key);
        $this->assertSame(64, strlen($order->checkout_fingerprint));
        $this->assertSame(3, $order->payment_failure_count);
        $this->assertTrue($order->last_payment_failure_at->equalTo($failureAt));
        $this->assertSame('do_not_honor', $order->last_payment_decline_code);
    }

    public function test_stale_selection_index_is_migration_backed(): void
    {
        $indexes = collect(Schema::getIndexes('orders'))
            ->keyBy('name')
            ->only(['orders_pending_stale_idx'])
            ->map(fn (array $index): array => [
                'columns' => $index['columns'],
                'unique' => $index['unique'],
            ]);

        $this->assertSame([
            'orders_pending_stale_idx' => [
                'columns' => ['status', 'created_at', 'id'],
                'unique' => false,
            ],
        ], $indexes->all());
    }

    public function test_v054_drop_is_a_noop_when_rerun(): void
    {
        // V036's re-run recovery of the Stripe columns is obsolete: V054 drops
        // them. The replacement contract is that re-running the drop stays a
        // no-op while the card-testing columns and indexes survive.
        $migration = require database_path('migrations/V054__drop_stripe_provider_columns.php');
        $migration->up();
        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'stripe_customer_id'));
        $this->assertFalse(Schema::hasIndex('users', 'users_stripe_customer_id_unique', 'unique'));
        $this->assertFalse(Schema::hasColumn('orders', 'stripe_payment_intent_id'));
        $this->assertFalse(Schema::hasIndex('orders', 'orders_stripe_payment_intent_idx'));
        $this->assertFalse(Schema::hasColumn('orders', 'stripe_fee_cents'));
        $this->assertFalse(Schema::hasColumn('payout_pools', 'stripe_fee_cents'));

        $this->assertTrue(Schema::hasColumns('orders', [
            'checkout_idempotency_key',
            'checkout_fingerprint',
            'payment_intent_generation',
            'payment_failure_count',
            'last_payment_failure_at',
            'last_payment_decline_code',
        ]));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_user_id_checkout_idempotency_key_unique', 'unique'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_user_fingerprint_lookup_idx'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_pending_stale_idx'));
    }

    public function test_schema_defaults_and_user_factory_account_age_states(): void
    {
        $establishedUser = User::factory()->create();
        $recentUser = User::factory()->recentAccount()->create();
        $order = Order::factory()->create(['user_id' => $establishedUser->id]);

        $this->assertTrue($establishedUser->created_at->lessThanOrEqualTo(now()->subDay()));
        $this->assertTrue($recentUser->created_at->greaterThan(now()->subHour()));
        $this->assertNull($order->checkout_idempotency_key);
        $this->assertNull($order->checkout_fingerprint);
        // `payment_intent_generation` is a historical online-payment column
        // with a database default. It is not part of the model defaults any
        // more, so assert the persisted value instead of the in-memory one.
        $order->refresh();
        $this->assertSame(1, (int) $order->payment_intent_generation);
        $this->assertSame(0, $order->payment_failure_count);
        $this->assertNull($order->last_payment_failure_at);
        $this->assertNull($order->last_payment_decline_code);
    }

    public function test_checkout_idempotency_key_is_unique_per_user(): void
    {
        $user = User::factory()->create();
        Order::factory()->create([
            'user_id' => $user->id,
            'checkout_idempotency_key' => 'same-key',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Order::factory()->create([
            'user_id' => $user->id,
            'checkout_idempotency_key' => 'same-key',
        ]);
    }
}
