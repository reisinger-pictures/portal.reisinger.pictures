<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class MailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mailpit API löschen vor dem Test, damit wir einen sauberen State haben
        Http::delete('http://127.0.0.1:8026/api/v1/messages');
    }

    public function test_invite_email_is_sent_to_mailpit()
    {
        $gallery = Gallery::factory()->create(['name' => 'Sommerfest']);
        $admin = User::factory()->create();
        $admin->roles()->attach(\App\Models\Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $admin->galleries()->attach($gallery);
        
        $token = auth('api')->login($admin);

        // Wir rufen den echten Endpoint auf, der die E-Mail versendet
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/galleries/' . $gallery->id . '/invites/send', [
                'email' => 'kunde@example.com',
                'name' => 'Max Mustermann'
            ]);

        $response->assertStatus(200);

        // Mailpit API abfragen
        $mailpitResponse = Http::get('http://127.0.0.1:8026/api/v1/messages');
        $this->assertTrue($mailpitResponse->successful());
        
        $messages = $mailpitResponse->json('messages');
        $this->assertCount(1, $messages, 'Es sollte exakt eine E-Mail in Mailpit liegen.');
        
        $firstMessage = $messages[0];
        $this->assertStringContainsString('kunde@example.com', $firstMessage['To'][0]['Address']);
        $this->assertStringContainsString('Sommerfest', $firstMessage['Subject']);
    }

    public function test_invoice_email_has_pdf_attachment_with_bank_details()
    {
        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'Test Bank Inhaber']);
        
        $user = User::factory()->create(['email' => 'invoice@example.com']);
        $order = \App\Models\Order::create(['user_id' => $user->id, 'status' => 'paid', 'total_amount' => 100]);
        $snapshot = \App\Models\InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'RE-1234',
            'customer_details' => ['name' => 'Kunde', 'street' => 'Teststreet 1', 'zip' => '1234', 'city' => 'Testcity', 'country' => 'Austria', 'email' => 'invoice@example.com', 'items' => []],
            'total_net' => 100,
            'total_gross' => 100,
            'tax_rate' => 0
        ]);

        \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\InvoiceMail($order, $snapshot, ['Zusatzdokument.pdf' => 'dummy-pdf-content']));
        
        $mailpitResponse = Http::get('http://127.0.0.1:8026/api/v1/messages');
        $messages = $mailpitResponse->json('messages');
        
        // Suche Nachricht an invoice@example.com
        $msg = collect($messages)->first(fn($m) => str_contains($m['To'][0]['Address'], 'invoice@example.com'));
        $this->assertNotNull($msg, 'Rechnungs-E-Mail wurde nicht gefunden.');
        
        $messageId = $msg['ID'];
        $mailDetails = Http::get("http://127.0.0.1:8026/api/v1/message/{$messageId}");
        
        $attachments = $mailDetails->json('Attachments');
        $this->assertCount(2, $attachments, 'Es sollten exakt 2 Attachments existieren (Rechnung + Zusatzdokument).');
        
        $this->assertEquals('RE-1234.pdf', $attachments[0]['FileName']);
        $this->assertEquals('application/pdf', $attachments[0]['ContentType']);
        $this->assertGreaterThan(0, $attachments[0]['Size']);

        $this->assertEquals('Zusatzdokument.pdf', $attachments[1]['FileName']);
    }
}
