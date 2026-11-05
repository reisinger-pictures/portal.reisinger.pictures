<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use PDOException;
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

    protected $description = 'Replace PostgreSQL rows with the frozen MariaDB source and verify cutover invariants.';

    /** @var list<string> */
    private array $issues = [];

    /** Number of failures recorded, including detail lines that were suppressed. */
    private int $failureCount = 0;

    /** Number of failure detail lines that exceeded the stored/emitted bound. */
    private int $suppressedFailureCount = 0;

    /** @var array<string, list<array{data_type: string, column_type: string}>> */
    private array $sourceColumnMetadata = [];

    /** @var array<string, list<string>> */
    private array $sharedColumns = [];

    /** @var array<string, list<string>> */
    private array $primaryKeys = [];

    /** @var array<string, string> */
    private array $targetColumnTypes = [];

    private readonly PostgresCopyValueNormalizer $normalizer;

    /**
     * Widely divergent production data can produce one failure per row and
     * cell. Keep the first examples for diagnosis and count the rest instead
     * of storing and printing an unbounded number of lines.
     */
    private const MAX_DETAIL_FAILURES = 25;

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
            // Make temporal and binary text output independent of server
            // defaults so the parity comparison sees one canonical rendering.
            $target->statement("SET datestyle = 'ISO, YMD'");
            $target->statement("SET bytea_output = 'hex'");
            [$sourceTables, $targetTables, $schemaErrors] = $this->inspectSchema($source, $target);

            foreach ($schemaErrors as $schemaError) {
                $this->recordFailure($schemaError);
            }

            if ($schemaErrors !== []) {
                throw new RuntimeException('Source and target schemas do not agree.');
            }

            if (! $verifyOnly) {
                $this->copyRows($source, $target, $sourceTables, max(1, (int) $this->option('chunk')));
                $this->resetSequences($target, $targetTables);
            }

            $this->verify($source, $target, $sourceTables, $targetTables, max(1, (int) $this->option('chunk')));
        } catch (Throwable $exception) {
            $this->recordFailure($this->describeThrowable($exception));
        }

        $this->newLine();
        $this->line('=== portal:db-copy summary ===');
        $this->line('Checks: '.($this->failureCount === 0 ? 'all passed' : $this->failureCount.' failed'));

        if ($this->suppressedFailureCount > 0) {
            $this->line(
                'Details: '.count($this->issues).' of '.$this->failureCount
                .' failures shown; '.$this->suppressedFailureCount.' detail line(s) suppressed.',
            );
        }

        $this->line('Result: '.($this->failureCount === 0 ? 'PASS' : 'FAIL'));
        $this->line('==============================');

        return $this->failureCount === 0 ? self::SUCCESS : self::FAILURE;
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
            // A copy run replaces the target table set instead of merging into
            // rows that target migrations or an earlier run left behind. The
            // transaction makes the reset and every insert atomic: a failed
            // insert leaves the previous target state intact, and a rerun
            // starts from a clean table set again.
            $target->transaction(function () use ($source, $target, $tables, $chunkSize): void {
                $this->resetTargetRows($target, $tables);

                foreach ($tables as $table) {
                    $columns = $this->sharedColumns[$table] ?? [];
                    $keys = $this->primaryKeys[$table] ?? [];

                    if ($columns === [] || $keys === []) {
                        throw new RuntimeException("Cannot copy '{$table}' without shared columns and a primary key.");
                    }

                    $sourceCount = $source->table($table)->count();
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

                        try {
                            $target->table($table)->insert($batch);
                        } catch (Throwable $exception) {
                            throw new RuntimeException(
                                "Failed to insert source rows into '{$table}': "
                                .$this->describeThrowable($exception).'.',
                                previous: $exception,
                            );
                        }

                        $last = $rows->last();
                        $cursor = [];

                        foreach ($keys as $key) {
                            $cursor[] = $last->{$key};
                        }

                        $copied += count($batch);
                    } while (count($rows) === $chunkSize);

                    $targetCount = $target->table($table)->count();

                    if ($copied !== $sourceCount || $targetCount !== $sourceCount) {
                        throw new RuntimeException(
                            "Copying '{$table}' did not reproduce the source rows exactly: "
                            ."source={$sourceCount}, copied={$copied}, target={$targetCount}.",
                        );
                    }

                    $this->line("Copied {$copied} source rows from {$table}.");
                }
            });
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
                'Failed to restore PostgreSQL session_replication_role: '
                .$this->describeThrowable($restoreFailure).'.',
                previous: $copyFailure ?? $restoreFailure,
            );
        }

        if ($copyFailure !== null) {
            throw $copyFailure;
        }
    }

    /**
     * Remove every row from the target tables the copy owns so the run
     * reconstructs them from the frozen source instead of merging with seed or
     * stale rows. Foreign-key triggers are disabled by the caller's replica
     * session role, but the table set is still reset in one statement so the
     * target's own foreign-key metadata stays consistent.
     *
     * @param  list<string>  $tables
     */
    private function resetTargetRows(Connection $target, array $tables): void
    {
        $this->assertNoExternalForeignKeyReferences($target, $tables);

        if ($tables === []) {
            return;
        }

        $grammar = $target->getQueryGrammar();
        $wrappedTables = array_map(
            static fn (string $table): string => $grammar->wrapTable($table),
            $tables,
        );

        try {
            $target->statement('TRUNCATE TABLE '.implode(', ', $wrappedTables));
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Failed to reset target rows for the copied table set: '
                .$this->describeThrowable($exception).'.',
                previous: $exception,
            );
        }

        $this->line('Reset target rows in '.count($tables).' copied table(s).');
    }

    /**
     * A target-only table that references a copied table would keep rows that
     * point at parents this copier is about to replace. Refuse the run before
     * touching any data rather than leaving an untruncated table behind.
     *
     * @param  list<string>  $tables
     */
    private function assertNoExternalForeignKeyReferences(Connection $target, array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $copied = array_fill_keys($tables, true);
        $rows = $target->select(
            'SELECT child_namespace.nspname AS child_schema, child.relname AS child_table, '
            .'parent_namespace.nspname AS parent_schema, parent.relname AS parent_table '
            .'FROM pg_constraint AS constraint_definition '
            .'JOIN pg_class AS child ON child.oid = constraint_definition.conrelid '
            .'JOIN pg_namespace AS child_namespace ON child_namespace.oid = child.relnamespace '
            .'JOIN pg_class AS parent ON parent.oid = constraint_definition.confrelid '
            .'JOIN pg_namespace AS parent_namespace ON parent_namespace.oid = parent.relnamespace '
            .'WHERE constraint_definition.contype = \'f\' '
            .'AND parent_namespace.nspname = current_schema()',
        );

        $external = [];
        foreach ($rows as $row) {
            $parent = (string) $row->parent_table;
            $child = (string) $row->child_table;

            if (isset($copied[$parent]) && ! isset($copied[$child])) {
                $external[$row->child_schema.'.'.$child.' -> '.$row->parent_schema.'.'.$parent] = true;
            }
        }

        if ($external !== []) {
            throw new RuntimeException(
                'Cannot reset target rows because tables outside the source set reference copied tables: '
                .implode(', ', array_keys($external)).'.',
            );
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
        $key = $table.'.'.$column;

        if (! array_key_exists($key, $this->targetColumnTypes)) {
            $row = $target->selectOne(
                'SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$table, $column],
            );
            $this->targetColumnTypes[$key] = strtolower((string) ($row->data_type ?? ''));
        }

        return $this->targetColumnTypes[$key];
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
    private function verify(Connection $source, Connection $target, array $sourceTables, array $targetTables, int $chunkSize): void
    {
        $this->verifyRowCounts($source, $target, $sourceTables);
        $this->verifyTableValues($source, $target, $sourceTables, $chunkSize);
        $this->verifySettings($source, $target);
        $this->verifyMoneySums($source, $target);
        $this->verifySequences($target, $targetTables);
        $this->verifyForeignKeys($target);
    }

    /**
     * Compare every shared source column against the target row with the same
     * primary key. The source is streamed in stable-key batches; each batch's
     * target rows are fetched by that bounded key set, so neither side is held
     * in memory and a string primary key never depends on the two engines
     * agreeing on collation order. Settings have their own exact comparison,
     * but this pass also covers their timestamps and any future column.
     *
     * @param  list<string>  $tables
     */
    private function verifyTableValues(Connection $source, Connection $target, array $tables, int $chunkSize): void
    {
        foreach ($tables as $table) {
            $columns = $this->sharedColumns[$table] ?? [];
            $keys = $this->primaryKeys[$table] ?? [];

            if ($columns === [] || $keys === []) {
                // inspectSchema already reports a missing column set or the
                // primary key that makes a stable row identity impossible.
                continue;
            }

            if (! $this->targetTableExists($target, $table)) {
                // verifyRowCounts already names the missing table.
                continue;
            }

            $targetTypes = [];
            foreach ($columns as $column) {
                $targetTypes[$column] = $this->targetColumnType($target, $table, $column);
            }

            $cursor = null;
            $verified = 0;

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

                $sourceKeyValues = [];
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($keys as $key) {
                        $values[] = $row->{$key};
                    }
                    $sourceKeyValues[] = $values;
                }

                $targetByKey = [];
                foreach ($this->targetRowsForKeys($target, $table, $columns, $keys, $sourceKeyValues) as $targetRow) {
                    $identity = [];
                    foreach ($keys as $key) {
                        $identity[] = $this->canonicalTargetValue($targetRow->{$key}, $targetTypes[$key]);
                    }
                    $targetByKey[$this->keyIdentity($identity)] = $targetRow;
                }

                foreach ($rows as $row) {
                    $identity = [];

                    foreach ($keys as $key) {
                        $identity[] = $this->canonicalSourceValue(
                            $row->{$key},
                            $this->sourceColumnMetadata[$table][$key],
                            $targetTypes[$key],
                        );
                    }

                    $rowKey = $this->describeRowKey($keys);
                    $targetRow = $targetByKey[$this->keyIdentity($identity)] ?? null;

                    if ($targetRow === null) {
                        $this->recordFailure(
                            "Source row missing on target for table '{$table}' at row key {$rowKey}.",
                        );

                        continue;
                    }

                    foreach ($columns as $column) {
                        if (in_array($column, $keys, true)) {
                            continue;
                        }

                        try {
                            $sourceValue = $this->canonicalSourceValue(
                                $row->{$column},
                                $this->sourceColumnMetadata[$table][$column],
                                $targetTypes[$column],
                            );
                            $targetValue = $this->canonicalTargetValue(
                                $targetRow->{$column},
                                $targetTypes[$column],
                            );
                        } catch (Throwable $exception) {
                            $this->recordFailure(
                                "Cannot compare {$table}.{$column} at row key {$rowKey}: "
                                .$this->describeThrowable($exception).'.',
                            );

                            continue;
                        }

                        if ($sourceValue !== $targetValue) {
                            $this->recordFailure(
                                "Value mismatch for {$table}.{$column} at row key {$rowKey}: "
                                .'the normalized source and target values differ.',
                            );
                        }
                    }

                    $verified++;
                }

                $last = $rows->last();
                $cursor = [];
                foreach ($keys as $key) {
                    $cursor[] = $last->{$key};
                }
            } while (count($rows) === $chunkSize);

            $this->line(
                "Verified values {$table}: {$verified} rows, ".count($columns).' shared columns.',
            );
        }
    }

    /**
     * Fetch only the target rows whose primary key is in the current bounded
     * source batch. The OR groups keep the parameter count proportional to the
     * configured chunk size instead of building one query for the whole table.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $keys
     * @param  list<list<mixed>>  $keyValues
     * @return Collection<int, object>
     */
    private function targetRowsForKeys(
        Connection $target,
        string $table,
        array $columns,
        array $keys,
        array $keyValues,
    ): Collection {
        $query = $target->table($table)->select($columns);
        $query->where(function (Builder $outer) use ($keys, $keyValues): void {
            foreach ($keyValues as $values) {
                $outer->orWhere(function (Builder $branch) use ($keys, $values): void {
                    foreach ($keys as $index => $key) {
                        $branch->where($key, '=', $values[$index]);
                    }
                });
            }
        });

        return $query->get();
    }

    private function targetTableExists(Connection $target, string $table): bool
    {
        return $target->selectOne(
            'SELECT 1 AS present FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
            [$table],
        ) !== null;
    }

    /** @param list<string> $values */
    private function keyIdentity(array $values): string
    {
        return serialize($values);
    }

    /** @param list<string> $keys */
    private function describeRowKey(array $keys): string
    {
        $parts = [];

        foreach ($keys as $key) {
            // Natural primary keys can contain emails, tokens, or other
            // customer data. The table and key-column names identify the row
            // shape without copying any source value into production logs.
            $parts[] = $key.'=<redacted>';
        }

        return '('.implode(', ', $parts).')';
    }

    /**
     * Normalize the source value exactly as the copy path does, then reduce it
     * to a representation the target can be compared against.
     *
     * @param  array{data_type: string, column_type: string}  $sourceColumn
     */
    private function canonicalSourceValue(mixed $value, array $sourceColumn, string $targetDataType): string
    {
        return $this->canonicalValue(
            $this->normalizer->normalize($value, $sourceColumn, $targetDataType),
            $targetDataType,
        );
    }

    private function canonicalTargetValue(mixed $value, string $targetDataType): string
    {
        return $this->canonicalValue($value, $targetDataType);
    }

    private function canonicalValue(mixed $value, string $targetDataType): string
    {
        if ($value === null) {
            return 'null';
        }

        $targetDataType = strtolower(trim($targetDataType));

        if ($targetDataType === 'boolean') {
            return 'boolean:'.$this->canonicalBoolean($value);
        }

        if ($targetDataType === 'bytea') {
            return 'bytea:'.$this->canonicalBytes($value);
        }

        if (in_array($targetDataType, ['json', 'jsonb'], true)) {
            return 'json:'.$this->canonicalJson($value);
        }

        if (in_array($targetDataType, [
            'smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision',
        ], true)) {
            return 'number:'.$this->canonicalNumeric($value, $targetDataType);
        }

        if (in_array($targetDataType, [
            'date', 'time without time zone', 'time with time zone',
            'timestamp without time zone', 'timestamp with time zone',
        ], true)) {
            return 'time:'.$this->canonicalTemporal($value, $targetDataType);
        }

        if ($targetDataType === 'character') {
            // PostgreSQL blank-pads char(n); trailing spaces are not part of
            // the value and MariaDB strips them on read.
            return 'text:'.rtrim($this->canonicalText($value), ' ');
        }

        return 'text:'.$this->canonicalText($value);
    }

    private function canonicalBoolean(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = strtolower(trim((string) $value));

        if (in_array($value, ['1', 't', 'true', 'yes', 'on'], true)) {
            return 'true';
        }

        if (in_array($value, ['0', 'f', 'false', 'no', 'off'], true)) {
            return 'false';
        }

        throw new RuntimeException('A boolean column returned an unrecognized representation.');
    }

    private function canonicalBytes(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);

            if (! is_string($value)) {
                throw new RuntimeException('A bytea value could not be read.');
            }
        }

        if (! is_string($value)) {
            throw new RuntimeException('A bytea value was not a byte string.');
        }

        // PDO may hand back a stream, the PostgreSQL hex text format, or raw
        // bytes depending on the driver path. All three reduce to the same hex
        // payload; the copy path writes the hex format for source binaries.
        if (str_starts_with($value, '\\x')) {
            return strtolower(substr($value, 2));
        }

        return bin2hex($value);
    }

    /**
     * jsonb reformats whitespace, object key order, and numeric spellings.
     * Decode the JSON text into a canonical representation without passing
     * numbers through PHP's int or float type: json_decode() collapses
     * distinct arbitrary-precision numbers such as 1.0000000000000000001 and
     * 1.0000000000000000002 to the same float, which would hide a real value
     * mismatch. Number tokens therefore keep their exact lexeme and are
     * canonicalized as decimal strings.
     */
    private function canonicalJson(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);

            if (! is_string($value)) {
                throw new RuntimeException('A JSON value could not be read.');
            }
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }

        try {
            $offset = 0;
            $canonical = $this->parseCanonicalJson($value, $offset, 0);
            $this->skipJsonWhitespace($value, $offset);

            if ($offset !== strlen($value)) {
                throw new JsonException('A JSON value has trailing content.');
            }

            return $canonical;
        } catch (JsonException) {
            // json/jsonb validates input, so this is only reachable for a
            // malformed fixture. Comparing the raw bytes is still useful.
            return 'raw:'.$value;
        }
    }

    /**
     * Parse one JSON value into its canonical comparison form. Object keys are
     * sorted, array order is kept, and number tokens are canonicalized from
     * their lexeme instead of being narrowed to a PHP number.
     */
    private function parseCanonicalJson(string $json, int &$offset, int $depth): string
    {
        if ($depth > 512) {
            throw new JsonException('A JSON value is nested too deeply.');
        }

        $this->skipJsonWhitespace($json, $offset);
        $length = strlen($json);

        if ($offset >= $length) {
            throw new JsonException('A JSON value ended unexpectedly.');
        }

        $character = $json[$offset];

        if ($character === '{') {
            $offset++;
            $members = [];

            $this->skipJsonWhitespace($json, $offset);
            if ($offset < $length && $json[$offset] === '}') {
                $offset++;

                return '{}';
            }

            while (true) {
                $this->skipJsonWhitespace($json, $offset);
                $key = $this->parseCanonicalJsonString($json, $offset);
                $this->skipJsonWhitespace($json, $offset);

                if ($offset >= $length || $json[$offset] !== ':') {
                    throw new JsonException('A JSON object member is missing its colon.');
                }
                $offset++;

                // jsonb keeps the last value of a duplicate key. The prefixed
                // array key also stops PHP from casting numeric-looking keys.
                $members['key:'.$key] = [
                    'key' => $key,
                    'value' => $this->parseCanonicalJson($json, $offset, $depth + 1),
                ];

                $this->skipJsonWhitespace($json, $offset);
                if ($offset >= $length) {
                    throw new JsonException('A JSON object ended unexpectedly.');
                }

                if ($json[$offset] === ',') {
                    $offset++;

                    continue;
                }

                if ($json[$offset] === '}') {
                    $offset++;
                    break;
                }

                throw new JsonException('A JSON object member is not separated correctly.');
            }

            uasort(
                $members,
                static fn (array $left, array $right): int => strcmp($left['key'], $right['key']),
            );

            $parts = [];
            foreach ($members as $member) {
                $parts[] = $this->encodeCanonicalJsonString($member['key']).':'.$member['value'];
            }

            return '{'.implode(',', $parts).'}';
        }

        if ($character === '[') {
            $offset++;
            $items = [];

            $this->skipJsonWhitespace($json, $offset);
            if ($offset < $length && $json[$offset] === ']') {
                $offset++;

                return '[]';
            }

            while (true) {
                $items[] = $this->parseCanonicalJson($json, $offset, $depth + 1);
                $this->skipJsonWhitespace($json, $offset);

                if ($offset >= $length) {
                    throw new JsonException('A JSON array ended unexpectedly.');
                }

                if ($json[$offset] === ',') {
                    $offset++;

                    continue;
                }

                if ($json[$offset] === ']') {
                    $offset++;
                    break;
                }

                throw new JsonException('A JSON array item is not separated correctly.');
            }

            return '['.implode(',', $items).']';
        }

        if ($character === '"') {
            return 'string:'.$this->encodeCanonicalJsonString(
                $this->parseCanonicalJsonString($json, $offset),
            );
        }

        if (substr($json, $offset, 4) === 'true') {
            $offset += 4;

            return 'boolean:true';
        }

        if (substr($json, $offset, 5) === 'false') {
            $offset += 5;

            return 'boolean:false';
        }

        if (substr($json, $offset, 4) === 'null') {
            $offset += 4;

            return 'null';
        }

        if (preg_match(
            '/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/',
            $json,
            $matches,
            0,
            $offset,
        ) !== 1) {
            throw new JsonException('A JSON value is not recognized.');
        }

        $offset += strlen($matches[0]);

        return 'number:'.$this->canonicalJsonNumber($matches[0]);
    }

    /**
     * Decode one JSON string token, so equivalent escape spellings compare
     * equal while the encoded form keeps the canonical output unambiguous.
     */
    private function parseCanonicalJsonString(string $json, int &$offset): string
    {
        $length = strlen($json);

        if ($offset >= $length || $json[$offset] !== '"') {
            throw new JsonException('A JSON string was expected.');
        }

        $start = $offset;
        $offset++;

        while ($offset < $length) {
            $character = $json[$offset];

            if ($character === '\\') {
                // Skip the escape and the escaped byte; the hex digits of a
                // \uXXXX escape are ordinary characters for this scan.
                $offset += 2;

                continue;
            }

            if ($character === '"') {
                $offset++;
                $decoded = json_decode(
                    substr($json, $start, $offset - $start),
                    false,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                if (! is_string($decoded)) {
                    throw new JsonException('A JSON string did not decode to a string.');
                }

                return $decoded;
            }

            $offset++;
        }

        throw new JsonException('A JSON string ended unexpectedly.');
    }

    private function encodeCanonicalJsonString(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new JsonException('A JSON string could not be encoded.');
        }

        return $encoded;
    }

    /**
     * Reduce a JSON number lexeme to a canonical decimal form. The value never
     * becomes a PHP int or float: 1, 1.0, and 1e0 all reduce to 1e0, while
     * 1.0000000000000000001 stays distinct from 1.0000000000000000002.
     */
    private function canonicalJsonNumber(string $number): string
    {
        $sign = '';

        if (str_starts_with($number, '-')) {
            $sign = '-';
            $number = substr($number, 1);
        }

        $exponent = 0;

        if (preg_match('/[eE]([+-]?\d+)$/', $number, $parts) === 1) {
            if (strlen(ltrim(ltrim($parts[1], '+-'), '0')) > 6) {
                // PostgreSQL numeric caps exponents well below this; keeping
                // such a value out of the canonical path avoids a saturated
                // integer cast that could equate distinct numbers.
                throw new JsonException('A JSON number has an unsupported exponent.');
            }

            $exponent = (int) $parts[1];
            $number = substr($number, 0, -strlen($parts[0]));
        }

        $digits = $number;
        if (str_contains($digits, '.')) {
            [$whole, $fraction] = explode('.', $digits, 2);
            $exponent -= strlen($fraction);
            $digits = $whole.$fraction;
        }

        $digits = ltrim($digits, '0');

        if ($digits === '') {
            // jsonb stores numbers as numeric, where -0 and 0 are equal.
            return '0';
        }

        $significant = rtrim($digits, '0');
        $exponent += strlen($digits) - strlen($significant);
        $digits = $significant;

        $point = $exponent + strlen($digits) - 1;
        $mantissa = strlen($digits) === 1
            ? $digits
            : $digits[0].'.'.substr($digits, 1);

        return $sign.$mantissa.'e'.$point;
    }

    private function skipJsonWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);

        while ($offset < $length && str_contains(" \t\n\r", $json[$offset])) {
            $offset++;
        }
    }

    private function canonicalNumeric(mixed $value, string $targetDataType): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return $this->canonicalFloat($value);
        }

        $value = trim((string) $value);

        if (in_array($targetDataType, ['real', 'double precision'], true)) {
            return $this->canonicalFloat((float) $value);
        }

        return $this->canonicalNumber($value);
    }

    private function canonicalFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }

        return strtolower(sprintf('%.17G', $value));
    }

    private function canonicalTemporal(mixed $value, string $targetDataType): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (str_ends_with($targetDataType, 'with time zone')) {
            $hasZone = preg_match('/(?:[zZ]|[+-]\d{2}(?::?\d{2})?)$/', $value) === 1;

            try {
                $instant = new DateTimeImmutable($hasZone ? $value : $value.'+00:00');
                $value = $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            } catch (Throwable) {
                // Fall through to the textual comparison below.
            }
        } else {
            $value = str_replace('T', ' ', $value);
        }

        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2}(?:[ ]\d{2}:\d{2}:\d{2})?|\d{2}:\d{2}:\d{2})(?:\.(\d+))?$/',
            $value,
            $parts,
        ) !== 1) {
            return $value;
        }

        $fraction = rtrim($parts[2] ?? '', '0');

        return $fraction === '' ? $parts[1] : $parts[1].'.'.$fraction;
    }

    private function canonicalText(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);

            if (! is_string($value)) {
                throw new RuntimeException('A text value could not be read.');
            }
        }

        return (string) $value;
    }

    /**
     * Settings are the deployment's operational contract (prices, licence
     * terms, bank details). A count and a money sum can agree while a target
     * row carries the wrong value, so compare every (key, brand) row exactly,
     * including null values and null brands. Sorting happens in PHP so the
     * comparison does not depend on the engines' collations.
     */
    private function verifySettings(Connection $source, Connection $target): void
    {
        if (! isset($this->sourceColumnMetadata['settings'])) {
            return;
        }

        $targetExists = $target->selectOne(
            'SELECT 1 AS present FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
            ['settings'],
        ) !== null;

        if (! $targetExists) {
            // verifyRowCounts already names the missing table.
            return;
        }

        $sourceRows = $this->settingRows($source);
        $targetRows = $this->settingRows($target);
        $sourceCount = count($sourceRows);
        $targetCount = count($targetRows);

        $this->line("Settings rows: source={$sourceCount}, target={$targetCount}.");

        if ($sourceRows === $targetRows) {
            $this->line('Settings identities and values: exact match.');

            return;
        }

        if ($sourceCount !== $targetCount) {
            $this->recordFailure("Settings row count mismatch: source={$sourceCount}, target={$targetCount}.");
        }

        $sourceByIdentity = $this->settingsByIdentity($sourceRows);
        $targetByIdentity = $this->settingsByIdentity($targetRows);
        $identities = array_keys($sourceByIdentity + $targetByIdentity);
        sort($identities, SORT_STRING);

        $differences = 0;

        foreach ($identities as $identity) {
            $inSource = array_key_exists($identity, $sourceByIdentity);
            $inTarget = array_key_exists($identity, $targetByIdentity);

            if (! $inTarget) {
                $differences++;
                $this->recordFailure(
                    'Settings row exists only in the source: '.$this->describeSetting($sourceByIdentity[$identity]).'.',
                );

                continue;
            }

            if (! $inSource) {
                $differences++;
                $this->recordFailure(
                    'Settings row exists only in the target: '.$this->describeSetting($targetByIdentity[$identity]).'.',
                );

                continue;
            }

            if ($sourceByIdentity[$identity]['value'] !== $targetByIdentity[$identity]['value']) {
                $differences++;
                $this->recordFailure(
                    'Settings value mismatch for '.$this->describeSetting($sourceByIdentity[$identity])
                    .': the source and target values differ.',
                );
            }
        }

        if ($differences === 0) {
            // Only reachable with duplicate (key, brand) identities, which the
            // production primary key forbids. The sorted row comparison above
            // still rejects them instead of reporting a false match.
            $this->recordFailure('Settings row sets differ although every (key, brand) identity has a matching value.');
        }
    }

    /**
     * @return list<array{key: ?string, brand: ?string, value: ?string}>
     */
    private function settingRows(Connection $connection): array
    {
        $settings = [];

        foreach ($connection->table('settings')->select(['key', 'brand', 'value'])->get() as $row) {
            $settings[] = [
                'key' => $row->key === null ? null : (string) $row->key,
                'brand' => $row->brand === null ? null : (string) $row->brand,
                'value' => $row->value === null ? null : (string) $row->value,
            ];
        }

        usort($settings, fn (array $left, array $right): int => $this->compareSettingRows($left, $right));

        return $settings;
    }

    /**
     * @param  array{key: ?string, brand: ?string, value: ?string}  $left
     * @param  array{key: ?string, brand: ?string, value: ?string}  $right
     */
    private function compareSettingRows(array $left, array $right): int
    {
        foreach (['key', 'brand', 'value'] as $field) {
            if ($left[$field] === $right[$field]) {
                continue;
            }

            if ($left[$field] === null) {
                return -1;
            }

            if ($right[$field] === null) {
                return 1;
            }

            $comparison = strcmp($left[$field], $right[$field]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /**
     * @param  list<array{key: ?string, brand: ?string, value: ?string}>  $rows
     * @return array<string, array{key: ?string, brand: ?string, value: ?string}>
     */
    private function settingsByIdentity(array $rows): array
    {
        $settings = [];

        foreach ($rows as $row) {
            $settings[$this->settingIdentity($row)] = $row;
        }

        return $settings;
    }

    /** @param array{key: ?string, brand: ?string, value: ?string} $row */
    private function settingIdentity(array $row): string
    {
        return ($row['key'] === null ? "\0" : $row['key'])."\0"
            .($row['brand'] === null ? "\0" : $row['brand']);
    }

    /** @param array{key: ?string, brand: ?string, value: ?string} $row */
    private function describeSetting(array $row): string
    {
        $key = $row['key'] === null ? 'NULL' : "'".$row['key']."'";
        $brand = $row['brand'] === null ? 'NULL' : "'".$row['brand']."'";

        return "key={$key}, brand={$brand}";
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

    /**
     * Record one failure and emit its detail only while the bounded detail
     * budget lasts. The total count is always kept, so suppressing detail can
     * never turn a failing run into a passing one.
     */
    private function recordFailure(string $message): void
    {
        $this->failureCount++;

        if (count($this->issues) >= self::MAX_DETAIL_FAILURES) {
            $this->suppressedFailureCount++;

            return;
        }

        $this->issues[] = $message;
        $this->error($message);
    }

    /**
     * Reduce an exception to a diagnostic that never contains SQL bindings or
     * row values. Laravel's QueryException interpolates bindings into its
     * message and the PDO exception underneath carries DETAIL lines with row
     * values, so for a database failure only the SQLSTATE code is safe to
     * emit. Callers add the table and operation context themselves.
     */
    private function describeThrowable(Throwable $exception): string
    {
        if ($this->isDatabaseFailure($exception)) {
            return $this->describeDatabaseFailure($exception);
        }

        $message = trim($exception->getMessage());

        if ($message === '') {
            $previous = $exception->getPrevious();

            return $previous === null ? $exception::class : $this->describeThrowable($previous);
        }

        $databaseFailure = $this->databaseFailureInChain($exception->getPrevious());

        if ($databaseFailure === null) {
            return $message;
        }

        // A wrapper that copied a database exception's message would carry
        // the interpolated bindings with it. Replace that segment with the
        // safe SQLSTATE description and keep the wrapper's own context.
        $unsafe = trim($databaseFailure->getMessage());

        if ($unsafe === '' || ! str_contains($message, $unsafe)) {
            return $message;
        }

        return trim(str_replace(
            $unsafe,
            $this->describeDatabaseFailure($databaseFailure),
            $message,
        ));
    }

    private function databaseFailureInChain(?Throwable $exception): ?Throwable
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($this->isDatabaseFailure($current)) {
                return $current;
            }
        }

        return null;
    }

    private function isDatabaseFailure(Throwable $exception): bool
    {
        return $exception instanceof QueryException || $exception instanceof PDOException;
    }

    private function describeDatabaseFailure(Throwable $exception): string
    {
        $state = $this->sqlState($exception);

        return $state === null
            ? 'a database error without a SQLSTATE code'
            : "a database error (SQLSTATE {$state})";
    }

    /**
     * Return the five-character SQLSTATE code when the driver supplies one.
     * Anything else is dropped: only a validated code may reach the output.
     */
    private function sqlState(Throwable $exception): ?string
    {
        $candidates = [];

        if ($exception instanceof PDOException) {
            $errorInfo = $exception->errorInfo;
            $candidates[] = is_array($errorInfo) ? ($errorInfo[0] ?? null) : null;
            $candidates[] = $exception->getCode();
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[0-9A-Z]{5}$/D', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }
}
