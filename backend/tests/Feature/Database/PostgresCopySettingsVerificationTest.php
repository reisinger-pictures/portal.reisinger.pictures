<?php

namespace Tests\Feature\Database;

use App\Console\Commands\PostgresCopy;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

class PostgresCopySettingsVerificationTest extends TestCase
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
        $this->sourceSchema = 'pc_settings_source_'.$suffix;
        $this->targetSchema = 'pc_settings_target_'.$suffix;
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

    public function test_verify_only_rejects_a_same_count_target_setting_with_the_wrong_value(): void
    {
        $this->createSettingsSchemas();

        $this->source->statement(
            'INSERT INTO settings (key, brand, value) VALUES '
            ."('base_price', 'rp', '14900'), "
            ."('term_editorial', 'rp', 'source text')",
        );
        DB::statement(
            'INSERT INTO settings (key, brand, value) VALUES '
            ."('base_price', 'rp', '14900'), "
            ."('term_editorial', 'rp', 'stale target text')",
        );

        [$status, $output] = $this->runVerifyOnlyCommand();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString(
            "Settings value mismatch for key='term_editorial', brand='rp'",
            $output,
        );
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_settings_verification_distinguishes_brand_and_null_values(): void
    {
        // Production has a primary key on (key, brand), but the verifier must
        // still treat null and a concrete brand as different identities and
        // null as different from an empty string.
        $this->createSettingsSchemas(withPrimaryKey: false);

        $this->source->statement(
            'INSERT INTO settings (key, brand, value) VALUES '
            ."('null_value', 'rp', NULL), "
            ."('brand_identity', NULL, 'same value')",
        );
        DB::statement(
            'INSERT INTO settings (key, brand, value) VALUES '
            ."('null_value', 'rp', ''), "
            ."('brand_identity', 'rp', 'same value')",
        );

        $command = new PostgresCopy;
        (new ReflectionProperty($command, 'output'))->setValue($command, new BufferedOutput);
        (new ReflectionProperty($command, 'sourceColumnMetadata'))->setValue($command, [
            'settings' => [
                'key' => ['data_type' => 'varchar', 'column_type' => 'varchar(255)'],
                'brand' => ['data_type' => 'varchar', 'column_type' => 'varchar(16)'],
                'value' => ['data_type' => 'text', 'column_type' => 'text'],
            ],
        ]);

        (new ReflectionMethod(PostgresCopy::class, 'verifySettings'))->invoke(
            $command,
            $this->source,
            DB::connection(),
        );

        /** @var list<string> $issues */
        $issues = (new ReflectionProperty($command, 'issues'))->getValue($command);

        $this->assertContains(
            "Settings value mismatch for key='null_value', brand='rp': the source and target values differ.",
            $issues,
        );
        $this->assertContains(
            "Settings row exists only in the source: key='brand_identity', brand=NULL.",
            $issues,
        );
        $this->assertContains(
            "Settings row exists only in the target: key='brand_identity', brand='rp'.",
            $issues,
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runVerifyOnlyCommand(): array
    {
        $target = DB::connection();
        $sourceForRows = $this->source;
        $this->assertNotNull($sourceForRows);

        $sourceMetadata = [
            'settings' => [
                'key' => ['data_type' => 'varchar', 'column_type' => 'varchar(255)'],
                'brand' => ['data_type' => 'varchar', 'column_type' => 'varchar(16)'],
                'value' => ['data_type' => 'text', 'column_type' => 'text'],
            ],
        ];

        $source = Mockery::mock(Connection::class);
        $source->shouldReceive('getDatabaseName')->andReturn($this->sourceSchema);
        $source->shouldReceive('statement')->andReturnTrue();
        $source->shouldReceive('table')->andReturnUsing(
            fn (string $table) => $sourceForRows->table($table),
        );
        $source->shouldReceive('select')->andReturnUsing(
            function (string $query) use ($sourceMetadata): array {
                if (str_contains($query, 'information_schema.TABLES')) {
                    return [(object) ['table_name' => 'settings']];
                }

                if (str_contains($query, 'information_schema.COLUMNS')) {
                    return array_map(
                        static fn (string $column): object => (object) [
                            'column_name' => $column,
                            'data_type' => $sourceMetadata['settings'][$column]['data_type'],
                            'column_type' => $sourceMetadata['settings'][$column]['column_type'],
                        ],
                        array_keys($sourceMetadata['settings']),
                    );
                }

                if (str_contains($query, 'information_schema.KEY_COLUMN_USAGE')) {
                    return [
                        (object) ['column_name' => 'key'],
                        (object) ['column_name' => 'brand'],
                    ];
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
            $status = $command->run(new ArrayInput(['--verify-only' => true]), $output);
        } finally {
            $this->app->instance('db', $originalManager);
            DB::clearResolvedInstance('db');
        }

        return [$status, $output->fetch()];
    }

    private function createSettingsSchemas(bool $withPrimaryKey = true): void
    {
        $this->source = $this->sourceConnection();
        $source = $this->quote($this->sourceSchema);
        $target = $this->quote($this->targetSchema);
        $primaryKey = $withPrimaryKey ? ', PRIMARY KEY (key, brand)' : '';

        $this->source->statement('CREATE SCHEMA '.$source);
        DB::statement('CREATE SCHEMA '.$target);

        $this->source->statement(
            'CREATE TABLE '.$source.'.settings (key TEXT NOT NULL, brand TEXT NULL, value TEXT NULL'.$primaryKey.')',
        );
        DB::statement(
            'CREATE TABLE '.$target.'.settings (key TEXT NOT NULL, brand TEXT NULL, value TEXT NULL'.$primaryKey.')',
        );

        $this->source->statement('SET search_path TO '.$source);
        DB::statement('SET search_path TO '.$target);
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
}
