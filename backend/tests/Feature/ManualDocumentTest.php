<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ManualDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Erforderliche Stammdaten für den PDF-Header/Footer
        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'TESTAT11']);
    }

    public function test_super_admin_can_generate_manual_document_with_discounts()
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::SUPER_ADMIN->value]));
        $token = auth('api')->login($superAdmin);

        $payload = [
            'invoice_number' => 'R-2026-999',
            'date' => '2026-04-14',
            'due_date' => 'Zahlbar sofort.',
            'type' => 'invoice',
            'customer_name' => 'Test Customer',
            'items' => [
                ['type' => 'item', 'description' => 'Service A', 'qty' => 2, 'price' => 50], // = 100
                ['type' => 'discount_percent', 'description' => '10% Off', 'qty' => 1, 'price' => 10], // = -10
                ['type' => 'discount_fixed', 'description' => 'Bonus', 'qty' => 1, 'price' => 5], // = -5 -> Total: 85
            ]
        ];

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->postJson('/api/management/invoices/manual', $payload);

        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_normal_admin_cannot_generate_manual_document()
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->postJson('/api/management/invoices/manual', [
                 'invoice_number' => 'R-123',
                 'date' => '2026-04-14',
                 'due_date' => 'Sofort',
                 'items' => [['type' => 'item', 'description' => 'A', 'qty' => 1, 'price' => 10]]
             ])
             ->assertStatus(403);
    }

    public function test_smart_document_can_be_generated_and_extracted_securely()
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::SUPER_ADMIN->value]));
        $token = auth('api')->login($superAdmin);

        $payload = [
            'invoice_number' => 'A-2026-999',
            'date' => '2026-04-14',
            'due_date' => '14 Tage',
            'type' => 'offer',
            'customer_name' => 'Smart Doc Tester',
            'customer_email' => 'smart@doc.test',
            'items' => [
                ['type' => 'item', 'description' => 'Consulting', 'qty' => 10, 'price' => 100],
                ['type' => 'discount_fixed', 'description' => 'Rabatt', 'qty' => 1, 'price' => 50],
            ],
            'terms_html' => '<p>Test Terms</p>'
        ];

        // 1. Generate Offer PDF
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->postJson('/api/management/invoices/manual', $payload);

        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/pdf');

        // Capture the streamed PDF content
        ob_start();
        $res->sendContent();
        $pdfContent = ob_get_clean();

        // Ensure the smart doc signature is appended
        $this->assertStringContainsString('%SMART_DOC:', $pdfContent);

        // 2. Extract Offer
        $tempPath = storage_path('app/private/temp/test_smart_doc_' . uniqid() . '.pdf');
        if (!is_dir(dirname($tempPath))) mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, $pdfContent);

        $uploadedFile = new \Illuminate\Http\UploadedFile(
            $tempPath,
            'Angebot.pdf',
            'application/pdf',
            null,
            true
        );

        $extractRes = $this->withHeaders(['Authorization' => "Bearer $token"])
                           ->post('/api/management/invoices/extract-offer', [
                               'pdf' => $uploadedFile
                           ]);

        $extractRes->assertStatus(200);
        $extractRes->assertJsonPath('customer_name', 'Smart Doc Tester');
        $extractRes->assertJsonPath('customer_email', 'smart@doc.test');
        $extractRes->assertJsonPath('items.0.description', 'Consulting');
        $extractRes->assertJsonPath('items.1.price', 50);

        // 3. Tamper with the PDF to test signature validation
        $tamperedContent = preg_replace('/%SMART_DOC:(.*?)\.(.*?)%/', '%SMART_DOC:$1.invalid_signature%', $pdfContent);
        file_put_contents($tempPath, $tamperedContent);

        $tamperedFile = new \Illuminate\Http\UploadedFile(
            $tempPath,
            'Angebot_tampered.pdf',
            'application/pdf',
            null,
            true
        );

        $tamperedRes = $this->withHeaders(['Authorization' => "Bearer $token"])
                            ->post('/api/management/invoices/extract-offer', [
                                'pdf' => $tamperedFile
                            ]);

        $tamperedRes->assertStatus(400);
        $tamperedRes->assertJsonPath('error', 'Signatur ungültig oder manipuliert. Das Angebot wurde eventuell verändert.');

        @unlink($tempPath);
    }
}
