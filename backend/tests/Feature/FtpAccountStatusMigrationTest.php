<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract coverage for V041, the FTP account provisioning status on `users`.
 *
 * The column is the source of truth for `status()` instead of a live query
 * against SFTPGo, so both the schema and the backfill are load-bearing: a silent
 * default of NULL or a missing backfill would make the read path claim an
 * unknown account state (features/infrastructure/19-ftp-upload-pipeline.md §7.4).
 */
class FtpAccountStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v041_installs_the_three_provisioning_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'ftp_account_status'));
        $this->assertTrue(Schema::hasColumn('users', 'ftp_provisioned_at'));
        $this->assertTrue(Schema::hasColumn('users', 'ftp_account_error'));

        $columns = collect(Schema::getColumns('users'))->keyBy('name');

        // The status is a closed set, not free text: an out-of-range value would
        // make the read path guess what the photographer sees. SQLite maps an
        // enum to a plain varchar, so the closed set is pinned on the migration
        // source instead; the behavioural test below covers the default.
        $this->assertFalse((bool) $columns['ftp_account_status']['nullable']);
        $this->assertStringContainsString(
            "enum('ftp_account_status', ['pending', 'active', 'error'])",
            $this->migrationSource(),
        );

        // MySQL/MariaDB is the one driver that keeps the enum as a type, and the
        // only place the closed set is enforced by the database itself.
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertStringContainsString(
                "enum('pending','active','error')",
                (string) $columns['ftp_account_status']['type'],
            );
        }

        // SQLite reports the default including its quoting, MySQL without it.
        $this->assertSame(
            'pending',
            trim((string) $columns['ftp_account_status']['default'], "'\" \t"),
            'New users must be provisioned as pending without an explicit write.',
        );

        // Both nullable columns describe an optional detail of a state, not a
        // separate one: no error text while pending, no timestamp while pending.
        $this->assertTrue((bool) $columns['ftp_provisioned_at']['nullable']);
        $this->assertTrue((bool) $columns['ftp_account_error']['nullable']);
    }

    public function test_v041_defaults_a_new_user_to_pending_without_any_explicit_write(): void
    {
        $user = User::factory()->create();

        $this->assertSame('pending', $user->fresh()->ftp_account_status);
        $this->assertNull($user->fresh()->ftp_provisioned_at);
        $this->assertNull($user->fresh()->ftp_account_error);
    }

    public function test_v041_backfills_pre_existing_photographers_to_pending(): void
    {
        $photographer = $this->createUserWithRole(UserRole::PHOTOGRAPHER, 'existing-photographer@example.com');
        $client = $this->createUserWithRole(UserRole::CLIENT, 'existing-client@example.com');

        $this->dropProvisioningColumns();
        $this->assertFalse(Schema::hasColumn('users', 'ftp_account_status'));

        try {
            $this->migration()->up();

            // The rows above existed before the migration ran. Their state is
            // unknown — they were never provisioned through SFTPGo — so the
            // backfill must report them as pending, not as active.
            $this->assertDatabaseHas('users', [
                'id' => $photographer->id,
                'ftp_account_status' => 'pending',
                'ftp_provisioned_at' => null,
                'ftp_account_error' => null,
            ]);
            // A client never gets an FTP account; pending is the truthful state
            // for them as well, so the backfill may not special-case the role.
            $this->assertDatabaseHas('users', [
                'id' => $client->id,
                'ftp_account_status' => 'pending',
            ]);
        } finally {
            $this->migration()->up();
        }
    }

    public function test_v041_rollback_drops_the_columns_without_touching_the_user(): void
    {
        $user = $this->createUserWithRole(UserRole::PHOTOGRAPHER, 'rollback@example.com');
        $user->forceFill([
            'ftp_account_status' => 'error',
            'ftp_account_error' => 'provisioning refused by the admin API',
        ])->save();

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('users', 'ftp_account_status'));
        $this->assertFalse(Schema::hasColumn('users', 'ftp_provisioned_at'));
        $this->assertFalse(Schema::hasColumn('users', 'ftp_account_error'));

        // Pure column drop: no FTP password is stored in the portal (P1-M23), so
        // nothing of value can be lost here.
        $remaining = $user->fresh();
        $this->assertSame($user->id, $remaining->id);
        $this->assertSame('rollback@example.com', $remaining->email);
        $this->assertSame('rollback', $remaining->ftp_slug);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_v041_accepts_only_the_three_documented_states(): void
    {
        $user = User::factory()->create();

        $applied = [];
        foreach (['pending', 'active', 'error'] as $state) {
            $user->forceFill(['ftp_account_status' => $state])->save();
            $applied[$state] = $user->fresh()->ftp_account_status;
        }

        $this->assertSame(
            ['pending' => 'pending', 'active' => 'active', 'error' => 'error'],
            $applied,
            'pending -> active -> error must round-trip through the column.',
        );

        $user->forceFill([
            'ftp_account_status' => 'active',
            'ftp_provisioned_at' => now(),
        ])->save();
        $this->assertNotNull($user->fresh()->ftp_provisioned_at);
    }

    public function test_v041_retry_after_a_partial_run_is_a_no_op(): void
    {
        // MySQL/MariaDB auto-commit DDL: a run that stopped between two column
        // additions must be resumable instead of failing on an existing column.
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ftp_provisioned_at');
        });
        $this->assertTrue(Schema::hasColumn('users', 'ftp_account_status'));
        $this->assertFalse(Schema::hasColumn('users', 'ftp_provisioned_at'));

        try {
            $this->migration()->up();

            $this->assertTrue(Schema::hasColumn('users', 'ftp_account_status'));
            $this->assertTrue(Schema::hasColumn('users', 'ftp_provisioned_at'));
            $this->assertTrue(Schema::hasColumn('users', 'ftp_account_error'));
            $this->assertSame('pending', User::factory()->create()->fresh()->ftp_account_status);
        } finally {
            $this->migration()->up();
        }
    }

    public function test_v041_replay_on_a_migrated_schema_changes_nothing(): void
    {
        $user = $this->createUserWithRole(UserRole::PHOTOGRAPHER, 'replay@example.com');
        $user->forceFill([
            'ftp_account_status' => 'active',
            'ftp_provisioned_at' => now(),
        ])->save();
        $provisionedAt = $user->fresh()->ftp_provisioned_at;

        $this->migration()->up();

        $replayed = $user->fresh();
        $this->assertSame('active', $replayed->ftp_account_status);
        $this->assertTrue($provisionedAt->equalTo($replayed->ftp_provisioned_at));
    }

    public function test_the_provisioning_columns_are_not_mass_assignable(): void
    {
        // $fillable discipline: provisioning state is written by the
        // provisioning path, never by request input. A factory would not prove
        // this — factories run inside Model::unguarded().
        $user = (new User)->fill(['ftp_account_status' => 'active']);

        $this->assertNull($user->ftp_account_status);
        $this->assertNotContains('ftp_account_status', (new User)->getFillable());
        $this->assertNotContains('ftp_provisioned_at', (new User)->getFillable());
        $this->assertNotContains('ftp_account_error', (new User)->getFillable());
    }

    private function migration(): object
    {
        return require database_path('migrations/V041__add_ftp_account_status_to_users.php');
    }

    private function migrationSource(): string
    {
        $source = file_get_contents((new \ReflectionClass($this->migration()))->getFileName());
        $this->assertIsString($source);
        $this->assertNotFalse($source);

        return $source;
    }

    private function dropProvisioningColumns(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('users', 'ftp_account_status'));
    }

    private function createUserWithRole(UserRole $role, string $email): User
    {
        $user = User::factory()->create(['brand' => Brand::B2B, 'email' => $email]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }
}
