<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;

class CollectiveInvoiceCronTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::delete('http://127.0.0.1:8026/api/v1/messages');
    }

    public function test_cron_generates_collective_invoices_at_end_of_month_with_pdf()
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['email' => 'tenant-accounting@example.com']);
        $user->tenants()->attach($tenant);

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

        // Mailpit via API abfragen
        $mailpitResponse = Http::get('http://127.0.0.1:8026/api/v1/messages');
        $messages = $mailpitResponse->json('messages');
        $this->assertGreaterThan(0, count($messages), 'Es wurde keine E-Mail an Mailpit versendet.');
        
        // Details der ersten Mail abrufen, um Attachments zu prüfen
        $messageId = $messages[0]['ID'];
        $mailDetails = Http::get("http://127.0.0.1:8026/api/v1/message/{$messageId}");
        $attachments = $mailDetails->json('Attachments');
        
        $this->assertNotEmpty($attachments, 'Die Rechnungs-E-Mail hat keinen Anhang.');
        $this->assertStringEndsWith('.pdf', $attachments[0]['FileName'], 'Der Anhang ist kein PDF.');

        Carbon::setTestNow();
    }
}
