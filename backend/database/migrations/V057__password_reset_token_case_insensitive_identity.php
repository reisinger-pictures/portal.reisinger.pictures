<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve MariaDB's case-insensitive reset-token primary-key identity on PostgreSQL.
 *
 * The cutover copies token rows verbatim, including a legacy mixed-case email.
 * A unique expression index on LOWER(email) lets that one row remain usable
 * while preventing a second slot for a case variant.
 */
return new class extends Migration
{
    private const TABLE = 'password_reset_tokens';

    private const UNIQUE_INDEX = 'password_reset_tokens_email_lower_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'email')) {
            throw new RuntimeException('V057 requires table [password_reset_tokens] with an [email] column.');
        }

        $duplicates = DB::select(
            'SELECT LOWER(email) AS normalized_email, COUNT(*) AS token_count'
            .' FROM '.self::TABLE
            .' GROUP BY LOWER(email)'
            .' HAVING COUNT(*) > 1'
        );

        if ($duplicates !== []) {
            $duplicateRows = array_sum(array_map(
                static fn (object $row): int => (int) $row->token_count,
                $duplicates,
            ));
            $duplicateGroups = count($duplicates);

            Log::error('V057 password-reset-token identity preflight found case-folded duplicates', [
                'duplicate_groups' => $duplicateGroups,
                'duplicate_rows' => $duplicateRows,
            ]);

            throw new RuntimeException(
                "V057 cannot enforce case-insensitive reset-token identity: {$duplicateGroups} case-folded groups across {$duplicateRows} rows exist. Resolve them before retrying; email addresses were not logged."
            );
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS '.self::UNIQUE_INDEX
                .' ON '.self::TABLE.' (LOWER(email))'
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // V001's primary key uses the configured case-insensitive column
            // collation and already enforces one token slot per email identity.
            return;
        }

        throw new RuntimeException('V057 does not support database driver ['.$driver.'].');
    }

    public function down(): void
    {
        // down() is never executed in this repository.
    }
};
