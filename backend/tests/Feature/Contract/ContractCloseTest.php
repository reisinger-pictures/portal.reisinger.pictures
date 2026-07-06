<?php

namespace Tests\Feature\Contract;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\Setting;
use App\Enums\Brand;
use App\Support\BrandRegistry;
use Tests\Support\MailpitAssertions;

#[\PHPUnit\Framework\Attributes\Group('mailpit')]
class ContractCloseTest extends TestCase
{
    use RefreshDatabase, MailpitAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'RP Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'RPBIC']);
    }

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'super_admin']);
        $user->roles()->attach($role);
        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = auth('api')->login($user);
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_close_contract_sends_emails_to_signers(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'billing_details' => null,
        ]);

        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'email' => 'signer1@example.com',
            'status' => 'signed',
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'email' => 'signer2@example.com',
            'status' => 'joined',
        ]);

        $response = $this->withHeaders($headers)
            ->postJson("/api/management/contracts/{$contract->id}/close");

        $response->assertStatus(200);

        $this->assertMailpitSentTo('signer1@example.com');
        $this->assertMailpitSentTo('signer2@example.com');

        $this->assertMailpitAttachmentExists(
            'signer1@example.com',
            expectedMimeType: 'application/pdf',
        );
    }

    public function test_close_with_items_creates_auto_invoice(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [
                ['type' => 'item', 'description' => 'Test', 'qty' => 1, 'price' => 10000, 'notes' => ''],
            ],
            'billing_details' => [
                'name' => 'Test Kunde',
                'email' => 'kunde@example.com',
            ],
        ]);

        $response = $this->withHeaders($headers)
            ->postJson("/api/management/contracts/{$contract->id}/close");

        $response->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'brand' => 'rp',
            'status' => 'invoice_created',
        ]);

        $this->assertDatabaseHas('invoice_snapshots', [
            'brand' => 'rp',
            'total_gross' => 10000,
        ]);
    }

    public function test_close_without_items_does_not_create_invoice(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [],
            'discounts' => [],
            'billing_details' => null,
        ]);

        $response = $this->withHeaders($headers)
            ->postJson("/api/management/contracts/{$contract->id}/close");

        $response->assertStatus(200);

        $this->assertDatabaseCount('orders', 0);
    }
}
