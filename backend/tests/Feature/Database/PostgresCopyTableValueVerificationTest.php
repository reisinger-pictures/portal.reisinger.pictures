<?php

namespace Tests\Feature\Database;

use App\Console\Commands\PostgresCopy;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PDOException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

class PostgresCopyTableValueVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const ROLE_ADMIN = '11111111-1111-4111-8111-111111111111';

    private const ROLE_PHOTOGRAPHER = '22222222-2222-4222-8222-222222222222';

    private const USER_ONE = '33333333-3333-4333-8333-333333333333';

    private const USER_TWO = '44444444-4444-4444-8444-444444444444';

    private const SECRET_SENTINEL = 'copy-insert-secret-sentinel@example.test';

    private ?Connection $source = null;

    private string $sourceSchema = '';

    private string $targetSchema = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $suffix = str_replace('-', '', (string) Str::uuid());
        $this->sourceSchema = 'pc_values_source_'.$suffix;
        $this->targetSchema = 'pc_values_target_'.$suffix;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->source !== null && $this->sourceSchema !== '') {
                $this->source->statement(
                    'DROP SCHEMA IF EXISTS '.$this->quote($this->sourceSchema).' CASCADE',
                );
            }
        } catch (Throwable) {
            // The source schema is a best-effort test fixture; the assertion
            // that failed before cleanup is the result that matters.
        } finally {
            try {
                DB::statement('SET search_path TO public');
                if ($this->targetSchema !== '') {
                    DB::statement(
                        'DROP SCHEMA IF EXISTS '.$this->quote($this->targetSchema).' CASCADE',
                    );
                }
            } catch (Throwable) {
                // The surrounding RefreshDatabase transaction rolls the target
                // schema back even when an explicit drop is not possible.
            }

            try {
                DB::purge('pgsql_copy_source');
            } catch (Throwable) {
                // The source connection is already unusable; the source schema
                // was dropped above when it still could be.
            }

            parent::tearDown();
        }
    }

    public function test_copy_verifies_next_sequence_value_after_explicit_id_insert(): void
    {
        $this->createFixtureSchemas();
        $this->source?->statement(
            'INSERT INTO sequence_rows (id, label) VALUES (?, ?)',
            [7, 'copied row'],
        );

        [$status, $output] = $this->runCommand([]);

        $this->assertSame(Command::SUCCESS, $status, $output);
        $this->assertStringContainsString('Sequence sequence_rows.id: next=8, max=7.', $output);
        $this->assertSame(8, (int) DB::table('sequence_rows')->insertGetId(['label' => 'next row']));
    }

    public function test_verify_only_rejects_a_same_count_wrong_value_in_a_copied_non_settings_column(): void
    {
        $this->createFixtureSchemas();
        $this->insertFixtureRows();
        DB::statement(
            'UPDATE roles SET name = ? WHERE id = ?',
            ['photographer_stale', self::ROLE_PHOTOGRAPHER],
        );
        DB::statement(
            'UPDATE password_reset_tokens SET token = ? WHERE email = ?',
            ['stale-reset-token', 'person@example.test'],
        );

        [$status, $output] = $this->runVerifyOnlyCommand();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString(
            'Value mismatch for roles.name at row key (id=<redacted>)',
            $output,
        );
        $this->assertStringContainsString(
            'Value mismatch for password_reset_tokens.token at row key (email=<redacted>)',
            $output,
        );
        $this->assertStringNotContainsString('photographer_stale', $output);
        $this->assertStringNotContainsString('person@example.test', $output);
        $this->assertStringNotContainsString('stale-reset-token', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_verify_only_accepts_normalized_tinyint_zero_date_and_bytea_values_with_composite_keys(): void
    {
        $this->createFixtureSchemas();
        $this->insertFixtureRows();

        [$status, $output] = $this->runVerifyOnlyCommand(1);

        $this->assertSame(Command::SUCCESS, $status, $output);
        $this->assertStringContainsString('Verified values feature_flags', $output);
        $this->assertStringContainsString('Verified values events', $output);
        $this->assertStringContainsString('Verified values attachments', $output);
        $this->assertStringContainsString('Verified values documents', $output);
        $this->assertStringContainsString('Verified values user_roles', $output);
        $this->assertStringContainsString('Result: PASS', $output);
    }

    public function test_verify_only_rejects_distinct_high_precision_json_numbers(): void
    {
        $this->createFixtureSchemas();
        $this->insertFixtureRows();
        $this->source?->statement(
            'UPDATE documents SET payload = ? WHERE id = 1',
            ['{"amount":1.0000000000000000001}'],
        );
        DB::statement(
            'UPDATE documents SET payload = ?::jsonb WHERE id = 1',
            ['{"amount":1.0000000000000000002}'],
        );

        [$status, $output] = $this->runVerifyOnlyCommand();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString(
            'Value mismatch for documents.payload at row key (id=<redacted>)',
            $output,
        );
        $this->assertStringNotContainsString('1.0000000000000000001', $output);
        $this->assertStringNotContainsString('1.0000000000000000002', $output);
    }

    public function test_copy_insert_failure_never_emits_source_values_or_bindings(): void
    {
        $this->createFixtureSchemas();
        DB::statement(
            'ALTER TABLE '.$this->quote($this->targetSchema).'.roles '
            .'ADD CONSTRAINT roles_name_not_secret CHECK (name <> '.$this->literal(self::SECRET_SENTINEL).')',
        );
        $this->source?->statement(
            'INSERT INTO roles (id, name) VALUES (?, ?)',
            [self::ROLE_ADMIN, self::SECRET_SENTINEL],
        );

        [$status, $output] = $this->runCommand([]);

        $this->assertSame(Command::FAILURE, $status, $output);
        $this->assertStringContainsString('Failed to insert source rows into', $output);
        $this->assertStringContainsString('roles', $output);
        // The SQLSTATE identifies the failure without copying the driver's
        // message, which would carry interpolated bindings and DETAIL values.
        $this->assertStringContainsString('SQLSTATE 23514', $output);
        $this->assertStringNotContainsString(self::SECRET_SENTINEL, $output);
        $this->assertStringNotContainsString(self::ROLE_ADMIN, $output);
        $this->assertStringContainsString('Result: FAIL', $output);
        $this->assertStringNotContainsString('Result: PASS', $output);
    }

    public function test_verify_only_bounds_failure_details_but_keeps_the_total_and_fails(): void
    {
        $this->createFixtureSchemas();

        for ($index = 1; $index <= 30; $index++) {
            $id = sprintf('55555555-5555-4555-8555-%012d', $index);
            $this->source?->statement(
                'INSERT INTO roles (id, name) VALUES (?, ?)',
                [$id, 'source-'.$index],
            );
            DB::statement(
                'INSERT INTO roles (id, name) VALUES (?, ?)',
                [$id, 'target-'.$index],
            );
        }

        [$status, $output] = $this->runVerifyOnlyCommand();

        $this->assertSame(Command::FAILURE, $status, $output);
        $this->assertStringContainsString('Checks: 30 failed', $output);
        $this->assertStringContainsString(
            'Details: 25 of 30 failures shown; 5 detail line(s) suppressed.',
            $output,
        );
        $this->assertSame(25, substr_count($output, 'Value mismatch for roles.name'));
        $this->assertStringContainsString('Result: FAIL', $output);
        $this->assertStringNotContainsString('Result: PASS', $output);
    }

    public function test_top_level_database_failure_never_emits_exception_message_or_bindings(): void
    {
        $this->createFixtureSchemas();

        $driverFailure = new PDOException('SQLSTATE[42P01]: Undefined table: '.self::SECRET_SENTINEL);
        $driverFailure->errorInfo = ['42P01', 7, 'ERROR: relation "roles" does not exist '.self::SECRET_SENTINEL];
        $failure = new QueryException(
            'mariadb',
            'select * from password_reset_tokens where email = ?',
            [self::SECRET_SENTINEL],
            $driverFailure,
        );

        [$status, $output] = $this->runCommand([], $failure);

        $this->assertSame(Command::FAILURE, $status, $output);
        $this->assertStringContainsString('SQLSTATE 42P01', $output);
        $this->assertStringNotContainsString(self::SECRET_SENTINEL, $output);
        $this->assertStringNotContainsString('select * from password_reset_tokens', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
        $this->assertStringNotContainsString('Result: PASS', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runVerifyOnlyCommand(int $chunk = 500): array
    {
        return $this->runCommand(['--verify-only' => true, '--chunk' => $chunk]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $options, ?Throwable $sourceFailure = null): array
    {
        $target = DB::connection();
        $sourceForRows = $this->source;
        $this->assertNotNull($sourceForRows);

        $sourceMetadata = $this->sourceMetadata();

        $source = Mockery::mock(Connection::class);
        $source->shouldReceive('getDatabaseName')->andReturn($this->sourceSchema);
        $source->shouldReceive('statement')->andReturnTrue();
        $source->shouldReceive('table')->andReturnUsing(
            fn (string $table) => $sourceForRows->table($table),
        );
        $source->shouldReceive('select')->andReturnUsing(
            function (string $query, array $bindings = []) use ($sourceMetadata, $sourceFailure): array {
                if ($sourceFailure !== null) {
                    throw $sourceFailure;
                }

                if (str_contains($query, 'information_schema.TABLES')) {
                    return array_map(
                        static fn (string $table): object => (object) ['table_name' => $table],
                        array_keys($sourceMetadata),
                    );
                }

                if (str_contains($query, 'information_schema.COLUMNS')) {
                    $table = (string) ($bindings[1] ?? '');

                    return array_map(
                        static fn (string $column): object => (object) [
                            'column_name' => $column,
                            'data_type' => $sourceMetadata[$table][$column]['data_type'],
                            'column_type' => $sourceMetadata[$table][$column]['column_type'],
                        ],
                        array_values(array_filter(
                            array_keys($sourceMetadata[$table] ?? []),
                            static fn (string $column): bool => $column !== 'primary_key',
                        )),
                    );
                }

                if (str_contains($query, 'information_schema.KEY_COLUMN_USAGE')) {
                    $table = (string) ($bindings[1] ?? '');

                    return array_map(
                        static fn (string $column): object => (object) ['column_name' => $column],
                        $sourceMetadata[$table]['primary_key'] ?? [],
                    );
                }

                throw new \RuntimeException('Unexpected source metadata query: '.$query);
            },
        );

        $manager = Mockery::mock(DatabaseManager::class);
        $manager->shouldReceive('connection')->with('mariadb')->andReturn($source);
        $manager->shouldReceive('connection')->with('pgsql')->andReturn($target);
        $manager->shouldReceive('purge')->zeroOrMoreTimes();

        $originalManager = $this->app->make('db');
        $this->app->instance('db', $manager);
        DB::clearResolvedInstance('db');

        try {
            $command = new PostgresCopy;
            $command->setLaravel($this->app);
            $output = new BufferedOutput;
            $status = $command->run(
                new ArrayInput($options),
                $output,
            );
        } finally {
            $this->app->instance('db', $originalManager);
            DB::clearResolvedInstance('db');
        }

        return [$status, $output->fetch()];
    }

    /**
     * @return array<string, array<string, array{data_type: string, column_type: string}|list<string>>>
     */
    private function sourceMetadata(): array
    {
        return [
            'roles' => [
                'primary_key' => ['id'],
                'id' => ['data_type' => 'char', 'column_type' => 'char(36)'],
                'name' => ['data_type' => 'varchar', 'column_type' => 'varchar(255)'],
            ],
            'user_roles' => [
                'primary_key' => ['user_id', 'role_id'],
                'user_id' => ['data_type' => 'char', 'column_type' => 'char(36)'],
                'role_id' => ['data_type' => 'char', 'column_type' => 'char(36)'],
            ],
            'password_reset_tokens' => [
                'primary_key' => ['email'],
                'email' => ['data_type' => 'varchar', 'column_type' => 'varchar(255)'],
                'token' => ['data_type' => 'varchar', 'column_type' => 'varchar(255)'],
            ],
            'feature_flags' => [
                'primary_key' => ['id'],
                'id' => ['data_type' => 'int', 'column_type' => 'int(11)'],
                'enabled' => ['data_type' => 'tinyint', 'column_type' => 'tinyint(1)'],
            ],
            'events' => [
                'primary_key' => ['id'],
                'id' => ['data_type' => 'int', 'column_type' => 'int(11)'],
                'deleted_at' => ['data_type' => 'datetime', 'column_type' => 'datetime'],
            ],
            'attachments' => [
                'primary_key' => ['id'],
                'id' => ['data_type' => 'int', 'column_type' => 'int(11)'],
                'payload' => ['data_type' => 'longblob', 'column_type' => 'longblob'],
            ],
            'documents' => [
                'primary_key' => ['id'],
                'id' => ['data_type' => 'int', 'column_type' => 'int(11)'],
                'payload' => ['data_type' => 'longtext', 'column_type' => 'longtext'],
            ],
            'sequence_rows' => [
                'primary_key' => ['id'],
                'id' => ['data_type' => 'int', 'column_type' => 'int(11)'],
                'label' => ['data_type' => 'varchar', 'column_type' => 'varchar(255)'],
            ],
        ];
    }

    private function createFixtureSchemas(): void
    {
        $this->source = $this->sourceConnection();
        $source = $this->quote($this->sourceSchema);
        $target = $this->quote($this->targetSchema);

        $this->source->statement('CREATE SCHEMA '.$source);
        DB::statement('CREATE SCHEMA '.$target);

        $this->source->statement(
            'CREATE TABLE '.$source.'.roles (id UUID PRIMARY KEY, name TEXT NOT NULL)',
        );
        DB::statement(
            'CREATE TABLE '.$target.'.roles (id UUID PRIMARY KEY, name TEXT NOT NULL)',
        );

        $this->source->statement(
            'CREATE TABLE '.$source.'.user_roles (user_id UUID NOT NULL, role_id UUID NOT NULL, PRIMARY KEY (user_id, role_id))',
        );
        DB::statement(
            'CREATE TABLE '.$target.'.user_roles (user_id UUID NOT NULL, role_id UUID NOT NULL, '
            .'PRIMARY KEY (user_id, role_id), '
            .'CONSTRAINT user_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES '.$target.'.roles (id))',
        );

        $this->source->statement(
            'CREATE TABLE '.$source.'.password_reset_tokens (email TEXT PRIMARY KEY, token TEXT NOT NULL)',
        );
        DB::statement(
            'CREATE TABLE '.$target.'.password_reset_tokens (email TEXT PRIMARY KEY, token TEXT NOT NULL)',
        );

        $this->source->statement(
            'CREATE TABLE '.$source.'.feature_flags (id INTEGER PRIMARY KEY, enabled SMALLINT NOT NULL)',
        );
        DB::statement(
            'CREATE TABLE '.$target.'.feature_flags (id INTEGER PRIMARY KEY, enabled BOOLEAN NOT NULL)',
        );

        $this->source->statement(
            'CREATE TABLE '.$source.'.events (id INTEGER PRIMARY KEY, deleted_at TEXT NULL)',
        );
        DB::statement(
            'CREATE TABLE '.$target.'.events (id INTEGER PRIMARY KEY, deleted_at TIMESTAMP NULL)',
        );

        $this->source->statement(
            'CREATE TABLE '.$source.'.attachments (id INTEGER PRIMARY KEY, payload BYTEA NOT NULL)',
        );
        DB::statement(
            'CREATE TABLE '.$target.'.attachments (id INTEGER PRIMARY KEY, payload BYTEA NOT NULL)',
        );

        $this->source->statement(
            'CREATE TABLE '.$source.'.documents (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)',
        );
        DB::statement(
            'CREATE TABLE '.$target.'.documents (id INTEGER PRIMARY KEY, payload JSONB NOT NULL)',
        );

        $this->source->statement(
            'CREATE TABLE '.$source.'.sequence_rows (id INTEGER PRIMARY KEY, label TEXT NOT NULL)',
        );
        DB::statement(
            'CREATE TABLE '.$target.'.sequence_rows (id BIGSERIAL PRIMARY KEY, label TEXT NOT NULL)',
        );

        $this->source->statement('SET search_path TO '.$source);
        DB::statement('SET search_path TO '.$target);
    }

    private function insertFixtureRows(): void
    {
        $this->source->statement(
            'INSERT INTO roles (id, name) VALUES (?, ?), (?, ?)',
            [self::ROLE_ADMIN, 'super_admin', self::ROLE_PHOTOGRAPHER, 'photographer'],
        );
        DB::statement(
            'INSERT INTO roles (id, name) VALUES (?, ?), (?, ?)',
            [self::ROLE_ADMIN, 'super_admin', self::ROLE_PHOTOGRAPHER, 'photographer'],
        );

        $this->source->statement(
            'INSERT INTO user_roles (user_id, role_id) VALUES (?, ?), (?, ?)',
            [self::USER_ONE, self::ROLE_ADMIN, self::USER_TWO, self::ROLE_PHOTOGRAPHER],
        );
        DB::statement(
            'INSERT INTO user_roles (user_id, role_id) VALUES (?, ?), (?, ?)',
            [self::USER_ONE, self::ROLE_ADMIN, self::USER_TWO, self::ROLE_PHOTOGRAPHER],
        );

        $this->source->statement(
            'INSERT INTO password_reset_tokens (email, token) VALUES (?, ?)',
            ['person@example.test', 'source-reset-token'],
        );
        DB::statement(
            'INSERT INTO password_reset_tokens (email, token) VALUES (?, ?)',
            ['person@example.test', 'source-reset-token'],
        );

        // MariaDB tinyint(1) values become PostgreSQL booleans.
        $this->source->statement(
            'INSERT INTO feature_flags (id, enabled) VALUES (1, 1), (2, 0)',
        );
        DB::statement(
            'INSERT INTO feature_flags (id, enabled) VALUES (1, true), (2, false)',
        );

        // A MariaDB zero date becomes NULL on PostgreSQL.
        $this->source->statement(
            "INSERT INTO events (id, deleted_at) VALUES (1, '0000-00-00 00:00:00')",
        );
        DB::statement('INSERT INTO events (id, deleted_at) VALUES (1, NULL)');

        $this->source->statement(
            "INSERT INTO attachments (id, payload) VALUES (1, decode('00ff706f7274616c00', 'hex'))",
        );
        DB::statement(
            "INSERT INTO attachments (id, payload) VALUES (1, decode('00ff706f7274616c00', 'hex'))",
        );

        // MariaDB JSON text has a different object key order than jsonb.
        $this->source->statement(
            'INSERT INTO documents (id, payload) VALUES (1, ?)',
            ['{"b": 2, "a": 1}'],
        );
        DB::statement(
            'INSERT INTO documents (id, payload) VALUES (1, ?::jsonb)',
            ['{"a":1,"b":2}'],
        );
    }

    private function sourceConnection(): Connection
    {
        config(['database.connections.pgsql_copy_source' => config('database.connections.pgsql')]);
        DB::purge('pgsql_copy_source');

        return DB::connection('pgsql_copy_source');
    }

    private function quote(string $identifier): string
    {
        return DB::connection()->getQueryGrammar()->wrapTable($identifier);
    }

    private function literal(string $value): string
    {
        $quoted = DB::connection()->getPdo()->quote($value);

        if ($quoted === false) {
            throw new \RuntimeException('Could not quote a test literal.');
        }

        return $quoted;
    }
}
