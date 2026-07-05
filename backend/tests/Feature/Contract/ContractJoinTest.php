<?php

namespace Tests\Feature\Contract;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Enums\Brand;
use App\Support\BrandRegistry;

class ContractJoinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_check_active_contract_via_join_token(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'test-join-token',
            'available_roles' => ['Model', 'Fotograf'],
            'brand' => Brand::B2B,
        ]);

        $response = $this->getJson('/api/contracts/join/test-join-token');
        $response->assertStatus(200);
        $response->assertJson([
            'available_roles' => ['Model', 'Fotograf'],
            'status' => 'active',
        ]);
    }

    public function test_404_for_invalid_token(): void
    {
        $response = $this->getJson('/api/contracts/join/nonexistent');
        $response->assertStatus(404);
    }

    public function test_410_for_closed_contract(): void
    {
        Contract::factory()->create([
            'status' => 'closed',
            'join_token' => 'closed-token',
            'brand' => Brand::B2B,
        ]);

        $response = $this->getJson('/api/contracts/join/closed-token');
        $response->assertStatus(410);
    }

    public function test_join_contract_returns_personal_token(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'joinme',
            'available_roles' => ['Model', 'Fotograf'],
            'allow_multiple_roles_per_signer' => false,
            'brand' => Brand::B2B,
        ]);

        $response = $this->postJson('/api/contracts/join/joinme', [
            'name' => 'Anna Test',
            'email' => 'anna@example.com',
            'roles' => ['Model'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['personal_token', 'name', 'roles']);
        $this->assertDatabaseHas('contract_signers', [
            'name' => 'Anna Test',
            'email' => 'anna@example.com',
            'status' => 'joined',
        ]);
    }

    public function test_multiple_roles_rejected(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'multi-roles',
            'available_roles' => ['Model', 'Fotograf'],
            'allow_multiple_roles_per_signer' => false,
            'brand' => Brand::B2B,
        ]);

        $response = $this->postJson('/api/contracts/join/multi-roles', [
            'name' => 'Test',
            'email' => 'test@test.com',
            'roles' => ['Model', 'Fotograf'],
        ]);

        $response->assertStatus(422);
    }

    public function test_audit_log_written_on_join(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'auditme',
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $this->postJson('/api/contracts/join/auditme', [
            'name' => 'Audit Person',
            'email' => 'audit@example.com',
            'roles' => ['Model'],
        ]);

        $this->assertDatabaseHas('contract_audit_logs', [
            'action' => 'opened',
            'contract_id' => $contract->id,
        ]);
    }

    public function test_invalid_role_rejected(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'badrole',
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $response = $this->postJson('/api/contracts/join/badrole', [
            'name' => 'Test',
            'email' => 'test@test.com',
            'roles' => ['Hacker'],
        ]);

        $response->assertStatus(422);
    }

    public function test_view_contract_content(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'terms_html' => '<p>Terms</p>',
            'brand' => Brand::B2B,
        ]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'personal-abc',
            'status' => 'joined',
        ]);

        $response = $this->getJson('/api/contracts/sign/personal-abc');
        $response->assertStatus(200);
        $response->assertJsonStructure(['contract', 'signer']);
        $response->assertJsonPath('contract.terms_html', '<p>Terms</p>');
    }

    public function test_heartbeat_logs_audit(): void
    {
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'hb-token',
            'status' => 'joined',
        ]);

        $this->getJson('/api/contracts/sign/hb-token');

        $this->assertDatabaseHas('contract_audit_logs', [
            'action' => 'heartbeat',
            'contract_signer_id' => $signer->id,
        ]);
    }

    public function test_sign_contract(): void
    {
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'sign-here',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/sign-here', [
            'accept_contract' => true,
            'content_version' => $contract->content_version,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('contract_signers', [
            'personal_token' => 'sign-here',
            'status' => 'signed',
        ]);
    }

    public function test_cannot_sign_twice(): void
    {
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'double-sign',
            'status' => 'signed',
            'signed_at' => now(),
        ]);

        $response = $this->postJson('/api/contracts/sign/double-sign', [
            'accept_contract' => true,
        ]);
        $response->assertStatus(409);
    }

    public function test_cannot_sign_closed_contract(): void
    {
        $contract = Contract::factory()->create(['status' => 'closed', 'brand' => Brand::B2B]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'closed-sign',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/closed-sign', [
            'accept_contract' => true,
            'content_version' => $contract->content_version,
        ]);
        $response->assertStatus(410);
    }

    public function test_duplicate_signed_email_rejected(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'dup-email',
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $this->postJson('/api/contracts/join/dup-email', [
            'name' => 'First',
            'email' => 'same@example.com',
            'roles' => ['Model'],
        ]);
        $signer = ContractSigner::where('email', 'same@example.com')->first();
        $signer->update(['status' => 'signed', 'signed_at' => now()]);

        $response = $this->postJson('/api/contracts/join/dup-email', [
            'name' => 'Second',
            'email' => 'same@example.com',
            'roles' => ['Model'],
        ]);
        $response->assertStatus(409);
    }

    public function test_contract_content_returns_content_version(): void
    {
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'version-token',
            'status' => 'joined',
        ]);

        $response = $this->getJson('/api/contracts/sign/version-token');
        $response->assertStatus(200);
        $response->assertJsonPath('contract.content_version', 0);
    }

    public function test_sign_rejects_wrong_content_version(): void
    {
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'stale-sign',
            'status' => 'joined',
        ]);

        // Simulate contract being edited (version becomes 1)
        $contract->increment('content_version');

        $response = $this->postJson('/api/contracts/sign/stale-sign', [
            'accept_contract' => true,
            'content_version' => 0, // stale version
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error', 'Der Vertrag wurde geändert. Bitte laden Sie die Seite neu und lesen Sie die aktuelle Version.');
    }

    public function test_sign_succeeds_with_correct_content_version(): void
    {
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'fresh-sign',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/fresh-sign', [
            'accept_contract' => true,
            'content_version' => 0,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_sign_rejects_missing_content_version(): void
    {
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'no-version',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/no-version', [
            'accept_contract' => true,
        ]);

        $response->assertStatus(422);
    }

    public function test_page_exit_logs_audit(): void
    {
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'exit-token',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/exit-token/page-exit');
        $response->assertStatus(204);

        $this->assertDatabaseHas('contract_audit_logs', [
            'action' => 'page_exit',
            'contract_signer_id' => $signer->id,
        ]);
    }

    public function test_page_exit_404_for_invalid_token(): void
    {
        $response = $this->postJson('/api/contracts/sign/invalid-token/page-exit');
        $response->assertStatus(404);
    }

    public function test_repeated_join_with_same_email_returns_existing_token(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'rejoin',
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $first = $this->postJson('/api/contracts/join/rejoin', [
            'name' => 'Anna Test',
            'email' => 'anna@example.com',
            'roles' => ['Model'],
        ]);
        $first->assertStatus(201);
        $firstToken = $first->json('personal_token');

        $second = $this->postJson('/api/contracts/join/rejoin', [
            'name' => 'Anna Test',
            'email' => 'anna@example.com',
            'roles' => ['Model'],
        ]);
        $second->assertStatus(200);
        $this->assertEquals($firstToken, $second->json('personal_token'));

        $this->assertEquals(1, ContractSigner::where('email', 'anna@example.com')->count());
    }

    public function test_repeated_heartbeat_no_error(): void
    {
        $contract = Contract::factory()->create(['status' => 'active', 'brand' => Brand::B2B]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'hb-repeat',
            'status' => 'joined',
        ]);

        $first = $this->getJson('/api/contracts/sign/hb-repeat');
        $first->assertStatus(200);

        $second = $this->getJson('/api/contracts/sign/hb-repeat');
        $second->assertStatus(200);

        $this->assertDatabaseHas('contract_audit_logs', [
            'action' => 'heartbeat',
            'contract_signer_id' => $signer->id,
        ]);
    }
}
