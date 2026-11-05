<?php

namespace Tests\Feature\Database;

use App\Console\Commands\PostgresCopy;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

class PostgresCopyTargetResetTest extends TestCase
{
    use RefreshDatabase;

    private ?Connection $source = null;

    private string $sourceSchema = '';

    private string $targetSchema = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $suffix = str_replace('-', '', (string) Str::uuid());
        $this->sourceSchema = 'pc_copy_source_'.$suffix;
        $this->targetSchema = 'pc_copy_target_'.$suffix;
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

    public function test_copy_replaces_stale_target_rows_and_repeats_from_the_current_source(): void
    {
        $this->createSourceAndTargetSchemas(withSettings: true);

        $this->source->statement(
            'INSERT INTO roles (id, name) VALUES '
            ."('11111111-1111-4111-8111-111111111111', 'super_admin'), "
            ."('22222222-2222-4222-8222-222222222222', 'photographer')",
        );
        $this->source->statement(
            'INSERT INTO settings (key, value) VALUES '
            ."('base_price', '14900'), ('term_editorial', 'source text')",
        );

        DB::statement(
            'INSERT INTO roles (id, name) VALUES '
            ."('99999999-9999-4999-8999-999999999999', 'super_admin'), "
            ."('88888888-8888-4888-8888-888888888888', 'legacy_only')",
        );
        DB::statement(
            'INSERT INTO settings (key, value) VALUES '
            ."('base_price', '35.00'), ('calc_hourly_rate', '120')",
        );

        $this->copy(['roles', 'settings']);

        $this->assertSame(
            [
                '11111111-1111-4111-8111-111111111111' => 'super_admin',
                '22222222-2222-4222-8222-222222222222' => 'photographer',
            ],
            $this->rows('roles', 'id', 'name'),
        );
        $this->assertSame(
            [
                'base_price' => '14900',
                'term_editorial' => 'source text',
            ],
            $this->rows('settings', 'key', 'value'),
        );
        $this->assertSame(
            [
                '11111111-1111-4111-8111-111111111111' => 'super_admin',
                '22222222-2222-4222-8222-222222222222' => 'photographer',
            ],
            $this->sourceRows('roles', 'id', 'name'),
        );
        $this->assertSame('origin', $this->sessionReplicationRole());

        $this->source->statement(
            "UPDATE settings SET value = '15000' WHERE key = 'base_price'",
        );
        $this->source->statement("DELETE FROM roles WHERE name = 'photographer'");

        $this->copy(['roles', 'settings']);

        $this->assertSame(
            ['11111111-1111-4111-8111-111111111111' => 'super_admin'],
            $this->rows('roles', 'id', 'name'),
        );
        $this->assertSame(
            [
                'base_price' => '15000',
                'term_editorial' => 'source text',
            ],
            $this->rows('settings', 'key', 'value'),
        );
        $this->assertSame('origin', $this->sessionReplicationRole());
    }

    public function test_copy_fails_and_rolls_back_instead_of_ignoring_a_target_conflict(): void
    {
        $this->createSourceAndTargetSchemas(withSettings: false);
        DB::statement('CREATE UNIQUE INDEX roles_name_lower_unique ON roles (lower(name))');

        $this->source->statement(
            'INSERT INTO roles (id, name) VALUES '
            ."('11111111-1111-4111-8111-111111111111', 'Admin'), "
            ."('22222222-2222-4222-8222-222222222222', 'admin')",
        );
        DB::statement(
            'INSERT INTO roles (id, name) VALUES '
            ."('99999999-9999-4999-8999-999999999999', 'stale_admin')",
        );

        try {
            $this->copy(['roles']);
            $this->fail('The copy accepted rows that the target unique index rejects.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Failed to insert source rows into', $exception->getMessage());
            $this->assertStringContainsString('roles', $exception->getMessage());
        }

        $this->assertSame(
            ['99999999-9999-4999-8999-999999999999' => 'stale_admin'],
            $this->rows('roles', 'id', 'name'),
        );
        $this->assertSame('origin', $this->sessionReplicationRole());
    }

