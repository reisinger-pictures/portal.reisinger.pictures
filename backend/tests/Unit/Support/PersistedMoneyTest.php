<?php

namespace Tests\Unit\Support;

use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Support\PersistedMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PersistedMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_ceiling_allows_the_boundary_for_both_persisted_money_models(): void
    {
        $boundary = PersistedMoney::MAX_CENTS;
        $order = Order::create([
            'status' => 'pending',
            'total_amount' => $boundary,
        ]);
        $snapshot = InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'BOUNDARY-1',
            'customer_details' => [],
            'total_net' => $boundary,
            'total_gross' => $boundary,
        ]);

        $this->assertSame($boundary, $order->fresh()->total_amount);
        $this->assertSame($boundary, $snapshot->fresh()->total_net);
        $this->assertSame($boundary, $snapshot->fresh()->total_gross);
    }

    public function test_order_overflow_is_rejected_before_insert(): void
    {
        try {
            Order::create([
                'status' => 'pending',
                'total_amount' => PersistedMoney::MAX_CENTS + 1,
            ]);
            $this->fail('Expected the persisted order ceiling guard to reject the write.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_invoice_snapshot_overflow_is_rejected_before_insert(): void
    {
        $order = Order::create([
            'status' => 'pending',
            'total_amount' => 1,
        ]);

        try {
            InvoiceSnapshot::create([
                'order_id' => $order->id,
                'invoice_number' => 'OVERFLOW-1',
                'customer_details' => [],
                'total_net' => PersistedMoney::MAX_CENTS + 1,
                'total_gross' => 1,
            ]);
            $this->fail('Expected the persisted snapshot ceiling guard to reject the write.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('invoice_snapshots', 0);
            $this->assertDatabaseHas('orders', ['id' => $order->id]);
        }
    }
}
