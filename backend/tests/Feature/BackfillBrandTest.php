<?php
namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillBrandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * B-01 F1: the backfill command runs in a CLI context where config('app.brand') is empty.
     * It must hard-default to B2B, never write null/empty.
     */
    public function test_backfill_command_sets_b2b_default_in_cli_context(): void
    {
        // Simulate the CLI/queue situation: brand config is empty.
        config(['app.brand' => null]);

        // Insert rows with NULL brand (raw, to bypass the model default).
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'brand' => null,
            'total_amount' => 100,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-2026-9001',
            'brand' => null,
            'customer_details' => ['name' => 'Legacy', 'items' => []],
            'total_net' => 100,
            'total_gross' => 100,
            'tax_rate' => 0,
        ]);

        $this->artisan('app:backfill-brand')
            ->expectsOutputToContain('Orders backfilled: 1')
            ->expectsOutputToContain('InvoiceSnapshots backfilled: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'brand' => Brand::B2B->value]);
        $this->assertDatabaseHas('invoice_snapshots', ['invoice_number' => 'P-2026-9001', 'brand' => Brand::B2B->value]);

        // Crucially, the empty runtime config did NOT leak into the written rows.
        $this->assertSame(null, config('app.brand'));
    }

    public function test_backfill_command_is_idempotent(): void
    {
        config(['app.brand' => null]);
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'brand' => Brand::B2B->value,
            'total_amount' => 100,
        ]);

        $this->artisan('app:backfill-brand')->assertSuccessful();

        // Already set → untouched, still B2B.
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'brand' => Brand::B2B->value]);
    }
}
