<?php

namespace Tests\Feature\Contract;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Mail;

class ContractControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'super_admin']);
        $user->roles()->attach($role);
        return $user;
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->attach($role);
        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = auth('api')->login($user);
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_index_filters_by_brand(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        Contract::factory()->create(['brand' => Brand::B2B]);
        Contract::factory()->create(['brand' => Brand::B2B]);
        Contract::factory()->create(['brand' => Brand::SRP]);

        $response = $this->withHeaders($headers)->getJson('/api/management/contracts');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    public function test_store_persists_brand(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $response = $this->withHeaders($headers)->postJson('/api/management/contracts', [
            'available_roles' => ['Model'],
            'terms_html' => '<p>Test</p>',
            'items' => [],
            'discounts' => [],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('contracts', [
            'brand' => 'rp',
            'status' => 'draft',
        ]);
    }

    public function test_non_super_admin_cannot_create(): void
    {
        $user = $this->createAdmin();
        $headers = $this->authHeaders($user);

        $response = $this->withHeaders($headers)->postJson('/api/management/contracts', [
            'available_roles' => ['Model'],
        ]);

        $response->assertStatus(403);
    }

    public function test_can_show_contract(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create(['brand' => Brand::B2B]);

        $response = $this->withHeaders($headers)->getJson("/api/management/contracts/{$contract->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('contract.id', $contract->id);
    }

    public function test_can_update_draft(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create(['status' => 'draft', 'brand' => Brand::B2B]);

        $response = $this->withHeaders($headers)->putJson("/api/management/contracts/{$contract->id}", [
            'available_roles' => ['Model', 'Fotograf'],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'available_roles' => json_encode(['Model', 'Fotograf']),
        ]);
    }

    public function test_cannot_update_active_contract_with_signed_signer(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);
        $contract->signers()->create(
            \App\Models\ContractSigner::factory()->make(['status' => 'signed', 'signed_at' => now()])->toArray()
        );

        $response = $this->withHeaders($headers)->putJson("/api/management/contracts/{$contract->id}", [
            'available_roles' => ['Model'],
        ]);

        $response->assertStatus(403);
    }

    public function test_open_contract_generates_join_link(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create(['status' => 'draft', 'brand' => Brand::B2B]);

        $response = $this->withHeaders($headers)->postJson("/api/management/contracts/{$contract->id}/open");
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('contract.status', 'active');
        $this->assertNotNull($response->json('contract.join_token'));
        $this->assertStringContainsString('/contracts/join/', $response->json('join_link'));
    }

    public function test_close_active_contract(): void
    {
        Mail::fake();

        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);

        $response = $this->withHeaders($headers)->postJson("/api/management/contracts/{$contract->id}/close");
        $response->assertStatus(200);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'closed',
        ]);
    }

    public function test_cannot_close_draft_contract(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create(['status' => 'draft', 'brand' => Brand::B2B]);

        $response = $this->withHeaders($headers)->postJson("/api/management/contracts/{$contract->id}/close");
        $response->assertStatus(400);
    }

    public function test_cannot_open_already_active_contract(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);

        $response = $this->withHeaders($headers)->postJson("/api/management/contracts/{$contract->id}/open");
        $response->assertStatus(400);
    }

    public function test_can_update_active_contract_without_signatures(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B, 'terms_html' => '<p>Original</p>']);

        $response = $this->withHeaders($headers)->putJson("/api/management/contracts/{$contract->id}", [
            'terms_html' => '<p>Updated</p>',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'terms_html' => '<p>Updated</p>',
            'content_version' => 1,
        ]);
    }

    public function test_modified_audit_log_written_when_updating_active_contract(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);

        $this->withHeaders($headers)->putJson("/api/management/contracts/{$contract->id}", [
            'terms_html' => '<p>Modified</p>',
        ]);

        $this->assertDatabaseHas('contract_audit_logs', [
            'contract_id' => $contract->id,
            'contract_signer_id' => null,
            'action' => 'modified',
        ]);
    }

    public function test_content_version_not_incremented_on_draft_update(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create(['status' => 'draft', 'brand' => Brand::B2B]);

        $this->withHeaders($headers)->putJson("/api/management/contracts/{$contract->id}", [
            'terms_html' => '<p>Draft edit</p>',
        ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'content_version' => 0,
        ]);
    }
}
