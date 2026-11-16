<?php

namespace Tests\Feature;

use App\Models\InvoiceSnapshot;
use App\Models\MolliePaymentAttempt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The E2E teardown route is the only thing that removes a test buyer again.
 *
 * `mollie_payment_attempts.order_id` is `on delete restrict`, so a paid Mollie
 * order cannot be deleted before its attempt. The route used to delete the
 * invoice snapshot first and then fail on the order, which left a paid order
 * behind without its invoice evidence and kept the user alive — the exact state
 * a manual #55 run produced (order `01a1e534-62de…`, deleting it raised
 * `FOREIGN KEY constraint failed`). This test pins the complete, atomic
 * teardown: every row the buyer produced is gone, and the route answers 200.
 */
class TestCleanupUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_removes_a_paid_mollie_order_with_its_attempt_and_invoice(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'paid',
            'total_amount' => 4500,
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-2026-9001',
            'customer_details' => ['name' => 'Teardown'],
            'total_net' => 4500,
            'total_gross' => 4500,
            'tax_rate' => null,
        ]);

        DB::table('mollie_payment_attempts')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'generation' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'mollie_payment_id' => 'tr_Teardown1234567890',
            'mode' => 'test',
            'credential_fingerprint' => hash('sha256', 'mollie-key|test_teardown'),
            'amount_cents' => 4500,
            'currency' => 'EUR',
            'status' => 'paid',
            'snapshot' => '{}',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson('/api/test/cleanup-user/'.$user->id)->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('invoice_snapshots', ['order_id' => $order->id]);
        $this->assertSame(0, MolliePaymentAttempt::where('order_id', $order->id)->count());
    }
}
