<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PostgresCopy extends Command
{
    protected $signature = 'portal:db-copy
        {--source=mariadb : Source Laravel connection}
        {--target=pgsql : Target Laravel connection}
        {--source-host= : Optional source host override for the cutover utility container}
        {--target-host= : Optional target host override for the cutover utility container}
        {--source-port= : Optional source port override}
        {--target-port= : Optional target port override}
        {--chunk=500 : Rows per stable-key batch}
        {--verify-only : Verify rows and invariants without writing}';

    protected $description = 'Copy MariaDB rows to PostgreSQL and verify cutover invariants.';

    /** @var list<string> */
    private array $issues = [];

    /** @var array<string, list<array{data_type: string, column_type: string}>> */
    private array $sourceColumnMetadata = [];

    /** @var array<string, list<string>> */
    private array $sharedColumns = [];

    /** @var array<string, list<string>> */
    private array $primaryKeys = [];

    private readonly PostgresCopyValueNormalizer $normalizer;

    /** @var array<string, list<string>> */
    private const MONEY_COLUMNS = [
        'orders' => [
            'total_amount', 'coupon_discount_cents', 'stripe_fee_cents', 'refunded_amount_cents',
        ],
        'invoice_snapshots' => ['total_net', 'total_gross'],
        'products' => ['price'],
        'license_use_cases' => ['base_price'],
        'volume_preset_tiers' => ['price_cents'],
        'coupons' => ['package_price_cents'],
        'payout_pools' => [
            'gross_amount_cents', 'stripe_fee_cents', 'net_pool_cents', 'value_per_share_cents',
        ],
        'photographer_statements' => [
            'pool_earnings_cents', 'delta_surcharge_earnings_cents', 'earned_amount_cents',
            'rolled_over_amount_cents', 'total_payable_cents',
        ],
        'payout_corrections' => ['refund_amount_cents', 'amount_cents'],
        'payout_open_items' => ['amount_cents'],
        'payout_order_bookings' => ['share_cents', 'photo_revenue_cents', 'refunded_cents'],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->normalizer = new PostgresCopyValueNormalizer;
    }

    public function handle(): int
    {
        $sourceName = (string) $this->option('source');
        $targetName = (string) $this->option('target');
        $verifyOnly = (bool) $this->option('verify-only');

        try {
            if ($sourceName !== 'mariadb' || $targetName !== 'pgsql') {
                throw new RuntimeException('This cutover command requires --source=mariadb and --target=pgsql.');
            }

            $this->applyConnectionOverrides($sourceName, 'source');
            $this->applyConnectionOverrides($targetName, 'target');

            $source = DB::connection($sourceName);
            $target = DB::connection($targetName);
            // MariaDB TIMESTAMP values are converted by the connection's
            // session time zone. Pin both sides so a host-local zone cannot
            // change the copied instant. These are connection-local settings;
            // verify-only still performs no table or sequence writes.
            $source->statement("SET SESSION time_zone = '+00:00'");
            $target->statement("SET TIME ZONE 'UTC'");
            [$sourceTables, $targetTables, $schemaErrors] = $this->inspectSchema($source, $target);

            foreach ($schemaErrors as $schemaError) {
                $this->issues[] = $schemaError;
                $this->error($schemaError);
            }

            if ($schemaErrors !== []) {
                throw new RuntimeException('Source and target schemas do not agree.');
            }

            if (! $verifyOnly) {
                $this->copyRows($source, $target, $sourceTables, max(1, (int) $this->option('chunk')));
                $this->resetSequences($target, $targetTables);
            }

            $this->verify($source, $target, $sourceTables, $targetTables);
        } catch (Throwable $exception) {
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;
            $this->issues[] = $message;
            $this->error($message);
        }

        $this->newLine();
        $this->line('=== portal:db-copy summary ===');
        $this->line('Checks: '.($this->issues === [] ? 'all passed' : count($this->issues).' failed'));
        $this->line('Result: '.($this->issues === [] ? 'PASS' : 'FAIL'));
        $this->line('==============================');

        return $this->issues === [] ? self::SUCCESS : self::FAILURE;
    }

    private function applyConnectionOverrides(string $connection, string $side): void
    {
        $host = $this->option($side.'-host');
        $port = $this->option($side.'-port');

        if (is_string($host) && $host !== '') {
            config([
                "database.connections.{$connection}.host" => $host,
                "database.connections.{$connection}.url" => null,
            ]);
        }

        if (is_string($port) && $port !== '') {
            config([
                "database.connections.{$connection}.port" => $port,
                "database.connections.{$connection}.url" => null,
            ]);
        }

        if ((is_string($host) && $host !== '') || (is_string($port) && $port !== '')) {
            DB::purge($connection);
        }
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function inspectSchema(Connection $source, Connection $target): array
    {
        $sourceDatabase = (string) $source->getDatabaseName();
        $sourceRows = $source->select(
            "SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            [$sourceDatabase],
        );
        $targetRows = $target->select(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name",
        );

        $targetTableNames = [];
        foreach ($targetRows as $row) {
            $targetTableNames[] = (string) $row->table_name;
        }
        $targetTables = array_fill_keys($targetTableNames, true);

        $sourceTables = [];
        $errors = [];

        foreach ($sourceRows as $row) {
            $table = (string) $row->table_name;

            if ($table === 'migrations') {
                continue;
            }

            $sourceTables[] = $table;

            if (! isset($targetTables[$table])) {
                $errors[] = "Target is missing source table '{$table}'.";

                continue;
            }

            $sourceColumns = $this->sourceColumns($source, $sourceDatabase, $table);
            $targetColumns = $this->targetColumns($target, $table);
            $sourceNames = array_keys($sourceColumns);
            $targetNames = array_keys($targetColumns);
            $missingTargetColumns = array_values(array_diff($sourceNames, $targetNames));

            foreach ($missingTargetColumns as $column) {
                $errors[] = "Target is missing source column '{$table}.{$column}'.";
            }

            $this->assertRequiredTargetColumns($table, $sourceNames, $targetColumns, $errors);
            $this->sourceColumnMetadata[$table] = $sourceColumns;
            $this->sharedColumns[$table] = array_values(array_intersect($sourceNames, $targetNames));
            $this->primaryKeys[$table] = $this->primaryKeyColumns($source, $sourceDatabase, $table);

            if ($this->primaryKeys[$table] === []) {
                $errors[] = "Source table '{$table}' has no primary key for stable chunking.";
            }
        }

        return [$sourceTables, $targetTableNames, $errors];
    }

    /**
     * @return array<string, array{data_type: string, column_type: string}>
     */
    private function sourceColumns(Connection $source, string $database, string $table): array
    {
        $rows = $source->select(
            'SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type '
            .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$database, $table],
        );
        $columns = [];

        foreach ($rows as $row) {
            $columns[(string) $row->column_name] = [
                'data_type' => (string) $row->data_type,
                'column_type' => (string) $row->column_type,
            ];
        }

        return $columns;
    }

    /**
     * @return array<string, array{data_type: string, nullable: bool, has_default: bool, generated: bool}>
     */
    private function targetColumns(Connection $target, string $table): array
    {
        $rows = $target->select(
            'SELECT column_name, data_type, is_nullable, column_default, is_generated, is_identity '
            .'FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        );
        $columns = [];

        foreach ($rows as $row) {
            $columns[(string) $row->column_name] = [
                'data_type' => (string) $row->data_type,
                'nullable' => strtoupper((string) $row->is_nullable) === 'YES',
                'has_default' => $row->column_default !== null,
                'generated' => strtoupper((string) $row->is_generated) !== 'NEVER'
                    || strtoupper((string) $row->is_identity) === 'YES',
            ];
        }

        return $columns;
    }

    /**
     * @param  list<string>  $sourceNames
     * @param  array<string, array{data_type: string, nullable: bool, has_default: bool, generated: bool}>  $targetColumns
     * @param  list<string>  $errors
     */
    private function assertRequiredTargetColumns(string $table, array $sourceNames, array $targetColumns, array &$errors): void
    {
        foreach ($targetColumns as $column => $metadata) {
            if (in_array($column, $sourceNames, true)) {
                continue;
            }

            if (! $metadata['nullable'] && ! $metadata['has_default'] && ! $metadata['generated']) {
                $errors[] = "Target-only required column '{$table}.{$column}' has no default.";
            }
        }
    }

    /** @return list<string> */
    private function primaryKeyColumns(Connection $source, string $database, string $table): array
    {
        $rows = $source->select(
            'SELECT COLUMN_NAME AS column_name FROM information_schema.KEY_COLUMN_USAGE '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = \'PRIMARY\' ORDER BY ORDINAL_POSITION',
            [$database, $table],
        );

        return array_map(static fn (object $row): string => (string) $row->column_name, $rows);
    }

    /** @param list<string> $tables */
    private function copyRows(Connection $source, Connection $target, array $tables, int $chunkSize): void
    {
        $originalRole = trim((string) ($target->selectOne('SHOW session_replication_role')->session_replication_role ?? ''));

        if (! in_array($originalRole, ['origin', 'replica', 'local'], true)) {
            throw new RuntimeException('PostgreSQL returned an unexpected session_replication_role.');
        }

        $target->statement('SET SESSION session_replication_role = replica');
        $copyFailure = null;
        $restoreFailure = null;

        try {
            foreach ($tables as $table) {
                $columns = $this->sharedColumns[$table] ?? [];
                $keys = $this->primaryKeys[$table] ?? [];

                if ($columns === [] || $keys === []) {
                    throw new RuntimeException("Cannot copy '{$table}' without shared columns and a primary key.");
                }

                $cursor = null;
                $copied = 0;

                do {
                    $query = $source->table($table)->select($columns);

                    if (is_array($cursor)) {
                        $this->applyAfterCursor($query, $keys, $cursor);
                    }

                    foreach ($keys as $key) {
                        $query->orderBy($key);
                    }

                    $rows = $query->limit($chunkSize)->get();

                    if ($rows->isEmpty()) {
                        break;
                    }

                    $batch = [];
                    foreach ($rows as $row) {
                        $record = [];

                        foreach ($columns as $column) {
                            $targetType = $this->targetColumnType($target, $table, $column);
                            $record[$column] = $this->normalizer->normalize(
                                $row->{$column},
                                $this->sourceColumnMetadata[$table][$column],
                                $targetType,
                            );
                        }

                        $batch[] = $record;
                    }

                    $target->table($table)->insertOrIgnore($batch);
                    $last = $rows->last();
                    $cursor = [];

                    foreach ($keys as $key) {
                        $cursor[] = $last->{$key};
                    }

                    $copied += count($batch);
                } while (count($rows) === $chunkSize);

                $this->line("Copied {$copied} source rows from {$table}.");
            }
        } catch (Throwable $exception) {
            $copyFailure = $exception;
        } finally {
            try {
                $target->statement('SET SESSION session_replication_role = '.$originalRole);
            } catch (Throwable $exception) {
                $restoreFailure = $exception;
            }
        }

        if ($restoreFailure !== null) {
            throw new RuntimeException(
                'Failed to restore PostgreSQL session_replication_role: '.$restoreFailure->getMessage(),
                previous: $copyFailure ?? $restoreFailure,
            );
        }

        if ($copyFailure !== null) {
            throw $copyFailure;
        }
    }

    /** @param list<string> $keys
     * @param  list<mixed>  $cursor
     */
    private function applyAfterCursor(Builder $query, array $keys, array $cursor): void
    {
        $query->where(function (Builder $outer) use ($keys, $cursor): void {
            foreach ($keys as $index => $key) {
                $outer->orWhere(function (Builder $branch) use ($keys, $cursor, $index, $key): void {
                    for ($prefix = 0; $prefix < $index; $prefix++) {
                        $branch->where($keys[$prefix], '=', $cursor[$prefix]);
                    }

                    $branch->where($key, '>', $cursor[$index]);
                });
            }
        });
    }

    private function targetColumnType(Connection $target, string $table, string $column): string
    {
        static $types = [];
        $key = $table.'.'.$column;

        if (! array_key_exists($key, $types)) {
            $row = $target->selectOne(
                'SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$table, $column],
            );
            $types[$key] = strtolower((string) ($row->data_type ?? ''));
        }

        return $types[$key];
    }

    /** @param list<string> $tables */
    private function resetSequences(Connection $target, array $tables): void
    {
        foreach ($tables as $table) {
            $hasId = $target->selectOne(
                'SELECT 1 AS present FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$table, 'id'],
            ) !== null;
            if (! $hasId) {
                continue;
            }

            $sequence = $target->selectOne(
                'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
                [$table, 'id'],
            );

            if (($sequence->sequence_name ?? null) === null) {
                continue;
            }

            $wrappedTable = $target->getQueryGrammar()->wrapTable($table);
            $target->selectOne(
                'SELECT setval(pg_get_serial_sequence(?, ?), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) '
                .'FROM '.$wrappedTable,
                [$table, 'id'],
            );
        }
    }

    /** @param list<string> $tables */
    private function verify(Connection $source, Connection $target, array $sourceTables, array $targetTables): void
    {
        $this->verifyRowCounts($source, $target, $sourceTables);
        $this->verifyMoneySums($source, $target);
        $this->verifySequences($target, $targetTables);
        $this->verifyForeignKeys($target);
    }

    /** @param list<string> $tables */
    private function verifyRowCounts(Connection $source, Connection $target, array $tables): void
    {
        foreach ($tables as $table) {
            $sourceCount = $source->table($table)->count();
            $targetExists = $target->selectOne(
                'SELECT 1 AS present FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
                [$table],
            ) !== null;

            if (! $targetExists) {
                $this->recordFailure("Row count mismatch for {$table}: source={$sourceCount}, target=missing.");

                continue;
            }

            $targetCount = $target->table($table)->count();
            $this->line("Rows {$table}: source={$sourceCount}, target={$targetCount}.");

            if ($sourceCount !== $targetCount) {
                $this->recordFailure("Row count mismatch for {$table}: source={$sourceCount}, target={$targetCount}.");
            }
        }
    }

    private function verifyMoneySums(Connection $source, Connection $target): void
    {
        foreach (self::MONEY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! isset($this->sourceColumnMetadata[$table][$column])) {
                    continue;
                }

                $sourceSum = $this->sumAsString($source, $table, $column);
                $targetSum = $this->sumAsString($target, $table, $column);
                $this->line("Sum {$table}.{$column}: source={$sourceSum}, target={$targetSum}.");

                if ($this->canonicalNumber($sourceSum) !== $this->canonicalNumber($targetSum)) {
                    $this->recordFailure("Money sum mismatch for {$table}.{$column}: source={$sourceSum}, target={$targetSum}.");
                }
            }
        }
    }

    private function sumAsString(Connection $connection, string $table, string $column): string
    {
        $value = $connection->table($table)->sum($column);

        return $value === null ? '0' : (string) $value;
    }

    private function canonicalNumber(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^-?\d+(?:\.\d+)?$/D', $value) !== 1) {
            throw new RuntimeException('A monetary SUM returned a non-numeric value.');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');
        $zero = $whole === '0' && $fraction === '';

        return ($negative && ! $zero ? '-' : '').$whole.($fraction === '' ? '' : '.'.$fraction);
    }

    /** @param list<string> $tables */
    private function verifySequences(Connection $target, array $tables): void
    {
        foreach ($tables as $table) {
            $hasId = $target->selectOne(
                'SELECT 1 AS present FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$table, 'id'],
            ) !== null;
            if (! $hasId) {
                continue;
            }

            $sequence = $target->selectOne(
                'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
                [$table, 'id'],
            );
            if (($sequence->sequence_name ?? null) === null) {
                continue;
            }

            $sequenceInfo = $target->selectOne(
                'SELECT n.nspname AS schema_name, c.relname AS sequence_name '
                .'FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
                .'WHERE c.oid = pg_get_serial_sequence(?, ?)::regclass',
                [$table, 'id'],
            );
            if ($sequenceInfo === null) {
                $this->recordFailure("Sequence metadata is missing for {$table}.id.");

                continue;
            }

            $sequenceTable = $target->getQueryGrammar()->wrapTable(
                $sequenceInfo->schema_name.'.'.$sequenceInfo->sequence_name,
            );
            $state = $target->selectOne('SELECT last_value, is_called FROM '.$sequenceTable);
            $increment = $target->selectOne(
                'SELECT increment_by FROM pg_sequences WHERE schemaname = ? AND sequencename = ?',
                [$sequenceInfo->schema_name, $sequenceInfo->sequence_name],
            );
            $maximum = $target->table($table)->max('id');
            $next = (int) $state->last_value + (strtolower((string) $state->is_called) === 't'
                ? (int) ($increment->increment_by ?? 1)
                : 0);
            $maximumDisplay = $maximum === null ? 'none' : (string) $maximum;

            $this->line("Sequence {$table}.id: next={$next}, max={$maximumDisplay}.");

            if ($maximum !== null && $next <= (int) $maximum) {
                $this->recordFailure("Sequence for {$table}.id is not ahead of max(id)={$maximumDisplay} (next={$next}).");
            }
        }
    }

    private function verifyForeignKeys(Connection $target): void
    {
        // constraint_column_usage has no ordinal_position in PostgreSQL. Follow
        // the referenced unique constraint through referential_constraints and
        // its key_column_usage rows to pair composite columns in key order.
        $rows = $target->select(
            'SELECT key_usage.constraint_name, key_usage.table_name, key_usage.column_name, '
            .'column_usage.table_name AS referenced_table_name, '
            .'column_usage.column_name AS referenced_column_name '
            .'FROM information_schema.key_column_usage AS key_usage '
            .'JOIN information_schema.table_constraints AS table_constraint '
            .'ON table_constraint.constraint_catalog = key_usage.constraint_catalog '
            .'AND table_constraint.constraint_schema = key_usage.constraint_schema '
            .'AND table_constraint.constraint_name = key_usage.constraint_name '
            .'AND table_constraint.table_catalog = key_usage.table_catalog '
            .'AND table_constraint.table_schema = key_usage.table_schema '
            .'AND table_constraint.table_name = key_usage.table_name '
            .'AND table_constraint.constraint_type = \'FOREIGN KEY\' '
            .'JOIN information_schema.referential_constraints AS reference_constraint '
            .'ON reference_constraint.constraint_catalog = key_usage.constraint_catalog '
            .'AND reference_constraint.constraint_schema = key_usage.constraint_schema '
            .'AND reference_constraint.constraint_name = key_usage.constraint_name '
            .'JOIN information_schema.key_column_usage AS referenced_key '
            .'ON referenced_key.constraint_catalog = reference_constraint.unique_constraint_catalog '
            .'AND referenced_key.constraint_schema = reference_constraint.unique_constraint_schema '
            .'AND referenced_key.constraint_name = reference_constraint.unique_constraint_name '
            .'AND referenced_key.ordinal_position = key_usage.position_in_unique_constraint '
            .'JOIN information_schema.constraint_column_usage AS column_usage '
            .'ON column_usage.constraint_catalog = key_usage.constraint_catalog '
            .'AND column_usage.constraint_schema = key_usage.constraint_schema '
            .'AND column_usage.constraint_name = key_usage.constraint_name '
            .'AND column_usage.table_catalog = referenced_key.table_catalog '
            .'AND column_usage.table_schema = referenced_key.table_schema '
            .'AND column_usage.table_name = referenced_key.table_name '
            .'AND column_usage.column_name = referenced_key.column_name '
            .'WHERE key_usage.table_schema = current_schema() '
            .'ORDER BY key_usage.table_name, key_usage.constraint_name, key_usage.ordinal_position',
        );
        $constraints = [];

        foreach ($rows as $row) {
            $key = $row->table_name."\0".$row->constraint_name;
            $constraints[$key] ??= [
                'table' => (string) $row->table_name,
                'name' => (string) $row->constraint_name,
                'columns' => [],
                'parent' => (string) $row->referenced_table_name,
            ];
            $constraints[$key]['columns'][] = [
                'local' => (string) $row->column_name,
                'parent' => (string) $row->referenced_column_name,
            ];
        }

        foreach ($constraints as $constraint) {
            $query = $target->table($constraint['table'].' as child');
            foreach ($constraint['columns'] as $column) {
                $query->whereNotNull('child.'.$column['local']);
            }

            $query->whereNotExists(function (Builder $parentQuery) use ($constraint): void {
                $parentQuery->selectRaw('1')->from($constraint['parent'].' as parent');
                foreach ($constraint['columns'] as $column) {
                    $parentQuery->whereColumn('parent.'.$column['parent'], 'child.'.$column['local']);
                }
            });

            $orphans = $query->count();
            if ($orphans > 0) {
                $mapping = implode(', ', array_map(
                    static fn (array $column): string => $column['local'].' -> '.$column['parent'],
                    $constraint['columns'],
                ));
                $this->recordFailure(
                    "Foreign-key {$constraint['table']}.{$constraint['name']} has {$orphans} orphan rows ({$mapping}).",
                );
            } else {
                $this->line("Foreign key {$constraint['table']}.{$constraint['name']}: no orphans.");
            }
        }
    }

    private function recordFailure(string $message): void
    {
        $this->issues[] = $message;
        $this->error($message);
    }
}
