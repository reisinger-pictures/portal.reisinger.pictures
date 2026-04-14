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
        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_bic'], ['value' => 'TESTAT11']);
    }

    public function test_super_admin_can_generate_manual_document_with_discounts()
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => 'super_admin']));
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
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
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
}
