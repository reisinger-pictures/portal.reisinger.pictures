<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve MariaDB's case-insensitive organization-domain identity on PostgreSQL.
 *
 * The existing MariaDB unique key uses the configured case-insensitive column
 * collation. PostgreSQL's ordinary unique key is case-sensitive, so it needs a
 * unique expression index on LOWER(domain). Before adding that invariant, fail
 * closed if legacy rows collide; the migration never picks an organization to
 * keep or rewrites the administrator-entered spelling.
 */
return new class extends Migration
{
    private const TABLE = 'orgs';

    private const UNIQUE_INDEX = 'orgs_domain_lower_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'domain')) {
            throw new RuntimeException('V056 requires table [orgs] with a [domain] column.');
        }

        $duplicates = DB::select(
            'SELECT LOWER(domain) AS normalized_domain, COUNT(*) AS domain_count'
            .' FROM '.self::TABLE
            .' WHERE domain IS NOT NULL'
            .' GROUP BY LOWER(domain)'
            .' HAVING COUNT(*) > 1'
            .' ORDER BY normalized_domain'
        );

        if ($duplicates !== []) {
            $report = collect($duplicates)
                ->map(static fn (object $row): string => sprintf(
                    'normalized_domain=%s count=%d',
                    (string) $row->normalized_domain,
                    (int) $row->domain_count,
                ))
                ->implode('; ');

            Log::error('V056 organization-domain case-insensitive uniqueness preflight failed', [
                'duplicates' => $report,
            ]);

            throw new RuntimeException(
                'V056 cannot enforce case-insensitive organization domains because case-folded duplicates exist: '.$report
            );
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS '.self::UNIQUE_INDEX
                .' ON '.self::TABLE.' (LOWER(domain))'
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // The column's existing unique index and utf8mb4_unicode_ci
            // collation already enforce the case-insensitive identity here.
            return;
        }

        throw new RuntimeException('V056 does not support database driver ['.$driver.'].');
    }

    public function down(): void
    {
        // down() is never executed in this repository.
    }
};
