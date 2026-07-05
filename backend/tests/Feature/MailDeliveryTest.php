<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MailpitAssertions;

class MailDeliveryTest extends TestCase
{
    use RefreshDatabase, MailpitAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearMailpit();
    }

    public function test_invite_email_is_sent_to_mailpit()
    {
        $gallery = Gallery::factory()->create(['name' => 'Sommerfest']);
        $admin = User::factory()->create();
        $admin->roles()->attach(\App\Models\Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $admin->galleries()->attach($gallery);
        
        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/galleries/' . $gallery->id . '/invites/send', [
                'email' => 'kunde@example.com',
                'name' => 'Max Mustermann'
            ]);

        $response->assertStatus(200);

        $message = $this->getMailpitMessageByEmail('kunde@example.com');
        $this->assertNotNull($message, 'E-Mail an kunde@example.com nicht in Mailpit gefunden');
        $this->assertStringContainsString('Sommerfest', $message['Subject']);
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

        $attachments = $this->assertMailpitAttachmentExists('invoice@example.com');

        $this->assertCount(2, $attachments, 'Es sollten exakt 2 Attachments existieren (Rechnung + Zusatzdokument).');
        $this->assertEquals('RE-1234.pdf', $attachments[0]['FileName']);
        $this->assertEquals('application/pdf', $attachments[0]['ContentType']);
        $this->assertGreaterThan(0, $attachments[0]['Size']);
        $this->assertEquals('Zusatzdokument.pdf', $attachments[1]['FileName']);
    }
}
