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
        Contract::factory()->create(['brand' => 'test-brand']);

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

    public function test_can_create_template(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $response = $this->withHeaders($headers)->postJson('/api/management/contracts', [
            'type' => 'template',
            'available_roles' => ['Model'],
            'terms_html' => '<p>Template</p>',
            'items' => [],
            'discounts' => [],
            'expires_at' => now()->addDays(30)->toDateTimeString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('contracts', [
            'type' => 'template',
            'status' => 'draft',
        ]);
    }

    public function test_cannot_open_template_without_expiry(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create([
            'type' => 'template',
            'status' => 'draft',
            'expires_at' => null,
            'brand' => Brand::B2B,
        ]);

        $response = $this->withHeaders($headers)->postJson("/api/management/contracts/{$contract->id}/open");
        $response->assertStatus(422);
    }

    public function test_cannot_open_template_with_past_expiry(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create([
            'type' => 'template',
            'status' => 'draft',
            'expires_at' => now()->subDay(),
            'brand' => Brand::B2B,
        ]);

        $response = $this->withHeaders($headers)->postJson("/api/management/contracts/{$contract->id}/open");
        $response->assertStatus(422);
    }

    public function test_can_open_template_with_valid_expiry(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $contract = Contract::factory()->create([
            'type' => 'template',
            'status' => 'draft',
            'expires_at' => now()->addDays(30),
            'brand' => Brand::B2B,
        ]);

        $response = $this->withHeaders($headers)->postJson("/api/management/contracts/{$contract->id}/open");
        $response->assertStatus(200);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'active',
        ]);
        $this->assertNotNull($response->json('contract.join_token'));
    }

    public function test_index_filters_by_type(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        Contract::factory()->create(['type' => 'template', 'brand' => Brand::B2B]);
        Contract::factory()->create(['type' => 'contract', 'brand' => Brand::B2B]);
        Contract::factory()->create(['type' => 'contract', 'brand' => Brand::B2B]);

        $response = $this->withHeaders($headers)->getJson('/api/management/contracts?type=template');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());

        $response2 = $this->withHeaders($headers)->getJson('/api/management/contracts?type=contract');
        $response2->assertStatus(200);
        $this->assertCount(2, $response2->json());
    }

    public function test_instances_endpoint_returns_template_instances(): void
    {
        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);

        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);

        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'closed',
            'brand' => Brand::B2B,
        ]);

        $response = $this->withHeaders($headers)->getJson("/api/management/contracts/{$template->id}/instances");
        $response->assertStatus(200);
        $responseData = $response->json();
        $this->assertIsArray($responseData);
        $this->assertCount(1, $responseData);
        $this->assertEquals($instance->id, $responseData[0]['id']);
    }

    public function test_close_instance_works(): void
    {
        Mail::fake();

        $user = $this->createSuperAdmin();
        $headers = $this->authHeaders($user);
        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => Contract::factory()->create(['type' => 'template', 'brand' => Brand::B2B])->id,
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);

        $response = $this->withHeaders($headers)->postJson("/api/management/contracts/{$instance->id}/close");
        $response->assertStatus(200);
        $this->assertDatabaseHas('contracts', [
            'id' => $instance->id,
            'status' => 'closed',
        ]);
    }
}
