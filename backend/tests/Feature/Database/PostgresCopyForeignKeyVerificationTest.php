<?php

namespace Tests\Feature\Database;

use App\Console\Commands\PostgresCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PostgresCopyForeignKeyVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_key_verification_reads_composite_references_from_postgres_metadata(): void
    {
        $connection = DB::connection();
        $this->assertSame('pgsql', $connection->getDriverName());

        $suffix = substr(str_replace('-', '', (string) Str::uuid()), 0, 10);
        $parentTable = 'pc_fk_parent_'.$suffix;
        $childTable = 'pc_fk_child_'.$suffix;
        $constraint = 'pc_fk_pair_'.$suffix;

        $connection->statement(
            "CREATE TABLE \"{$parentTable}\" (tenant_key INTEGER NOT NULL, parent_key VARCHAR(24) NOT NULL, PRIMARY KEY (tenant_key, parent_key))",
        );
        $connection->statement(
            "CREATE TABLE \"{$childTable}\" (parent_ref VARCHAR(24) NOT NULL, tenant_ref INTEGER NOT NULL, CONSTRAINT \"{$constraint}\" FOREIGN KEY (parent_ref, tenant_ref) REFERENCES \"{$parentTable}\" (parent_key, tenant_key))",
        );
        $connection->table($parentTable)->insert(['tenant_key' => 17, 'parent_key' => 'alpha']);
        $connection->table($childTable)->insert(['parent_ref' => 'alpha', 'tenant_ref' => 17]);

        $command = new PostgresCopy;
        $output = new BufferedOutput;
        (new ReflectionProperty($command, 'output'))->setValue($command, $output);

        (new ReflectionMethod(PostgresCopy::class, 'verifyForeignKeys'))->invoke($command, $connection);

        $this->assertStringContainsString(
            "Foreign key {$childTable}.{$constraint}: no orphans.",
            $output->fetch(),
        );
    }
}
