<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-B11 (MEDIUM) — a manually supplied document number must be normalised,
 * well-formed and not collide with an already persisted invoice number.
 */
class ManualInvoiceNumberValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'TESTAT11']);
    }

    private function actingAsSuperAdmin(): string
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return auth('api')->login($superAdmin);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'invoice_number' => 'R-2026-999',
            'date' => '2026-04-14',
            'due_date' => 'Zahlbar sofort.',
            'type' => 'invoice',
            'customer_name' => 'Test Customer',
            'items' => [
                ['type' => 'item', 'description' => 'Service A', 'qty' => 1, 'price' => 100],
            ],
        ], $overrides);
    }

    public function test_duplicate_invoice_number_is_rejected(): void
    {
        $token = $this->actingAsSuperAdmin();

        $order = Order::factory()->create();
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'R-2026-999',
            'customer_details' => ['name' => 'Existing', 'items' => []],
            'total_net' => 0,
            'total_gross' => 0,
            'tax_rate' => 0,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/invoices/manual', $this->payload(['invoice_number' => 'R-2026-999']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_number');
    }

    public function test_malformed_invoice_number_is_rejected(): void
    {
        $token = $this->actingAsSuperAdmin();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/invoices/manual', $this->payload(['invoice_number' => 'R 2026 #999']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_number');
    }

    public function test_unique_invoice_number_is_trimmed_and_accepted(): void
    {
        $token = $this->actingAsSuperAdmin();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/management/invoices/manual', $this->payload(['invoice_number' => '  R-2026-1000  ']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('R-2026-1000', (string) $response->headers->get('content-disposition'));
    }
}
