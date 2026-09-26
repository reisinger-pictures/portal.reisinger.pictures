<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FtpPasswordReset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contract coverage for V042, the reset audit trail (P1-M33).
 *
 * The table is the record of who rotated a camera credential, so its shape is
 * load-bearing in the same way V041's is for the read path: a nullable `success`
 * would make "did not attempt" indistinguishable from "did not work", and a
 * missing IP length would truncate a real IPv6 address on insert.
 */
class FtpPasswordResetMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v042_installs_the_audit_columns(): void
    {
        $this->assertTrue(Schema::hasTable('ftp_password_resets'));

        $columns = collect(Schema::getColumns('ftp_password_resets'))->keyBy('name');

        $this->assertSame(
            ['id', 'user_id', 'ip', 'success', 'reset_at'],
            $columns->keys()->all(),
            'The trail is exactly the four audited facts plus its key — a column '
            .'that could hold a password must never appear here.',
        );

        $this->assertFalse((bool) $columns['success']['nullable'], 'The outcome is mandatory.');
        $this->assertFalse((bool) $columns['reset_at']['nullable'], 'The time is mandatory.');

        // 45 is the longest textual IPv6 form, the same choice
        // `contract_audit_logs` makes. A narrower column would silently truncate.
        // SQLite does not report a column length at all, so there the width is
        // pinned on the migration source and the behavioural check below (a full
        // IPv6 address round-trips) carries the real proof on that driver.
        $this->assertTrue(
            (bool) $columns['ip']['nullable'],
            'A console or job reset has no address, and a placeholder would be a lie.',
        );
        $this->assertStringContainsString(
            "->string('ip', 45)->nullable()",
            $this->migrationSource(),
        );

        // `useCurrent()` is the guarantee for a writer that forgets the column: a
        // row is never left without a time, which is the one fact the whole
        // table exists to record.
        $this->assertSame(
            'CURRENT_TIMESTAMP',
            trim((string) $columns['reset_at']['default'], "'\" \t"),
        );

        // `reset_at` is a single timestamp column, so the model must not also try
        // to write Eloquent's created_at/updated_at into columns that do not
        // exist. Pinned through the model constants below.
        $this->assertNull(FtpPasswordReset::CREATED_AT);
        $this->assertNull(FtpPasswordReset::UPDATED_AT);
    }

    public function test_a_row_round_trips_through_the_model(): void
    {
        $user = $this->photographer();

        FtpPasswordReset::query()->create([
            'user_id' => $user->id,
            'reset_at' => now(),
            'ip' => '2001:db8:85a3::8a2e:370:7334',
            'success' => true,
        ]);

        $row = FtpPasswordReset::query()->sole();

        $this->assertTrue($row->exists);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('2001:db8:85a3::8a2e:370:7334', $row->ip, 'A full IPv6 address must fit.');
        $this->assertTrue($row->success, 'success must be cast to a real boolean, not 0/1.');
        $this->assertInstanceOf(Carbon::class, $row->reset_at);
    }

    /**
     * The index serves the only query the table exists for: one account's resets,
     * newest first. Without it the trail degrades into a full scan that grows
     * with every reset on the installation.
     */
    public function test_the_composite_index_covers_the_reading_query(): void
    {
        $indexes = collect(Schema::getIndexes('ftp_password_resets'))
            ->map(fn (array $index): array => $index['columns'])
            ->all();

        $this->assertContains(
            ['user_id', 'reset_at'],
            $indexes,
            'The reading query is "this account, newest first".',
        );
    }

    public function test_the_account_reference_is_enforced_and_cascades(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('ftp_password_resets'))->first();

        $this->assertIsArray($foreignKeys);
        $this->assertSame(['user_id'], $foreignKeys['columns']);
        $this->assertSame('users', $foreignKeys['foreign_table']);

        // An audit row without an account would be unattributable, and a row that
        // outlives a deleted account would be a dangling reference — so the
        // account goes with the user.
        $this->assertSame('cascade', strtolower((string) $foreignKeys['on_delete']));

        $user = $this->photographer();
        FtpPasswordReset::query()->create([
            'user_id' => $user->id,
            'reset_at' => now(),
            'ip' => null,
            'success' => false,
        ]);

        $user->forceDelete();

        $this->assertSame(0, FtpPasswordReset::query()->count());
    }

    public function test_a_row_without_an_account_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('ftp_password_resets')->insert([
            'user_id' => (string) Str::uuid(),
            'reset_at' => now(),
            'ip' => '203.0.113.7',
            'success' => true,
        ]);
    }

    public function test_the_table_starts_empty_for_existing_accounts(): void
    {
        // No backfill: a row can only mean "the portal witnessed a reset at that
        // moment", which is not reconstructible for the past. A backfilled row
        // would assert something nobody observed.
        $this->photographer();

        $this->assertSame(0, FtpPasswordReset::query()->count());
    }

    public function test_v042_rollback_drops_the_table(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('ftp_password_resets'));

        // Re-running up() must restore a working trail, so a rollback is not a
        // one-way door for the migration itself.
        try {
            $this->migration()->up();
            $this->assertTrue(Schema::hasTable('ftp_password_resets'));
        } finally {
            $this->migration()->up();
        }
    }

    public function test_v042_replay_on_a_migrated_schema_is_a_no_op(): void
    {
        $user = $this->photographer();
        FtpPasswordReset::query()->create([
            'user_id' => $user->id,
            'reset_at' => now(),
            'ip' => '203.0.113.7',
            'success' => true,
        ]);

        // A replay after a partially committed MySQL/MariaDB run must continue
        // instead of failing on an existing table — and must not drop the rows
        // that are already there.
        $this->migration()->up();

        $this->assertSame(1, FtpPasswordReset::query()->count());
        $this->assertTrue(FtpPasswordReset::query()->sole()->success);
    }

    public function test_a_retry_after_a_partial_creation_recovers(): void
    {
        // Drop the index but keep the table, which is what a run that stopped
        // between the CREATE and the index would leave behind on MySQL.
        Schema::table('ftp_password_resets', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'reset_at']);
        });

        try {
            $this->migration()->up();

            $this->assertTrue(Schema::hasTable('ftp_password_resets'));
        } finally {
            $this->migration()->up();
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/V042__add_ftp_password_resets_audit_table.php');
    }

    private function migrationSource(): string
    {
        $source = file_get_contents((new \ReflectionClass($this->migration()))->getFileName());
        $this->assertIsString($source);
        $this->assertNotFalse($source);

        return $source;
    }

    private function photographer(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        return $user->fresh();
    }
}
