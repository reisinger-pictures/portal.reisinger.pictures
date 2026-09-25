<?php

namespace Tests\Feature\Contract;

use App\Enums\Brand;
use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class ContractSignerIdentityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v039_installs_the_canonical_columns_and_unique_index(): void
    {
        $this->assertTrue(Schema::hasColumn('contract_signers', 'normalized_email'));
        $this->assertTrue(Schema::hasColumn('contract_signers', 'join_scope_key'));

        $index = collect(Schema::getIndexes('contract_signers'))
            ->firstWhere('name', 'contract_signers_scope_normalized_email_unique');

        $this->assertNotNull($index);
        $this->assertTrue((bool) $index['unique']);
        $this->assertSame(
            ['join_scope_key', 'normalized_email'],
            array_map('strtolower', $index['columns']),
        );

        $columns = collect(Schema::getColumns('contract_signers'))->keyBy('name');
        if (DB::connection()->getDriverName() === 'sqlite') {
            $triggerCount = DB::scalar(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name IN (?, ?)",
                ['contract_signers_identity_insert_guard', 'contract_signers_identity_update_guard'],
            );
            $this->assertSame(2, (int) $triggerCount);
        } else {
            $this->assertFalse((bool) $columns['normalized_email']['nullable']);
            $this->assertFalse((bool) $columns['join_scope_key']['nullable']);
        }
    }

    public function test_v039_backfills_direct_and_template_scopes_without_touching_legacy_data(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The legacy-null backfill fixture targets SQLite, whose V039 columns remain nullable until trigger guards are installed.');
        }

        $direct = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'legacy-direct-contract-token',
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

        $directSignerId = (string) Str::uuid();
        $templateSignerId = (string) Str::uuid();
        $createdAt = '2024-01-02 03:04:05';
        $updatedAt = '2024-02-03 04:05:06';

        $this->dropIdentityGuards();
        $this->insertLegacySigner($directSignerId, $direct->id, ' Direct@Example.COM ', 'legacy-direct-token', $createdAt, $updatedAt);
        $this->insertLegacySigner($templateSignerId, $instance->id, 'Template@Example.COM', 'legacy-template-token', $createdAt, $updatedAt);

        // Simulate a partial retry: one canonical field was written before a
        // previous attempt stopped, while the unique index and triggers are
        // absent. The replay must trust the valid scope snapshot and fill only
        // the missing normalized email.
        DB::table('contract_signers')
            ->where('id', $templateSignerId)
            ->update(['join_scope_key' => 'template:'.$template->id]);

        $directAuditId = DB::table('contract_audit_logs')->insertGetId([
            'contract_id' => $direct->id,
            'contract_signer_id' => $directSignerId,
            'action' => 'opened',
            'created_at' => $createdAt,
        ]);
        $templateAuditId = DB::table('contract_audit_logs')->insertGetId([
            'contract_id' => $instance->id,
            'contract_signer_id' => $templateSignerId,
            'action' => 'opened',
            'created_at' => $createdAt,
        ]);

        try {
            $migration = $this->migration();
            $migration->up();

            $directSigner = DB::table('contract_signers')->where('id', $directSignerId)->first();
            $templateSigner = DB::table('contract_signers')->where('id', $templateSignerId)->first();

            $this->assertSame(' Direct@Example.COM ', $directSigner->email);
            $this->assertSame('direct@example.com', $directSigner->normalized_email);
            $this->assertSame('contract:'.$direct->id, $directSigner->join_scope_key);
            $this->assertSame('legacy-direct-token', $directSigner->personal_token);
            $this->assertSame($createdAt, $directSigner->created_at);
            $this->assertSame($updatedAt, $directSigner->updated_at);

            $this->assertSame('Template@Example.COM', $templateSigner->email);
            $this->assertSame('template@example.com', $templateSigner->normalized_email);
            $this->assertSame('template:'.$template->id, $templateSigner->join_scope_key);
            $this->assertSame('legacy-template-token', $templateSigner->personal_token);
            $this->assertSame($createdAt, $templateSigner->created_at);
            $this->assertSame($updatedAt, $templateSigner->updated_at);

            $this->assertDatabaseHas('contract_audit_logs', [
                'id' => $directAuditId,
                'contract_signer_id' => $directSignerId,
            ]);
            $this->assertDatabaseHas('contract_audit_logs', [
                'id' => $templateAuditId,
                'contract_signer_id' => $templateSignerId,
            ]);

            // A second invocation must be a no-op for data and replay-safe
            // for the index/trigger state.
            $migration->up();
            $this->assertSame(1, DB::table('contract_signers')->where('normalized_email', 'direct@example.com')->count());
            $this->assertSame(1, DB::table('contract_signers')->where('normalized_email', 'template@example.com')->count());
        } finally {
            // Leave the in-memory schema complete even when an assertion fails;
            // the migration itself never deletes source rows.
            $this->migration()->up();
        }
    }

    public function test_v039_malformed_row_preflight_fails_closed_without_backfill(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The legacy-null malformed fixture targets SQLite so it can model a pre-index migration state.');
        }

        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'malformed-contract-token',
            'brand' => Brand::B2B,
        ]);
        $signerId = (string) Str::uuid();
        $createdAt = '2024-03-04 05:06:07';
        $this->dropIdentityGuards();
        $this->insertLegacySigner($signerId, $contract->id, 'not-an-email', 'malformed-token', $createdAt, $createdAt);
        DB::table('contract_audit_logs')->insert([
            'contract_id' => $contract->id,
            'contract_signer_id' => $signerId,
            'action' => 'opened',
            'created_at' => $createdAt,
        ]);

        $exception = null;
        try {
            $this->migration()->up();
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        try {
            $this->assertInstanceOf(\RuntimeException::class, $exception);
            $this->assertStringContainsString('malformed_rows=1', $exception->getMessage());
            $this->assertStringContainsString('No signers or audit records were deleted or merged', $exception->getMessage());
            $this->assertDatabaseHas('contract_signers', [
                'id' => $signerId,
                'email' => 'not-an-email',
                'normalized_email' => null,
                'join_scope_key' => null,
                'personal_token' => 'malformed-token',
            ]);
            $this->assertDatabaseCount('contract_audit_logs', 1);
        } finally {
            DB::table('contract_audit_logs')->where('contract_signer_id', $signerId)->delete();
            DB::table('contract_signers')->where('id', $signerId)->delete();
            $this->migration()->up();
        }
    }

    public function test_v039_null_token_template_deletion_ambiguity_fails_even_with_a_stored_scope(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The legacy-null ambiguity fixture targets SQLite so it can model a pre-index migration state.');
        }

        $contract = Contract::factory()->create([
            'type' => 'contract',
            'template_id' => null,
            'join_token' => null,
            'status' => 'active',
            'brand' => Brand::B2B,
        ]);
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();
        $createdAt = '2024-03-04 05:06:07';
        $this->dropIdentityGuards();
        $this->insertLegacySigner($firstId, $contract->id, 'ambiguous-one@example.com', 'ambiguous-token-one', $createdAt, $createdAt);
        $this->insertLegacySigner($secondId, $contract->id, 'ambiguous-two@example.com', 'ambiguous-token-two', $createdAt, $createdAt);
        DB::table('contract_signers')->where('id', $secondId)->update([
            'join_scope_key' => 'contract:'.$contract->id,
        ]);
        DB::table('contract_audit_logs')->insert([
            [
                'contract_id' => $contract->id,
                'contract_signer_id' => $firstId,
                'action' => 'opened',
                'created_at' => $createdAt,
            ],
            [
                'contract_id' => $contract->id,
                'contract_signer_id' => $secondId,
                'action' => 'opened',
                'created_at' => $createdAt,
            ],
        ]);

        $exception = null;
        try {
            $this->migration()->up();
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        try {
            $this->assertInstanceOf(\RuntimeException::class, $exception);
            $this->assertStringContainsString('malformed_rows=2', $exception->getMessage());
            $this->assertStringContainsString('ambiguous legacy contract', $exception->getMessage());
            $this->assertStringContainsString($firstId, $exception->getMessage());
            $this->assertStringContainsString($secondId, $exception->getMessage());
            $this->assertDatabaseHas('contract_signers', [
                'id' => $firstId,
                'normalized_email' => null,
                'join_scope_key' => null,
            ]);
            $this->assertDatabaseHas('contract_signers', [
                'id' => $secondId,
                'normalized_email' => null,
                'join_scope_key' => 'contract:'.$contract->id,
            ]);
            $this->assertDatabaseCount('contract_audit_logs', 2);
        } finally {
            DB::table('contract_audit_logs')->whereIn('contract_signer_id', [$firstId, $secondId])->delete();
            DB::table('contract_signers')->whereIn('id', [$firstId, $secondId])->delete();
            DB::table('contracts')->where('id', $contract->id)->delete();
            $this->migration()->up();
        }
    }

    public function test_v039_duplicate_preflight_reports_and_aborts_without_deleting_or_merging(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The legacy-null duplicate fixture targets SQLite so it can model a pre-index migration state.');
        }

        $contract = Contract::factory()->create([
            'status' => 'active',
            'join_token' => 'duplicate-contract-token',
            'brand' => Brand::B2B,
        ]);
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();
        $createdAt = '2024-03-04 05:06:07';

        $this->dropIdentityGuards();
        $this->insertLegacySigner($firstId, $contract->id, 'same@example.com', 'duplicate-token-one', $createdAt, $createdAt);
        $this->insertLegacySigner($secondId, $contract->id, 'SAME@example.com', 'duplicate-token-two', $createdAt, $createdAt);
        DB::table('contract_audit_logs')->insert([
            [
                'contract_id' => $contract->id,
                'contract_signer_id' => $firstId,
                'action' => 'opened',
                'created_at' => $createdAt,
            ],
            [
                'contract_id' => $contract->id,
                'contract_signer_id' => $secondId,
                'action' => 'opened',
                'created_at' => $createdAt,
            ],
        ]);

        $exception = null;
        try {
            $this->migration()->up();
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        try {
            $this->assertInstanceOf(\RuntimeException::class, $exception);
            $this->assertStringContainsString('duplicate_groups=1', $exception->getMessage());
            $this->assertStringContainsString('same@example.com', $exception->getMessage());
            $this->assertStringContainsString($firstId, $exception->getMessage());
            $this->assertStringContainsString($secondId, $exception->getMessage());
            $this->assertStringContainsString('No signers or audit records were deleted or merged', $exception->getMessage());
            $this->assertDatabaseCount('contract_signers', 2);
            $this->assertDatabaseCount('contract_audit_logs', 2);
            $this->assertDatabaseHas('contract_signers', [
                'id' => $firstId,
                'email' => 'same@example.com',
                'normalized_email' => null,
                'join_scope_key' => null,
                'personal_token' => 'duplicate-token-one',
            ]);
            $this->assertDatabaseHas('contract_signers', [
                'id' => $secondId,
                'email' => 'SAME@example.com',
                'normalized_email' => null,
                'join_scope_key' => null,
                'personal_token' => 'duplicate-token-two',
            ]);
        } finally {
            DB::table('contract_audit_logs')->whereIn('contract_signer_id', [$firstId, $secondId])->delete();
            DB::table('contract_signers')->whereIn('id', [$firstId, $secondId])->delete();
            $this->migration()->up();
        }
    }

    public function test_v039_replay_repairs_a_partial_index_state_without_recomputing_a_snapshot(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The partial-column replay fixture targets SQLite; production drivers expose the completed NOT NULL state.');
        }

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
        $signerId = (string) Str::uuid();
        $this->dropIdentityGuards();
        $this->insertLegacySigner($signerId, $instance->id, 'snapshot@example.com', 'snapshot-token', '2024-04-05 06:07:08', '2024-04-05 06:07:08');
        DB::table('contract_signers')->where('id', $signerId)->update([
            'join_scope_key' => 'template:'.$template->id,
        ]);

        try {
            $this->migration()->up();
            $row = DB::table('contract_signers')->where('id', $signerId)->first();
            $this->assertSame('snapshot@example.com', $row->normalized_email);
            $this->assertSame('template:'.$template->id, $row->join_scope_key);
            $this->assertTrue(Schema::hasIndex('contract_signers', 'contract_signers_scope_normalized_email_unique', 'unique'));
        } finally {
            $this->migration()->up();
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/V039__enforce_contract_signer_identity.php');
    }

    private function dropIdentityGuards(): void
    {
        if (Schema::hasIndex('contract_signers', 'contract_signers_scope_normalized_email_unique')) {
            Schema::table('contract_signers', function ($table): void {
                $table->dropIndex('contract_signers_scope_normalized_email_unique');
            });
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_insert_guard ON contract_signers');
            DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_update_guard ON contract_signers');

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_insert_guard');
        DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_update_guard');
    }

    private function insertLegacySigner(
        string $id,
        string $contractId,
        string $email,
        string $personalToken,
        string $createdAt,
        string $updatedAt,
    ): void {
        DB::table('contract_signers')->insert([
            'id' => $id,
            'contract_id' => $contractId,
            'name' => 'Legacy signer',
            'email' => $email,
            'normalized_email' => null,
            'join_scope_key' => null,
            'roles' => json_encode(['Model']),
            'personal_token' => $personalToken,
            'status' => 'joined',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
    }
}
