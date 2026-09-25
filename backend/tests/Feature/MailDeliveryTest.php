<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\InvoiceMail;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceMailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MailpitAssertions;
use Tests\TestCase;

#[Group('mailpit')]
class MailDeliveryTest extends TestCase
{
    use MailpitAssertions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_invite_email_is_sent_to_mailpit()
    {
        $gallery = Gallery::factory()->create(['name' => 'Sommerfest']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $admin->galleries()->attach($gallery);

        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/galleries/'.$gallery->id.'/invites/send', [
                'email' => 'kunde@example.com',
                'name' => 'Max Mustermann',
            ]);

        $response->assertStatus(200);

        $messages = $this->getMailpitMessagesByRecipient('kunde@example.com');
        $matching = array_filter($messages, fn ($m) => str_contains($m['Subject'] ?? '', 'Sommerfest'));
        $this->assertNotEmpty($matching, 'E-Mail an kunde@example.com mit Betreff "Sommerfest" nicht in Mailpit gefunden');
    }

    public function test_invoice_email_has_pdf_attachment_with_bank_details()
    {
        Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'Test Bank Inhaber']);

        $user = User::factory()->create(['email' => 'invoice@example.com']);
        $order = Order::create(['user_id' => $user->id, 'status' => 'paid', 'total_amount' => 100]);
        $snapshot = InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'RE-1234',
            'customer_details' => ['name' => 'Kunde', 'street' => 'Teststreet 1', 'zip' => '1234', 'city' => 'Testcity', 'country' => 'Austria', 'email' => 'invoice@example.com', 'items' => []],
            'total_net' => 100,
            'total_gross' => 100,
            'tax_rate' => 0,
        ]);

        Mail::to($user->email)->send(new InvoiceMail($order, $snapshot, ['Zusatzdokument.pdf' => 'dummy-pdf-content']));

        $attachments = $this->assertMailpitAttachmentExists(
            'invoice@example.com',
            expectedFilename: 'RE-1234.pdf',
            expectedMimeType: 'application/pdf',
        );

        $this->assertCount(2, $attachments, 'Es sollten exakt 2 Attachments existieren (Rechnung + Zusatzdokument).');
        $this->assertEquals('RE-1234.pdf', $attachments[0]['FileName']);
        $this->assertEquals('application/pdf', $attachments[0]['ContentType']);
        $this->assertGreaterThan(0, $attachments[0]['Size']);
        $this->assertEquals('Zusatzdokument.pdf', $attachments[1]['FileName']);
    }

    public function test_invoice_dispatch_claim_allows_one_real_mailpit_delivery_and_replay_does_not_enqueue_again(): void
    {
        $email = 'invoice-claim-'.Str::uuid().'@example.test';
        foreach ([
            'bank_holder' => 'Test Bank Inhaber',
            'bank_iban' => 'AT123456789',
            'bank_bic' => 'BICTEST',
        ] as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key, 'brand' => Brand::B2B->value],
                ['value' => $value],
            );
        }

        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'email' => $email,
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'brand' => Brand::B2B,
            'status' => 'paid',
            'total_amount' => 100,
        ]);
        $snapshot = InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'MAILPIT-'.Str::upper(Str::random(8)),
            'brand' => Brand::B2B,
            'customer_details' => [
                'name' => 'Mailpit Customer',
                'street' => 'Teststreet 1',
                'zip' => '1234',
                'city' => 'Vienna',
                'country' => 'Austria',
                'email' => $email,
                'items' => [],
            ],
            'total_net' => 100,
            'total_gross' => 100,
            'tax_rate' => 0,
        ]);

        $dispatcher = app(InvoiceMailDispatcher::class);
        $this->assertTrue($dispatcher->queueOnce($order, $user));
        $this->assertFalse($dispatcher->queueOnce($order, $user));

        // This observes one delivery in this isolated run; it is not a
        // universal exactly-once SMTP guarantee. The durable claim contract is
        // at-most-once enqueue and is covered separately by the fake/queue
        // fault-injection tests.
        $messages = $this->getMailpitMessagesByRecipient($email);
        $this->assertCount(1, $messages);
        $message = $this->getMailpitMessageByEmail($email);
        $this->assertNotNull($message);
        $this->assertStringContainsString(
            $snapshot->invoice_number,
            (string) ($message['Subject'] ?? ''),
        );
    }
}
