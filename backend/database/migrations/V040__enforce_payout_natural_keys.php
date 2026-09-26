<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce the operational natural keys used by the payout ledger.
 *
 * The current calculation API creates one aggregate pool for a calendar month
 * and one statement for a photographer in that month.  The old V011 schema
 * only had UUID primary keys, so concurrent writers could create financial
 * duplicates before either writer noticed the other one.
 *
 * This migration is intentionally fail-closed.  It reports existing duplicate
 * groups and aborts without deleting, merging, or rewriting a financial row.
 * An operator must reconcile the reported rows in a maintenance procedure and
 * retry the migration.  Only a clean preflight is followed by the two unique
 * indexes; those indexes are the durable race guard for future writers.
 *
 * V011 and all earlier migrations are already deployed and are not edited.
 */
return new class extends Migration
{
    private const POOL_INDEX = 'payout_pools_year_month_unique';

    private const STATEMENT_INDEX = 'photographer_statements_user_id_year_month_unique';

    private const STATEMENT_USER_ID_SUPPORT_INDEX = 'photographer_statements_user_id_fk_support';

    private const MAX_REPORT_GROUPS = 20;

    private const MAX_REPORT_IDS = 20;

    public function up(): void
    {
        $this->assertSupportedDriver();
        $this->assertTablesExist();

        // A completed replay is a no-op.  If only one index exists, both
        // tables still need a preflight before the missing index is repaired.
        if ($this->hasExpectedUniqueIndex('payout_pools', self::POOL_INDEX, ['year', 'month'])
            && $this->hasExpectedUniqueIndex(
                'photographer_statements',
                self::STATEMENT_INDEX,
                ['user_id', 'year', 'month'],
            )) {
            return;
        }

        $duplicates = [
            'payout_pools' => $this->findDuplicateGroups('payout_pools', ['year', 'month']),
            'photographer_statements' => $this->findDuplicateGroups(
                'photographer_statements',
                ['user_id', 'year', 'month'],
            ),
        ];
        $duplicates = array_filter(
            $duplicates,
            static fn (array $groups): bool => $groups !== [],
        );

        if ($duplicates !== []) {
            $report = $this->duplicateReport($duplicates);
            Log::error('V040 payout natural-key preflight failed', [
                'report' => $report,
            ]);

            throw new RuntimeException($report);
        }

        $this->ensureUniqueIndex('payout_pools', self::POOL_INDEX, ['year', 'month']);
        $this->ensureUniqueIndex(
            'photographer_statements',
            self::STATEMENT_INDEX,
            ['user_id', 'year', 'month'],
        );
    }

    public function down(): void
    {
        // down() wird nie ausgeführt (Policy).
    }

    private function assertSupportedDriver(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('V040 does not support database driver ['.$driver.'].');
        }
    }

    private function assertTablesExist(): void
    {
        foreach (['payout_pools', 'photographer_statements'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('V040 requires table ['.$table.'] before enforcing payout keys.');
            }
        }
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, array{key: list<string>, ids: list<string>}>
     */
    private function findDuplicateGroups(string $table, array $columns): array
    {
        $groups = [];
        $query = DB::table($table)->select([...$columns, 'id']);

        // MySQL/MariaDB UUID columns are commonly case-insensitive.  Binary
        // ordering makes the report deterministic without changing the
        // database's natural-key comparison semantics.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->orderByRaw('BINARY `id`');
        } else {
            $query->orderBy('id');
        }

        $query->chunk(500, function ($rows) use (&$groups, $columns): void {
            foreach ($rows as $row) {
                $keyValues = [];
                foreach ($columns as $column) {
                    $value = $row->{$column};
                    $keyValues[] = is_string($value)
                        ? strtolower(trim($value))
                        : trim((string) $value);
                }

                $key = implode("\0", $keyValues);
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'key' => $keyValues,
                        'ids' => [],
                    ];
                }

                $groups[$key]['ids'][] = (string) $row->id;
            }
        });

        ksort($groups, SORT_STRING);

        return array_filter(
            $groups,
            static fn (array $group): bool => count($group['ids']) > 1,
        );
    }

    /**
     * @param  array<string, array<string, array{key: list<string>, ids: list<string>}>>  $duplicates
     */
    private function duplicateReport(array $duplicates): string
    {
        $lines = [
            'V040 payout natural-key preflight failed: duplicate financial rows.',
            'No payout pools or statements were deleted or merged. Resolve the reported groups before retrying V040.',
        ];

        foreach ($duplicates as $table => $groups) {
            $totalGroups = count($groups);
            $totalRows = array_sum(array_map(
                static fn (array $group): int => count($group['ids']),
                $groups,
            ));
            $lines[] = sprintf(
                '%s duplicate_groups=%d duplicate_rows=%d',
                $table,
                $totalGroups,
                $totalRows,
            );

            foreach (array_slice($groups, 0, self::MAX_REPORT_GROUPS, true) as $group) {
                $ids = $group['ids'];
                sort($ids, SORT_STRING);
                $lines[] = sprintf(
                    '%s key=%s count=%d ids=%s',
                    $table,
                    implode('|', $group['key']),
                    count($ids),
                    implode(',', array_slice($ids, 0, self::MAX_REPORT_IDS)),
                );
            }

            if ($totalGroups > self::MAX_REPORT_GROUPS) {
                $lines[] = sprintf(
                    '%s ... %d additional duplicate groups omitted.',
                    $table,
                    $totalGroups - self::MAX_REPORT_GROUPS,
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureUniqueIndex(string $table, string $name, array $columns): void
    {
        if ($this->hasExpectedUniqueIndex($table, $name, $columns)) {
            return;
        }

        // A previous interrupted/manual attempt may have left an index with
        // the same name but the wrong uniqueness or column order.  Replace
        // only after the duplicate preflight above has succeeded.
        if (Schema::hasIndex($table, $name)) {
            if ($table === 'photographer_statements') {
                $this->ensureStatementUserIdSupportIndex($name);
            }

            Schema::table($table, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name): void {
            $table->unique($columns, $name);
        });
    }

    /**
     * MySQL/MariaDB require an index whose first column is the FK column.
     * The composite natural-key index can satisfy that requirement, but it
     * cannot be dropped and replaced while it is the only such index. Keep a
     * small alternate index in place before replacing a stale composite index.
     * PostgreSQL and SQLite do not require an FK-supporting index.
     */
    private function ensureStatementUserIdSupportIndex(string $replacedIndex): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (Schema::getIndexes('photographer_statements') as $index) {
            if (($index['name'] ?? null) === $replacedIndex) {
                continue;
            }

            $columns = $this->indexColumns($index);
            if (($columns[0] ?? null) === 'user_id') {
                return;
            }
        }

        if (Schema::hasIndex('photographer_statements', self::STATEMENT_USER_ID_SUPPORT_INDEX)) {
            return;
        }

        Schema::table('photographer_statements', function (Blueprint $table): void {
            $table->index(['user_id'], self::STATEMENT_USER_ID_SUPPORT_INDEX);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasExpectedUniqueIndex(string $table, string $name, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) !== $name) {
                continue;
            }

            if ((bool) ($index['unique'] ?? false)
                && $this->indexColumns($index) === array_map('strtolower', $columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $index
     * @return list<string>
     */
    private function indexColumns(array $index): array
    {
        $columns = $index['columns'] ?? [];
        if (is_string($columns)) {
            $columns = explode(',', $columns);
        }

        return array_map(
            static fn (mixed $column): string => strtolower(trim((string) $column)),
            $columns,
        );
    }
};
