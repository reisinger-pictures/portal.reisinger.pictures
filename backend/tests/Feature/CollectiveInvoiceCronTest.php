<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Org;
use App\Models\User;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\MailpitAssertions;

#[\PHPUnit\Framework\Attributes\Group('mailpit')]
class CollectiveInvoiceCronTest extends TestCase
{
    use RefreshDatabase, MailpitAssertions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_cron_generates_collective_invoices_at_end_of_month_with_pdf()
    {
        $org = Org::create(['name' => 'Test Org', 'invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['email' => 'tenant-accounting@example.com']);
        $user->org_id = $org->id;
        $user->save();

        $order = Order::create([
            'user_id' => $user->id, 
            'status' => 'delivery_note', 
            'total_amount' => 100
        ]);
        
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'L-2026-0001',
            'customer_details' => ['name' => 'Kunde', 'items' => []],
            'total_net' => 100,
            'total_gross' => 100,
            'tax_rate' => 0
        ]);

        // Sprung zum Monatsende
        Carbon::setTestNow(Carbon::now()->endOfMonth());

        // Flow AC: Run Cron Command (Löst echten E-Mail-Versand in Queue aus)
        $this->artisan('app:process-collective-invoices')->assertExitCode(0);

        $order->refresh();
        $this->assertEquals('archived_in_collective', $order->status);
        $this->assertDatabaseHas('orders', [
            'status' => 'invoice_created', 
            'total_amount' => 100
        ]);
        
        // Warteschlange abarbeiten, damit die InvoiceMail verschickt wird
        Artisan::call('queue:work', ['--stop-when-empty' => true]);

        // Mailpit via API abfragen — recipient-spezifisch, nicht global
        $this->assertMailpitAttachmentExists(
            'tenant-accounting@example.com',
            expectedMimeType: 'application/pdf',
        );

        Carbon::setTestNow();
    }
}
