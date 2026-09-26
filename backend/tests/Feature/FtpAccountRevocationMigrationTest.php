<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract coverage for V043, the `revoked` state of the camera account
 * lifecycle (§7.16).
 *
 * Two things are load-bearing and neither is visible from the source alone:
 * the enum has to accept `revoked` on SQLite as well — where the closed set is
 * a `CHECK` constraint, not a column type — and a replay of the migration must
 * not disturb existing rows. Tests run on SQLite `:memory:`, so the first is
 * exactly the case that would otherwise break every test boot.
 */
class FtpAccountRevocationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v043_adds_the_revoked_state_and_the_timestamp(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'ftp_revoked_at'));

        $columns = collect(Schema::getColumns('users'))->keyBy('name');
        $this->assertTrue((bool) $columns['ftp_revoked_at']['nullable']);

        // SQLite reports the enum as a plain varchar, so the widened closed set
        // is pinned on the migration source; the behavioural test below is what
        // proves the constraint actually accepts `revoked`.
        $source = $this->migrationSource();
        $this->assertStringContainsString("'revoked'", $source);
        $this->assertStringContainsString('ftp_revoked_at', $source);

        // MySQL/MariaDB keeps the set as a type and is the only driver where the
        // database itself names the four values.
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertStringContainsString(
                "enum('pending','active','error','revoked')",
                (string) $columns['ftp_account_status']['type'],
            );
        }
    }

    public function test_v043_accepts_revoked_and_still_rejects_unknown_states(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'ftp_account_status' => 'revoked',
            'ftp_revoked_at' => now(),
        ])->save();

        $revoked = $user->fresh();
        $this->assertSame('revoked', $revoked->ftp_account_status);
        $this->assertNotNull($revoked->ftp_revoked_at);

        // The widening must not have *removed* the closed set: on SQLite the
        // rebuild re-emits the CHECK constraint, and a value outside it is still
        // a constraint violation. This is the regression guard against a
        // "widen" that quietly turns the column into free text.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->expectException(QueryException::class);
            $user->forceFill(['ftp_account_status' => 'nonsense'])->save();
        }
    }

    public function test_v043_replay_on_a_migrated_schema_changes_nothing(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'ftp_account_status' => 'active',
            'ftp_provisioned_at' => now(),
        ])->save();
        $provisionedAt = $user->fresh()->ftp_provisioned_at;

        $this->migration()->up();

        $replayed = $user->fresh();
        $this->assertSame('active', $replayed->ftp_account_status);
        $this->assertTrue($provisionedAt->equalTo($replayed->ftp_provisioned_at));
        $this->assertNull($replayed->ftp_revoked_at);

        // And the widened set is still accepted after the replay.
        $replayed->forceFill([
            'ftp_account_status' => 'revoked',
            'ftp_revoked_at' => now(),
        ])->save();
        $this->assertSame('revoked', $replayed->fresh()->ftp_account_status);
    }

    public function test_the_revoked_state_and_timestamp_are_not_mass_assignable(): void
    {
        // $fillable discipline: provisioning state is written by the lifecycle
        // path, never by request input. A factory would not prove this —
        // factories run inside Model::unguarded().
        $user = (new User)->fill([
            'ftp_account_status' => 'revoked',
            'ftp_revoked_at' => now(),
        ]);

        $this->assertNull($user->ftp_account_status);
        $this->assertNull($user->ftp_revoked_at);
        $this->assertNotContains('ftp_revoked_at', (new User)->getFillable());
    }

    private function migration(): object
    {
        return require database_path('migrations/V043__add_ftp_account_revocation.php');
    }

    private function migrationSource(): string
    {
        $source = file_get_contents((new \ReflectionClass($this->migration()))->getFileName());
        $this->assertIsString($source);
        $this->assertNotFalse($source);

        return $source;
    }
}
