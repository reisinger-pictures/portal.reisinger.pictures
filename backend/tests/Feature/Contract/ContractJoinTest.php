<?php

namespace Tests\Feature\Contract;

use App\Enums\Brand;
use App\Mail\ContractClosedMail;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

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

    public function test_410_for_contract_after_closes_at_on_check(): void
    {
        Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'closed-at-check',
            'closes_at' => now()->subMinute(),
            'brand' => Brand::B2B,
        ]);

        $response = $this->getJson('/api/contracts/join/closed-at-check');

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Die Signaturphase ist beendet');
    }

    public function test_410_for_contract_after_closes_at_on_join(): void
    {
        Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'closed-at-join',
            'closes_at' => now()->subMinute(),
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $response = $this->postJson('/api/contracts/join/closed-at-join', [
            'name' => 'Test',
            'email' => 'test@example.com',
            'roles' => ['Model'],
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Die Signaturphase ist beendet');
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

    public function test_view_contract_content_returns_authoritative_total_for_ordered_discounts(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [
                ['type' => 'item', 'description' => 'Fotoshooting', 'notes' => '', 'qty' => 2, 'price' => 5000],
            ],
            'discounts' => [
                ['type' => 'discount_percent', 'description' => '10% Rabatt', 'notes' => '', 'price' => 1000],
                ['type' => 'discount_fixed', 'description' => 'Bonus', 'notes' => '', 'price' => 500],
            ],
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'discounted-contract',
            'status' => 'joined',
        ]);

        $response = $this->getJson('/api/contracts/sign/discounted-contract');

        $response->assertOk();
        $response->assertJsonPath('contract.discounts.0.price', 1000);
        // 10000 - round(10000 × 1000 / 10000) = 9000; 9000 - 500 = 8500
        $response->assertJsonPath('contract.total', 8500);
    }

    public function test_view_contract_content_normalizes_legacy_mixed_snapshot_before_total(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [
                ['type' => 'item', 'description' => 'Fotoshooting', 'notes' => '', 'qty' => 1, 'price' => 10000],
                ['type' => 'discount_percent', 'description' => '10% Rabatt', 'notes' => '', 'price' => 1000],
            ],
            'discounts' => [
                ['type' => 'discount_fixed', 'description' => 'Bonus', 'notes' => '', 'price' => 500],
            ],
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'legacy-mixed-contract',
            'status' => 'joined',
        ]);

        $response = $this->getJson('/api/contracts/sign/legacy-mixed-contract');

        $response->assertOk();
        $response->assertJsonPath('contract.items.0.type', 'item');
        $response->assertJsonCount(1, 'contract.items');
        $response->assertJsonPath('contract.discounts.0.type', 'discount_percent');
        $response->assertJsonPath('contract.discounts.1.type', 'discount_fixed');
        // Legacy source order is retained: 10000 - 10% = 9000, then -500.
        $response->assertJsonPath('contract.total', 8500);
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

    public function test_repeated_signature_requests_create_one_signature_path(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'single-signature-path',
            'status' => 'joined',
        ]);

        $first = $this->postJson('/api/contracts/sign/single-signature-path', [
            'accept_contract' => true,
            'content_version' => $contract->content_version,
        ]);
        $first->assertStatus(200);

        $second = $this->postJson('/api/contracts/sign/single-signature-path', [
            'accept_contract' => true,
            'content_version' => $contract->content_version,
        ]);
        $second->assertStatus(409);

        $this->assertDatabaseCount('contract_audit_logs', 1);
        $this->assertDatabaseHas('contract_audit_logs', [
            'contract_signer_id' => ContractSigner::where('personal_token', 'single-signature-path')->value('id'),
            'action' => 'signed',
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

    public function test_contract_content_rejects_expired_contract(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'expires_at' => now()->subMinute(),
            'brand' => Brand::B2B,
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'expired-content',
            'status' => 'joined',
        ]);

        $response = $this->getJson('/api/contracts/sign/expired-content');

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Der Vertragslink ist abgelaufen');
    }

    public function test_sign_rejects_expired_contract(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'expires_at' => now()->subMinute(),
            'brand' => Brand::B2B,
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'expired-sign',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/expired-sign', [
            'accept_contract' => true,
            'content_version' => $contract->content_version,
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Der Vertragslink ist abgelaufen');
        $this->assertDatabaseHas('contract_signers', [
            'personal_token' => 'expired-sign',
            'status' => 'joined',
        ]);
    }

    public function test_contract_content_rejects_contract_after_closes_at(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'closes_at' => now()->subMinute(),
            'brand' => Brand::B2B,
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'closed-at-content',
            'status' => 'joined',
        ]);

        $response = $this->getJson('/api/contracts/sign/closed-at-content');

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Die Signaturphase ist beendet');
    }

    public function test_sign_rejects_contract_after_closes_at(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'closes_at' => now()->subMinute(),
            'brand' => Brand::B2B,
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'closed-at-sign',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/closed-at-sign', [
            'accept_contract' => true,
            'content_version' => $contract->content_version,
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Die Signaturphase ist beendet');
        $this->assertDatabaseHas('contract_signers', [
            'personal_token' => 'closed-at-sign',
            'status' => 'joined',
        ]);
    }

    public function test_standard_join_rejects_expiry_crossed_while_contract_is_locked(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'join-expiry-crossing',
            'expires_at' => now()->addHour(),
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $this->crossDeadlineWhenLocked($contract, 'expires_at');

        $response = $this->postJson('/api/contracts/join/join-expiry-crossing', [
            'name' => 'Crossing Signer',
            'email' => 'crossing@example.com',
            'roles' => ['Model'],
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Der Vertragslink ist abgelaufen');
        $this->assertDatabaseCount('contract_signers', 0);
    }

    public function test_standard_join_rolls_back_when_deadline_crosses_during_signer_insert(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'join-expiry-during-insert',
            'expires_at' => now()->addHour(),
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);
        $crossed = false;

        ContractSigner::creating(function () use ($contract, &$crossed): void {
            if ($crossed) {
                return;
            }

            $crossed = true;
            DB::table('contracts')
                ->where('id', $contract->getKey())
                ->update([
                    'expires_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
        });

        $response = $this->postJson('/api/contracts/join/join-expiry-during-insert', [
            'name' => 'Insert Crossing Signer',
            'email' => 'insert-crossing@example.com',
            'roles' => ['Model'],
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Der Vertragslink ist abgelaufen');
        $this->assertDatabaseCount('contract_signers', 0);
    }

    public function test_template_join_rolls_back_instance_when_deadline_crosses_during_signer_insert(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'join_token' => 'template-expiry-during-insert',
            'expires_at' => now()->addHour(),
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);
        $crossed = false;

        ContractSigner::creating(function () use ($template, &$crossed): void {
            if ($crossed) {
                return;
            }

            $crossed = true;
            DB::table('contracts')
                ->where('id', $template->getKey())
                ->update([
                    'expires_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
        });

        $response = $this->postJson('/api/contracts/join/template-expiry-during-insert', [
            'name' => 'Template Insert Crossing Signer',
            'email' => 'template-insert-crossing@example.com',
            'roles' => ['Model'],
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Der Vertragslink ist abgelaufen');
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('contract_signers', 0);
    }

    public function test_template_join_rejects_expiry_crossed_while_template_is_locked(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'join_token' => 'template-expiry-crossing',
            'expires_at' => now()->addHour(),
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $this->crossDeadlineWhenLocked($template, 'expires_at');

        $response = $this->postJson('/api/contracts/join/template-expiry-crossing', [
            'name' => 'Template Crossing Signer',
            'email' => 'template-crossing@example.com',
            'roles' => ['Model'],
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Der Vertragslink ist abgelaufen');
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('contract_signers', 0);
    }

    public function test_sign_rejects_close_deadline_crossed_while_contract_is_locked(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'closes_at' => now()->addHour(),
            'brand' => Brand::B2B,
        ]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'sign-expiry-crossing',
            'status' => 'joined',
        ]);

        $this->crossDeadlineWhenLocked($contract, 'closes_at');

        $response = $this->postJson('/api/contracts/sign/sign-expiry-crossing', [
            'accept_contract' => true,
            'content_version' => $contract->content_version,
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Die Signaturphase ist beendet');
        $this->assertDatabaseHas('contract_signers', [
            'id' => $signer->id,
            'status' => 'joined',
            'signed_at' => null,
        ]);
    }

    public function test_contract_content_rechecks_deadline_after_locked_read(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'closes_at' => now()->addHour(),
            'brand' => Brand::B2B,
        ]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'content-expiry-crossing',
            'status' => 'joined',
        ]);

        $this->crossDeadlineWhenLocked($contract, 'closes_at');

        $response = $this->getJson('/api/contracts/sign/content-expiry-crossing');

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Die Signaturphase ist beendet');
        $this->assertDatabaseMissing('contract_audit_logs', [
            'contract_signer_id' => $signer->id,
            'action' => 'heartbeat',
        ]);
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

    public function test_page_exit_remains_valid_telemetry_after_contract_is_closed(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'closed',
            'closes_at' => now()->subMinute(),
            'brand' => Brand::B2B,
        ]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'exit-after-close',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/exit-after-close/page-exit');

        $response->assertStatus(204);
        $this->assertDatabaseHas('contract_audit_logs', [
            'contract_signer_id' => $signer->id,
            'action' => 'page_exit',
        ]);
        $this->assertDatabaseHas('contract_signers', [
            'id' => $signer->id,
            'status' => 'joined',
        ]);
    }

    public function test_page_exit_404_for_invalid_token(): void
    {
        $response = $this->postJson('/api/contracts/sign/invalid-token/page-exit');
        $response->assertStatus(404);
    }

    public function test_repeated_join_with_same_email_waits_for_the_normalized_lock_and_fails_closed_without_disclosure(): void
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

        // A competing request reaches the same cache-lock identity even when
        // case and surrounding whitespace differ. Holding that exact lock for
        // one second exercises Laravel's supported block/wait path without an
        // unsafe second SQLite :memory: writer.
        $lockKey = Contract::joinLockKey($contract->getKey(), '  ANNA@EXAMPLE.COM  ');
        $lock = Cache::lock($lockKey, 1);
        $this->assertTrue($lock->get());
        $startedAt = microtime(true);

        try {
            $second = $this->postJson('/api/contracts/join/rejoin', [
                'name' => 'Anna Test',
                'email' => '  ANNA@EXAMPLE.COM  ',
                'roles' => ['Model'],
            ]);
        } finally {
            $lock->release();
        }

        $this->assertGreaterThan(0.5, microtime(true) - $startedAt);
        $second->assertStatus(409);
        $second->assertExactJson(['error' => 'Für diese E-Mail besteht bereits ein Vertrag.']);
        $this->assertArrayNotHasKey('personal_token', $second->json());
        $this->assertArrayNotHasKey('name', $second->json());
        $this->assertArrayNotHasKey('roles', $second->json());
        $this->assertStringNotContainsString($firstToken, $second->getContent());
        $this->assertStringNotContainsString('Anna Test', $second->getContent());
        $this->assertStringNotContainsString('Model', $second->getContent());
        $this->assertFalse(Cache::lock($lockKey)->isLocked());

        $this->assertEquals(1, ContractSigner::where('email', 'anna@example.com')->count());
        $this->assertDatabaseCount('contract_audit_logs', 1);
    }

    public function test_standard_join_rejects_legacy_mixed_case_email_without_disclosure(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'legacy-normalized-email',
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);
        $existingSigner = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'email' => '  Legacy-Signer@Example.COM  ',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/join/legacy-normalized-email', [
            'name' => 'Different Name',
            'email' => 'legacy-signer@example.com',
            'roles' => ['Model'],
        ]);

        $response->assertStatus(409);
        $this->assertArrayNotHasKey('personal_token', $response->json());
        $this->assertArrayNotHasKey('name', $response->json());
        $this->assertArrayNotHasKey('roles', $response->json());
        $this->assertDatabaseHas('contract_signers', [
            'id' => $existingSigner->id,
            'email' => '  Legacy-Signer@Example.COM  ',
        ]);
        $this->assertDatabaseCount('contract_signers', 1);
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

    public function test_template_join_creates_instance_and_signer(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'join_token' => 'tpl-join',
            'available_roles' => ['Model', 'Fotograf'],
            'terms_html' => '<p>Template Terms</p>',
            'items' => [['type' => 'item', 'description' => 'Test Item', 'qty' => 1, 'price' => 1000, 'notes' => '']],
            'brand' => Brand::B2B,
        ]);

        $response = $this->postJson('/api/contracts/join/tpl-join', [
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'roles' => ['Model'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['personal_token', 'name', 'roles']);

        $this->assertDatabaseHas('contracts', [
            'type' => 'contract',
            'template_id' => $template->id,
            'terms_html' => '<p>Template Terms</p>',
        ]);

        $instance = Contract::where('template_id', $template->id)->first();
        $this->assertNotNull($instance);
        $this->assertDatabaseHas('contract_signers', [
            'contract_id' => $instance->id,
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'status' => 'joined',
        ]);
    }

    public function test_repeated_template_join_with_same_email_waits_for_the_normalized_lock_and_fails_closed_without_disclosure(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'join_token' => 'tpl-rejoin',
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $first = $this->postJson('/api/contracts/join/tpl-rejoin', [
            'name' => 'Max Mustermann',
            'email' => 'max-rejoin@example.com',
            'roles' => ['Model'],
        ]);
        $first->assertStatus(201);
        $firstToken = $first->json('personal_token');

        $lockKey = Contract::joinLockKey($template->getKey(), '  MAX-REJOIN@EXAMPLE.COM  ');
        $lock = Cache::lock($lockKey, 1);
        $this->assertTrue($lock->get());
        $startedAt = microtime(true);

        try {
            $second = $this->postJson('/api/contracts/join/tpl-rejoin', [
                'name' => 'Max Mustermann',
                'email' => '  MAX-REJOIN@EXAMPLE.COM  ',
                'roles' => ['Model'],
            ]);
        } finally {
            $lock->release();
        }

        $this->assertGreaterThan(0.5, microtime(true) - $startedAt);
        $second->assertStatus(409);
        $second->assertExactJson(['error' => 'Für diese E-Mail besteht bereits ein Vertrag.']);
        $this->assertArrayNotHasKey('personal_token', $second->json());
        $this->assertArrayNotHasKey('name', $second->json());
        $this->assertArrayNotHasKey('roles', $second->json());
        $this->assertStringNotContainsString($firstToken, $second->getContent());
        $this->assertStringNotContainsString('Max Mustermann', $second->getContent());
        $this->assertStringNotContainsString('Model', $second->getContent());
        $this->assertFalse(Cache::lock($lockKey)->isLocked());

        $this->assertDatabaseCount('contracts', 2);
        $this->assertDatabaseCount('contract_signers', 1);
        $this->assertSame(1, ContractSigner::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', ['max-rejoin@example.com'])
            ->count());
        $this->assertDatabaseHas('contracts', [
            'template_id' => $template->id,
            'type' => 'contract',
        ]);
        $this->assertDatabaseCount('contract_audit_logs', 1);
    }

    public function test_normalized_email_uniqueness_is_isolated_per_direct_contract_and_template(): void
    {
        $directContracts = [
            Contract::factory()->create([
                'status' => 'active',
                'join_token' => 'scope-direct-a',
                'available_roles' => ['Model'],
                'brand' => Brand::B2B,
            ]),
            Contract::factory()->create([
                'status' => 'active',
                'join_token' => 'scope-direct-b',
                'available_roles' => ['Model'],
                'brand' => Brand::B2B,
            ]),
        ];
        $templates = [
            Contract::factory()->create([
                'type' => 'template',
                'status' => 'active',
                'join_token' => 'scope-template-a',
                'available_roles' => ['Model'],
                'brand' => Brand::B2B,
            ]),
            Contract::factory()->create([
                'type' => 'template',
                'status' => 'active',
                'join_token' => 'scope-template-b',
                'available_roles' => ['Model'],
                'brand' => Brand::B2B,
            ]),
        ];

        $responses = [
            $this->postJson('/api/contracts/join/scope-direct-a', [
                'name' => 'Scoped Signer',
                'email' => 'scoped-signer@example.com',
                'roles' => ['Model'],
            ]),
            $this->postJson('/api/contracts/join/scope-direct-b', [
                'name' => 'Scoped Signer',
                'email' => '  SCOPED-SIGNER@EXAMPLE.COM  ',
                'roles' => ['Model'],
            ]),
            $this->postJson('/api/contracts/join/scope-template-a', [
                'name' => 'Scoped Signer',
                'email' => 'scoped-signer@example.com',
                'roles' => ['Model'],
            ]),
            $this->postJson('/api/contracts/join/scope-template-b', [
                'name' => 'Scoped Signer',
                'email' => '  SCOPED-SIGNER@EXAMPLE.COM  ',
                'roles' => ['Model'],
            ]),
        ];

        foreach ($responses as $response) {
            $response->assertCreated();
        }

        $tokens = array_map(
            static fn ($response): string => (string) $response->json('personal_token'),
            $responses,
        );
        $this->assertCount(4, array_unique($tokens));

        foreach ($directContracts as $contract) {
            $this->assertSame(1, $contract->signers()->count());
        }
        foreach ($templates as $template) {
            $instances = Contract::query()
                ->where('template_id', $template->getKey())
                ->whereHas('signers', function ($query): void {
                    $query->whereRaw('LOWER(TRIM(email)) = ?', ['scoped-signer@example.com']);
                })
                ->get();
            $this->assertCount(1, $instances);
            $this->assertSame(1, $instances->first()->signers()->count());
        }

        $this->assertSame(4, ContractSigner::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', ['scoped-signer@example.com'])
            ->count());
        $this->assertDatabaseCount('contract_audit_logs', 4);
    }

    public function test_template_join_copies_template_data(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'join_token' => 'tpl-data',
            'available_roles' => ['Model'],
            'terms_html' => '<p>Copied Terms</p>',
            'items' => [['type' => 'item', 'description' => 'Foto', 'qty' => 1, 'price' => 5000, 'notes' => '']],
            'discounts' => [['type' => 'discount_fixed', 'description' => 'Rabatt', 'price' => 500, 'notes' => '']],
            'brand' => Brand::B2B,
        ]);

        $this->postJson('/api/contracts/join/tpl-data', [
            'name' => 'User',
            'email' => 'user@example.com',
            'roles' => ['Model'],
        ]);

        $instance = Contract::where('template_id', $template->id)->first();
        $this->assertEquals('<p>Copied Terms</p>', $instance->terms_html);
        $this->assertEquals($template->items, $instance->items);
        $this->assertEquals($template->discounts, $instance->discounts);
        $this->assertEquals($template->available_roles, $instance->available_roles);
    }

    public function test_template_expired_returns_410_on_check(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'join_token' => 'expired-tpl',
            'expires_at' => now()->subDay(),
            'brand' => Brand::B2B,
        ]);

        $response = $this->getJson('/api/contracts/join/expired-tpl');
        $response->assertStatus(410);
    }

    public function test_template_expired_returns_410_on_join(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'join_token' => 'expired-join',
            'expires_at' => now()->subDay(),
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $response = $this->postJson('/api/contracts/join/expired-join', [
            'name' => 'Test',
            'email' => 'test@example.com',
            'roles' => ['Model'],
        ]);
        $response->assertStatus(410);
    }

    public function test_legacy_template_instance_with_null_expiry_is_blocked_by_expired_parent(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'expires_at' => now()->subMinute(),
            'closes_at' => null,
            'brand' => Brand::B2B,
        ]);
        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'active',
            'expires_at' => null,
            'closes_at' => null,
            'brand' => Brand::B2B,
        ]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $instance->id,
            'personal_token' => 'legacy-parent-expired',
            'status' => 'joined',
        ]);

        $content = $this->getJson('/api/contracts/sign/legacy-parent-expired');
        $content->assertStatus(410);
        $content->assertJsonPath('error', 'Der Vertragslink ist abgelaufen');

        $signature = $this->postJson('/api/contracts/sign/legacy-parent-expired', [
            'accept_contract' => true,
            'content_version' => $instance->content_version,
        ]);
        $signature->assertStatus(410);
        $signature->assertJsonPath('error', 'Der Vertragslink ist abgelaufen');

        $this->assertDatabaseHas('contract_signers', [
            'id' => $signer->id,
            'status' => 'joined',
            'signed_at' => null,
        ]);
        $this->assertDatabaseHas('contracts', [
            'id' => $instance->id,
            'expires_at' => null,
            'closes_at' => null,
        ]);
    }

    public function test_legacy_template_instance_with_null_close_deadline_is_blocked_by_parent(): void
    {
        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'expires_at' => now()->addHour(),
            'closes_at' => now()->subMinute(),
            'brand' => Brand::B2B,
        ]);
        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'active',
            'expires_at' => null,
            'closes_at' => null,
            'brand' => Brand::B2B,
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $instance->id,
            'personal_token' => 'legacy-parent-closed',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/legacy-parent-closed', [
            'accept_contract' => true,
            'content_version' => $instance->content_version,
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'Die Signaturphase ist beendet');
        $this->assertDatabaseHas('contracts', [
            'id' => $instance->id,
            'expires_at' => null,
            'closes_at' => null,
        ]);
    }

    public function test_template_sign_auto_closes_instance(): void
    {
        Mail::fake();

        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);

        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'active',
            'terms_html' => '<p>Instance</p>',
            'brand' => Brand::B2B,
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $instance->id,
            'personal_token' => 'auto-close-token',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/auto-close-token', [
            'accept_contract' => true,
            'content_version' => $instance->content_version,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('contracts', [
            'id' => $instance->id,
            'status' => 'closed',
        ]);
    }

    public function test_repeated_template_auto_close_keeps_one_accounting_and_mail_path(): void
    {
        Mail::fake();

        $template = Contract::factory()->create([
            'type' => 'template',
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'active',
            'brand' => Brand::B2B,
            'items' => [[
                'type' => 'item',
                'description' => 'Automatischer Abschluss',
                'notes' => '',
                'qty' => 1,
                'price' => 10000,
            ]],
            'discounts' => [],
            'billing_details' => [
                'name' => 'Rechnung',
                'email' => 'auto-close@example.com',
            ],
        ]);
        $signer = ContractSigner::factory()->create([
            'contract_id' => $instance->id,
            'email' => 'auto-close@example.com',
            'status' => 'joined',
        ]);

        $first = $this->postJson("/api/contracts/sign/{$signer->personal_token}", [
            'accept_contract' => true,
            'content_version' => $instance->content_version,
        ]);
        $first->assertOk();

        // The closed instance is unavailable on a repeated public signature
        // request, and the close service must not create a second claim.
        $second = $this->postJson("/api/contracts/sign/{$signer->personal_token}", [
            'accept_contract' => true,
            'content_version' => $instance->content_version,
        ]);
        $second->assertStatus(410);

        $this->assertDatabaseHas('contracts', [
            'id' => $instance->id,
            'status' => 'closed',
        ]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('invoice_snapshots', 1);
        Mail::assertQueued(ContractClosedMail::class, 1);
    }

    public function test_standard_contract_sign_does_not_auto_close(): void
    {
        $contract = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => null,
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'personal_token' => 'standard-sign',
            'status' => 'joined',
        ]);

        $response = $this->postJson('/api/contracts/sign/standard-sign', [
            'accept_contract' => true,
            'content_version' => $contract->content_version,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'active',
        ]);
    }

    /**
     * Simulate a concurrent deadline writer exactly when the operation enters
     * its locked-read boundary, without sleeping or relying on wall-clock races.
     */
    private function crossDeadlineWhenLocked(Contract $contract, string $column): void
    {
        $crossed = false;

        Contract::retrieved(function (Contract $loadedContract) use ($contract, $column, &$crossed): void {
            if ($crossed
                || DB::connection()->transactionLevel() < 1
                || $loadedContract->getKey() !== $contract->getKey()) {
                return;
            }

            $crossed = true;
            DB::table('contracts')
                ->where('id', $contract->getKey())
                ->update([
                    $column => DB::raw('CURRENT_TIMESTAMP'),
                ]);
        });
    }
}
