<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `revoked` state to the FTP/SFTP camera account lifecycle (§7.16).
 *
 * V041 modelled `pending|active|error`. A photographer who stops being one
 * needs a fourth value: reusing `pending` would claim "never provisioned" and
 * invite a new credential request, `error` would claim a provisioning failure.
 * Both would be lies, and the audit could not tell "never had" from "had and
 * lost". `ftp_revoked_at` is the mirror of `ftp_provisioned_at`; it is written
 * on the day the account ends while `ftp_provisioned_at` stays — together they
 * read "provisioned at X, revoked at Y".
 *
 * Schema decision, documented up front as required by backend/AGENTS.md:
 * - `ftp_account_status`  enum gains `revoked` (widened, no row touched).
 * - `ftp_revoked_at`      timestamp, nullable.
 * - Backfill: none. No account was ever revoked before this migration, so
 *   every stored value remains valid — there is nothing to reconstruct.
 *
 * Driver behaviour of the widening step, verified on this repo's Laravel
 * (13.x, native schema editing — no doctrine/dbal) rather than assumed:
 * - MariaDB is the driver that stores the closed set as a column type, so
 *   `change()` compiles to `ALTER TABLE users MODIFY ftp_account_status
 *   ENUM('pending','active','error','revoked') NOT NULL DEFAULT 'pending'`.
 * - SQLite cannot MODIFY a column; Laravel rebuilds the table and re-emits the
 *   column as `varchar ... CHECK (ftp_account_status in ('pending','active',
 *   'error','revoked')) ...`. The rebuild is **required**, not cosmetic: the
 *   enum V041 added already carries a `CHECK` constraint with the three old
 *   values, so without it SQLite rejects `revoked` with a constraint
 *   violation. Because tests run on SQLite `:memory:`, this runs on every test
 *   boot — the reason it is probed here instead of trusted.
 *
 * Rollback: narrowing the enum and dropping the column. Only meaningful while
 * no row is `revoked`, and the state exists exactly for that case, so it is not
 * rolled back in normal operation. `down()` stays empty per repo rule.
 *
 * Idempotency: the widening is skipped once the enum accepts `revoked`, so a
 * replay of this migration touches neither the schema nor any row. MariaDB
 * auto-commits DDL, so a retry after a partial run has to be a no-op and not an
 * error.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded, not unconditional: an unguarded `change()` would rewrite the
        // enum (and rebuild the users table on SQLite) on every replay. The
        // column itself comes from V041, which runs before this file.
        if (Schema::hasColumn('users', 'ftp_account_status') && ! $this->statusAcceptsRevoked()) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE users DROP CONSTRAINT users_ftp_account_status_check');
                DB::statement("ALTER TABLE users ALTER COLUMN ftp_account_status TYPE VARCHAR(255), ALTER COLUMN ftp_account_status SET DEFAULT 'pending'");
                DB::statement("ALTER TABLE users ADD CONSTRAINT users_ftp_account_status_check CHECK (ftp_account_status IN ('pending', 'active', 'error', 'revoked'))");
            } else {
                Schema::table('users', function (Blueprint $table): void {
                    $table->enum('ftp_account_status', ['pending', 'active', 'error', 'revoked'])
                        ->default('pending')
                        ->change();
                });
            }
        }

        if (! Schema::hasColumn('users', 'ftp_revoked_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('ftp_revoked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Never executed in this repository; the rollback decision is documented
        // in the docblock and the state exists for the case being rolled back.
    }

    /**
     * Whether the stored closed set already contains `revoked`.
     *
     * MariaDB reports it inside the column type (`enum(...,'revoked')`). SQLite
     * reports a plain `varchar`; there the set lives in the table DDL as a
     * `CHECK` constraint, so the DDL is the only place to ask.
     */
    private function statusAcceptsRevoked(): bool
    {
        $column = collect(Schema::getColumns('users'))->keyBy('name')['ftp_account_status'] ?? null;

        if ($column === null) {
            return false;
        }

        if (str_contains((string) $column['type'], 'revoked')) {
            return true;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return str_contains($this->sqliteTableSql('users'), "'revoked'");
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $definition = DB::scalar(
                'SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?',
                ['users', 'users_ftp_account_status_check'],
            );

            return str_contains((string) $definition, 'revoked');
        }

        return false;
    }

    private function sqliteTableSql(string $table): string
    {
        $row = DB::selectOne(
            'select sql from sqlite_master where type = ? and name = ?',
            ['table', $table],
        );

        return is_object($row) && isset($row->sql) ? (string) $row->sql : '';
    }
};