    public function test_copy_refuses_to_reset_when_a_target_only_table_references_a_copied_table(): void
    {
        $this->createSourceAndTargetSchemas(withSettings: false);

        $this->source->statement(
            'INSERT INTO roles (id, name) VALUES '
            ."('11111111-1111-4111-8111-111111111111', 'super_admin')",
        );
        DB::statement(
            'INSERT INTO roles (id, name) VALUES '
            ."('99999999-9999-4999-8999-999999999999', 'stale_admin')",
        );
        DB::statement(
            'CREATE TABLE audit_refs (role_id UUID NOT NULL REFERENCES roles (id))',
        );
        DB::statement(
            "INSERT INTO audit_refs (role_id) VALUES ('99999999-9999-4999-8999-999999999999')",
        );

        try {
            $this->copy(['roles']);
            $this->fail('The copy reset tables that a target-only table references.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'tables outside the source set reference copied tables',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            ['99999999-9999-4999-8999-999999999999' => 'stale_admin'],
            $this->rows('roles', 'id', 'name'),
        );
        $this->assertSame('origin', $this->sessionReplicationRole());
    }

    private function createSourceAndTargetSchemas(bool $withSettings): void
    {
        $this->source = $this->sourceConnection();
        $source = $this->quote($this->sourceSchema);
        $target = $this->quote($this->targetSchema);

        $this->source->statement('CREATE SCHEMA '.$source);
        DB::statement('CREATE SCHEMA '.$target);

        $this->source->statement(
            'CREATE TABLE '.$source.'.roles (id UUID PRIMARY KEY, name TEXT NOT NULL UNIQUE)',
        );
        DB::statement(
            'CREATE TABLE '.$target.'.roles (id UUID PRIMARY KEY, name TEXT NOT NULL UNIQUE)',
        );

        if ($withSettings) {
            $this->source->statement(
                'CREATE TABLE '.$source.'.settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
            );
            DB::statement(
                'CREATE TABLE '.$target.'.settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
            );
        }

        $this->source->statement('SET search_path TO '.$source);
        DB::statement('SET search_path TO '.$target);
    }

    private function sourceConnection(): Connection
    {
        config(['database.connections.pgsql_copy_source' => config('database.connections.pgsql')]);
        DB::purge('pgsql_copy_source');

        return DB::connection('pgsql_copy_source');
    }

    /** @param list<string> $tables */
    private function copy(array $tables): void
    {
        $command = new PostgresCopy;
        $sourceMetadata = [];

        foreach ($tables as $table) {
            $sourceMetadata[$table] = $table === 'roles'
                ? [
                    'id' => ['data_type' => 'char', 'column_type' => 'char(36)'],
                    'name' => ['data_type' => 'varchar', 'column_type' => 'varchar(255)'],
                ]
                : [
                    'key' => ['data_type' => 'varchar', 'column_type' => 'varchar(255)'],
                    'value' => ['data_type' => 'text', 'column_type' => 'text'],
                ];
        }

        (new ReflectionProperty($command, 'output'))->setValue($command, new BufferedOutput);
        (new ReflectionProperty($command, 'sourceColumnMetadata'))->setValue($command, $sourceMetadata);
        (new ReflectionProperty($command, 'sharedColumns'))->setValue(
            $command,
            array_map(
                static fn (array $columns): array => array_keys($columns),
                $sourceMetadata,
            ),
        );
        (new ReflectionProperty($command, 'primaryKeys'))->setValue(
            $command,
            array_map(
                static fn (string $table): array => [$table === 'roles' ? 'id' : 'key'],
                array_combine($tables, $tables),
            ),
        );

        (new ReflectionMethod(PostgresCopy::class, 'copyRows'))->invoke(
            $command,
            $this->source,
            DB::connection(),
            $tables,
            500,
        );
    }

    /**
     * @return array<string, string>
     */
    private function rows(string $table, string $keyColumn, string $valueColumn): array
    {
        $rows = [];
        foreach (DB::table($table)->orderBy($keyColumn)->get() as $row) {
            $rows[(string) $row->{$keyColumn}] = (string) $row->{$valueColumn};
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function sourceRows(string $table, string $keyColumn, string $valueColumn): array
    {
        $rows = [];
        foreach ($this->source->table($table)->orderBy($keyColumn)->get() as $row) {
            $rows[(string) $row->{$keyColumn}] = (string) $row->{$valueColumn};
        }

        return $rows;
    }

    private function sessionReplicationRole(): string
    {
        return (string) DB::selectOne('SHOW session_replication_role')->session_replication_role;
    }

    private function quote(string $identifier): string
    {
        return DB::connection()->getQueryGrammar()->wrapTable($identifier);
    }
}
