<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CardTestingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backed_card_testing_fields_are_mass_assignable_and_cast(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'stripe_customer_id'));
        $this->assertTrue(Schema::hasColumns('orders', [
            'checkout_idempotency_key',
            'checkout_fingerprint',
            'payment_intent_generation',
            'payment_failure_count',
            'last_payment_failure_at',
            'last_payment_decline_code',
        ]));

        $failureAt = now()->subMinutes(5)->startOfSecond();
        $order = Order::factory()->create([
            'checkout_idempotency_key' => 'checkout-key',
            'checkout_fingerprint' => hash('sha256', 'stable-checkout-fingerprint'),
            'payment_intent_generation' => 4,
            'payment_failure_count' => 3,
            'last_payment_failure_at' => $failureAt,
            'last_payment_decline_code' => 'do_not_honor',
        ]);

        $this->assertSame('checkout-key', $order->checkout_idempotency_key);
        $this->assertSame(64, strlen($order->checkout_fingerprint));
        $this->assertSame(4, $order->payment_intent_generation);
        $this->assertSame(3, $order->payment_failure_count);
        $this->assertTrue($order->last_payment_failure_at->equalTo($failureAt));
        $this->assertSame('do_not_honor', $order->last_payment_decline_code);
    }

    public function test_payment_intent_and_stale_selection_indexes_are_migration_backed(): void
    {
        $paymentIndexes = collect(Schema::getIndexes('orders'))
            ->keyBy('name')
            ->only([
                'orders_stripe_payment_intent_idx',
                'orders_pending_stale_idx',
            ])
            ->sortKeys()
            ->map(fn (array $index): array => [
                'columns' => $index['columns'],
                'unique' => $index['unique'],
            ]);

        $this->assertSame([
            'orders_pending_stale_idx' => [
                'columns' => ['status', 'created_at', 'id'],
                'unique' => false,
            ],
            'orders_stripe_payment_intent_idx' => [
                'columns' => ['stripe_payment_intent_id'],
                'unique' => false,
            ],
        ], $paymentIndexes->all());
    }

    public function test_v036_rerun_is_a_noop_and_recovers_a_partial_index_commit(): void
    {
        $migration = require database_path('migrations/V036__card_testing_defenses.php');

        // RefreshDatabase already applied V036 once. Model the MariaDB
        // auto-commit boundary where all columns are committed but a later
        // index statement failed: a retry must tolerate the columns and only
        // recreate what is missing.
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_stripe_customer_id_unique');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_stripe_payment_intent_idx');
            $table->dropUnique('orders_user_id_checkout_idempotency_key_unique');
        });

        $migration->up();
        // A second, fully satisfied retry must be a pure no-op.
        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'stripe_customer_id'));
        $this->assertTrue(Schema::hasIndex('users', 'users_stripe_customer_id_unique', 'unique'));
        $this->assertTrue(Schema::hasColumns('orders', [
            'checkout_idempotency_key',
            'checkout_fingerprint',
            'payment_intent_generation',
            'payment_failure_count',
            'last_payment_failure_at',
            'last_payment_decline_code',
        ]));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_user_id_checkout_idempotency_key_unique', 'unique'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_stripe_payment_intent_idx'));
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
        $this->assertSame(1, $order->payment_intent_generation);
        $this->assertSame(0, $order->payment_failure_count);
        $this->assertNull($order->last_payment_failure_at);
        $this->assertNull($order->last_payment_decline_code);
    }

    public function test_stripe_customer_id_is_unique(): void
    {
        User::factory()->create(['stripe_customer_id' => 'cus_unique']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['stripe_customer_id' => 'cus_unique']);
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
