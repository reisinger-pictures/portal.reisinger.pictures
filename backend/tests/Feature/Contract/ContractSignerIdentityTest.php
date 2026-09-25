<?php

namespace Tests\Feature\Contract;

use App\Enums\Brand;
use App\Exceptions\ContractIdentityException;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Support\BrandRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractSignerIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_model_derives_direct_and_template_identity_and_freezes_it(): void
    {
        $direct = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $directSigner = ContractSigner::factory()->create([
            'contract_id' => $direct->id,
            'email' => '  Direct@Example.COM  ',
        ]);

        $this->assertSame('  Direct@Example.COM  ', $directSigner->email);
        $this->assertSame('direct@example.com', $directSigner->normalized_email);
        $this->assertSame('contract:'.$direct->id, $directSigner->join_scope_key);

        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $templateSigner = ContractSigner::factory()->create([
            'contract_id' => $instance->id,
            'email' => 'Template@Example.COM',
        ]);

        $this->assertSame('template@example.com', $templateSigner->normalized_email);
        $this->assertSame('template:'.$template->id, $templateSigner->join_scope_key);

        // The snapshot is independent of the mutable contract relation.
        $instance->update(['template_id' => null]);
        $template->delete();
        DB::table('contract_signers')->where('id', $templateSigner->id)->update(['name' => 'Snapshot retained']);
        $this->assertSame('template:'.$template->id, $templateSigner->fresh()->join_scope_key);

        try {
            $directSigner->update(['join_scope_key' => 'template:'.Str::uuid()]);
            $this->fail('Expected the model to freeze join_scope_key.');
        } catch (ContractIdentityException $exception) {
            $this->assertStringContainsString('unveränderlich', $exception->getMessage());
        }

        try {
            $templateSigner->update(['email' => 'changed@example.com']);
            $this->fail('Expected the model to freeze normalized email identity.');
        } catch (ContractIdentityException $exception) {
            $this->assertStringContainsString('unveränderlich', $exception->getMessage());
        }
    }

    public function test_model_rejects_a_malformed_join_scope_before_insert(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);

        try {
            ContractSigner::factory()->create([
                'contract_id' => $contract->id,
                'join_scope_key' => 'contract:not-a-uuid',
            ]);
            $this->fail('Expected malformed scope to be rejected.');
        } catch (ContractIdentityException $exception) {
            $this->assertStringContainsString('Vertragsscope', $exception->getMessage());
        }

        $this->assertDatabaseCount('contract_signers', 0);

        try {
            ContractSigner::factory()->create([
                'contract_id' => $contract->id,
                'normalized_email' => 'different@example.com',
            ]);
            $this->fail('Expected a mismatched canonical email to be rejected.');
        } catch (ContractIdentityException $exception) {
            $this->assertStringContainsString('E-Mail', $exception->getMessage());
        }

        try {
            ContractSigner::factory()->create([
                'contract_id' => $contract->id,
                'join_scope_key' => 'contract:'.Str::uuid(),
            ]);
            $this->fail('Expected a mismatched canonical scope to be rejected.');
        } catch (ContractIdentityException $exception) {
            $this->assertStringContainsString('Vertragsscope', $exception->getMessage());
        }

        $this->assertDatabaseCount('contract_signers', 0);
    }

    public function test_model_rejects_a_signer_when_the_template_relation_is_malformed(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);

        DB::table('contracts')->where('id', $template->id)->update(['type' => 'contract']);

        try {
            ContractSigner::factory()->create([
                'contract_id' => $instance->id,
                'email' => 'malformed-template@example.com',
            ]);
            $this->fail('Expected a malformed template relation to be rejected.');
        } catch (ContractIdentityException $exception) {
            $this->assertStringContainsString('Template-Scope', $exception->getMessage());
        }

        $this->assertDatabaseCount('contract_signers', 0);
    }

    public function test_database_guards_reject_null_and_identity_mutation_for_raw_writers(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);

        try {
            DB::table('contract_signers')->insert([
                'id' => (string) Str::uuid(),
                'contract_id' => $contract->id,
                'name' => 'Raw writer',
                'email' => 'raw@example.com',
                'normalized_email' => null,
                'join_scope_key' => 'contract:'.$contract->id,
                'roles' => json_encode(['Model']),
                'personal_token' => 'raw-null-'.Str::random(20),
                'status' => 'joined',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected the database null-identity guard to reject the insert.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('contract_signer_identity_required', $exception->getMessage());
        }

        $signer = ContractSigner::factory()->create([
            'contract_id' => $contract->id,
            'email' => 'immutable@example.com',
        ]);

        try {
            DB::table('contract_signers')
                ->where('id', $signer->id)
                ->update(['join_scope_key' => 'contract:'.Str::uuid()]);
            $this->fail('Expected the database identity guard to reject the update.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('contract_signer_identity_immutable', $exception->getMessage());
        }

        $otherContract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        try {
            DB::table('contract_signers')
                ->where('id', $signer->id)
                ->update(['contract_id' => $otherContract->id]);
            $this->fail('Expected the database identity guard to reject contract reassignment.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('contract_signer_identity_immutable', $exception->getMessage());
        }

        try {
            DB::table('contract_signers')
                ->where('id', $signer->id)
                ->update(['email' => 'changed-raw@example.com']);
            $this->fail('Expected the database identity guard to reject email mutation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('contract_signer_identity_immutable', $exception->getMessage());
        }
    }

    public function test_mariadb_trigger_works_with_a_non_unicode_database_or_server_collation(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('This collation regression requires MySQL/MariaDB.');
        }

        $databaseCollation = (string) DB::scalar('SELECT @@collation_database');
        $serverCollation = (string) DB::scalar('SELECT @@collation_server');
        if ($databaseCollation === 'utf8mb4_unicode_ci' && $serverCollation === 'utf8mb4_unicode_ci') {
            $this->markTestSkipped('Run this regression with a non-unicode_ci database or server default.');
        }

        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $this->insertRawSigner(
            $contract,
            'collation@example.com',
            'contract:'.$contract->id,
            'collation@example.com',
        );

        $this->assertDatabaseHas('contract_signers', [
            'contract_id' => $contract->id,
            'normalized_email' => 'collation@example.com',
            'join_scope_key' => 'contract:'.$contract->id,
        ]);
    }

    public function test_raw_sql_guards_reject_wrong_email_and_direct_or_template_scope(): void
    {
        $direct = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);

        $this->assertRawSignerInsertRejected(
            $direct,
            'source@example.com',
            'wrong@example.com',
            'contract:'.$direct->id,
        );
        $this->assertRawSignerInsertRejected(
            $direct,
            'wrong-scope@example.com',
            'wrong-scope@example.com',
            'template:'.Str::uuid(),
        );
        $this->assertRawSignerInsertRejected(
            $instance,
            'wrong-template-scope@example.com',
            'wrong-template-scope@example.com',
            'contract:'.$instance->id,
        );

        $this->assertDatabaseCount('contract_signers', 0);
    }

    public function test_raw_sql_guards_reject_overlong_normalized_email(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $overlongNormalizedEmail = str_repeat('a', 244).'@example.com';

        try {
            $this->insertRawSigner(
                $contract,
                'short@example.com',
                'contract:'.$contract->id,
                $overlongNormalizedEmail,
            );
            $this->fail('Expected the normalized-email length guard to reject the insert.');
        } catch (QueryException $exception) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $this->assertStringContainsString('contract_signer_identity_required', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('contract_signers', 0);
    }

    public function test_raw_sql_guards_reject_malformed_template_relation_and_accept_valid_scopes(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $instance = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => $template->id,
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $direct = Contract::factory()->create([
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);

        $this->assertRawSignerInsertRejected(
            $template,
            'attached-to-template@example.com',
            'attached-to-template@example.com',
            'contract:'.$template->id,
        );

        DB::table('contracts')->where('id', $template->id)->update(['type' => 'contract']);
        $this->assertRawSignerInsertRejected(
            $instance,
            'malformed-relation@example.com',
            'malformed-relation@example.com',
            'template:'.$template->id,
        );

        DB::table('contracts')->where('id', $template->id)->update(['type' => 'template']);
        $this->insertRawSigner(
            $instance,
            '  Template@Example.COM  ',
            'template:'.$template->id,
            'template@example.com',
        );
        $this->insertRawSigner(
            $direct,
            '  Direct@Example.COM  ',
            'contract:'.$direct->id,
            'direct@example.com',
        );

        $this->assertDatabaseHas('contract_signers', [
            'contract_id' => $instance->id,
            'normalized_email' => 'template@example.com',
            'join_scope_key' => 'template:'.$template->id,
        ]);
        $this->assertDatabaseHas('contract_signers', [
            'contract_id' => $direct->id,
            'normalized_email' => 'direct@example.com',
            'join_scope_key' => 'contract:'.$direct->id,
        ]);
    }

    public function test_direct_join_duplicate_uses_canonical_scope_and_returns_safe_conflict(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'identity-direct',
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $first = $this->postJson('/api/contracts/join/identity-direct', [
            'name' => 'First signer',
            'email' => 'direct.identity@example.com',
            'roles' => ['Model'],
        ]);
        $first->assertCreated();
        $firstToken = $first->json('personal_token');

        $second = $this->postJson('/api/contracts/join/identity-direct', [
            'name' => 'Second signer',
            'email' => '  DIRECT.IDENTITY@EXAMPLE.COM  ',
            'roles' => ['Model'],
        ]);

        $second->assertStatus(409);
        $second->assertExactJson(['error' => ContractSigner::DUPLICATE_JOIN_ERROR]);
        $this->assertArrayNotHasKey('personal_token', $second->json());
        $this->assertArrayNotHasKey('name', $second->json());
        $this->assertArrayNotHasKey('roles', $second->json());
        $this->assertStringNotContainsString($firstToken, $second->getContent());
        $this->assertDatabaseCount('contract_signers', 1);
        $this->assertDatabaseHas('contract_signers', [
            'contract_id' => $contract->id,
            'normalized_email' => 'direct.identity@example.com',
            'join_scope_key' => 'contract:'.$contract->id,
        ]);
    }

    public function test_template_join_duplicate_is_scoped_to_template_snapshot(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'join_token' => 'identity-template',
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $first = $this->postJson('/api/contracts/join/identity-template', [
            'name' => 'First template signer',
            'email' => 'template.identity@example.com',
            'roles' => ['Model'],
        ]);
        $first->assertCreated();

        $second = $this->postJson('/api/contracts/join/identity-template', [
            'name' => 'Second template signer',
            'email' => 'TEMPLATE.IDENTITY@EXAMPLE.COM',
            'roles' => ['Model'],
        ]);

        $second->assertStatus(409);
        $second->assertExactJson(['error' => ContractSigner::DUPLICATE_JOIN_ERROR]);
        $this->assertDatabaseCount('contracts', 2);
        $this->assertDatabaseCount('contract_signers', 1);
        $this->assertDatabaseHas('contract_signers', [
            'normalized_email' => 'template.identity@example.com',
            'join_scope_key' => 'template:'.$template->id,
        ]);
    }

    public function test_unique_violation_rolls_back_a_template_instance_and_signer_together(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'join_token' => 'identity-unique-template',
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $injected = false;
        ContractSigner::creating(function (ContractSigner $candidate) use ($template, &$injected): void {
            if ($injected || $candidate->normalized_email !== 'forced-template-race@example.com') {
                return;
            }

            $injected = true;
            DB::table('contract_signers')->insert([
                'id' => (string) Str::uuid(),
                'contract_id' => $candidate->contract_id,
                'name' => 'Injected conflict',
                'email' => $candidate->email,
                'normalized_email' => $candidate->normalized_email,
                'join_scope_key' => 'template:'.$template->id,
                'roles' => json_encode(['Model']),
                'personal_token' => 'forced-conflict-'.Str::random(20),
                'status' => 'joined',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->postJson('/api/contracts/join/identity-unique-template', [
            'name' => 'Forced conflict',
            'email' => 'forced-template-race@example.com',
            'roles' => ['Model'],
        ]);

        $response->assertStatus(409);
        $response->assertExactJson(['error' => ContractSigner::DUPLICATE_JOIN_ERROR]);
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('contract_signers', 0);
        $this->assertDatabaseCount('contract_audit_logs', 0);
    }

    public function test_unique_violation_rolls_back_a_direct_join_signer(): void
    {
        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'identity-unique-direct',
            'available_roles' => ['Model'],
            'brand' => Brand::B2B,
        ]);

        $injected = false;
        ContractSigner::creating(function (ContractSigner $candidate) use ($contract, &$injected): void {
            if ($injected || $candidate->normalized_email !== 'forced-direct-race@example.com') {
                return;
            }

            $injected = true;
            DB::table('contract_signers')->insert([
                'id' => (string) Str::uuid(),
                'contract_id' => $contract->id,
                'name' => 'Injected direct conflict',
                'email' => $candidate->email,
                'normalized_email' => $candidate->normalized_email,
                'join_scope_key' => 'contract:'.$contract->id,
                'roles' => json_encode(['Model']),
                'personal_token' => 'forced-direct-'.Str::random(20),
                'status' => 'joined',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->postJson('/api/contracts/join/identity-unique-direct', [
            'name' => 'Forced direct conflict',
            'email' => 'forced-direct-race@example.com',
            'roles' => ['Model'],
        ]);

        $response->assertStatus(409);
        $response->assertExactJson(['error' => ContractSigner::DUPLICATE_JOIN_ERROR]);
        $this->assertDatabaseCount('contract_signers', 0);
        $this->assertDatabaseCount('contract_audit_logs', 0);
    }

    private function assertRawSignerInsertRejected(
        Contract $contract,
        string $email,
        string $normalizedEmail,
        string $scopeKey,
    ): void {
        try {
            $this->insertRawSigner($contract, $email, $scopeKey, $normalizedEmail);
            $this->fail('Expected the raw signer identity guard to reject the insert.');
        } catch (QueryException $exception) {
            $message = $exception->getMessage();
            $this->assertTrue(
                str_contains($message, 'contract_signer_identity_required')
                    || str_contains($message, 'contract_signer_identity_invalid'),
                'The raw insert was rejected without a V039 identity error: '.$message,
            );
        }
    }

    private function insertRawSigner(
        Contract $contract,
        string $email,
        string $scopeKey,
        string $normalizedEmail,
    ): void {
        DB::table('contract_signers')->insert([
            'id' => (string) Str::uuid(),
            'contract_id' => $contract->id,
            'name' => 'Raw SQL signer',
            'email' => $email,
            'normalized_email' => $normalizedEmail,
            'join_scope_key' => $scopeKey,
            'roles' => json_encode(['Model']),
            'personal_token' => 'raw-identity-'.Str::random(24),
            'status' => 'joined',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
