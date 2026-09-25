<?php

namespace Tests\Feature\Contract;

use App\Models\Contract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class ContractSignerIdentityRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_mariadb_or_mysql_multi_connection_race_has_one_canonical_winner(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('This race requires a real MariaDB/MySQL connection; SQLite :memory: cannot provide independent writer connections.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This race requires pcntl_fork to coordinate two independent database connections.');
        }

        $connectionConfig = config('database.connections.'.$driver);
        config([
            'database.connections.contract_identity_race_a' => $connectionConfig,
            'database.connections.contract_identity_race_b' => $connectionConfig,
        ]);
        DB::purge('contract_identity_race_a');
        DB::purge('contract_identity_race_b');

        $contract = Contract::factory()->create([
            'status' => 'active',
        ]);
        $scopeKey = 'contract:'.$contract->id;
        $email = 'mariadb-race@example.com';
        $barrierDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portal-contract-race-'.Str::uuid();
        mkdir($barrierDirectory, 0700, true);
        $goFile = $barrierDirectory.DIRECTORY_SEPARATOR.'go';
        $children = [];

        try {
            foreach (['a', 'b'] as $worker) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Unable to fork the MariaDB/MySQL race worker.');
                }

                if ($pid === 0) {
                    $this->runRaceChild(
                        'contract_identity_race_'.$worker,
                        $barrierDirectory.DIRECTORY_SEPARATOR.'ready-'.$worker,
                        $barrierDirectory.DIRECTORY_SEPARATOR.'result-'.$worker,
                        $goFile,
                        $contract->id,
                        $scopeKey,
                        $email,
                    );
                }

                $children[] = $pid;
            }

            $this->waitForBarrierFiles([
                $barrierDirectory.DIRECTORY_SEPARATOR.'ready-a',
                $barrierDirectory.DIRECTORY_SEPARATOR.'ready-b',
            ]);
            file_put_contents($goFile, "go\n", LOCK_EX);

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $children = [];

            $resultA = trim((string) file_get_contents($barrierDirectory.DIRECTORY_SEPARATOR.'result-a'));
            $resultB = trim((string) file_get_contents($barrierDirectory.DIRECTORY_SEPARATOR.'result-b'));
            $results = [$resultA, $resultB];
            sort($results);

            $this->assertSame(['duplicate', 'ok'], $results);
            $this->assertSame(1, DB::table('contract_signers')
                ->where('join_scope_key', $scopeKey)
                ->where('normalized_email', $email)
                ->count());
        } finally {
            if (isset($contract)) {
                DB::table('contracts')->where('id', $contract->id)->delete();
            }

            foreach ($children as $pid) {
                if (function_exists('posix_kill')) {
                    @posix_kill($pid, SIGTERM);
                }
            }

            (new Filesystem)->deleteDirectory($barrierDirectory);
            DB::purge('contract_identity_race_a');
            DB::purge('contract_identity_race_b');
        }
    }

    private function runRaceChild(
        string $connectionName,
        string $readyFile,
        string $resultFile,
        string $goFile,
        string $contractId,
        string $scopeKey,
        string $email,
    ): void {
        file_put_contents($readyFile, "ready\n", LOCK_EX);
        $deadline = microtime(true) + 10;
        while (! is_file($goFile) && microtime(true) < $deadline) {
            usleep(1000);
        }

        if (! is_file($goFile)) {
            file_put_contents($resultFile, 'error:barrier-timeout', LOCK_EX);
            exit(1);
        }

        try {
            DB::purge($connectionName);
            DB::disconnect($connectionName);
            DB::connection($connectionName)->table('contract_signers')->insert([
                'id' => (string) Str::uuid(),
                'contract_id' => $contractId,
                'name' => 'MariaDB race signer',
                'email' => $email,
                'normalized_email' => $email,
                'join_scope_key' => $scopeKey,
                'roles' => json_encode(['Model']),
                'personal_token' => (string) Str::random(64),
                'status' => 'joined',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::disconnect($connectionName);
            file_put_contents($resultFile, 'ok', LOCK_EX);
            exit(0);
        } catch (Throwable $exception) {
            DB::disconnect($connectionName);
            $message = strtolower($exception->getMessage());
            $result = str_contains($message, 'unique')
                || str_contains($message, 'duplicate')
                ? 'duplicate'
                : 'error:'.$exception->getMessage();
            file_put_contents($resultFile, $result, LOCK_EX);
            exit(0);
        }
    }

    private function waitForBarrierFiles(array $files): void
    {
        $deadline = microtime(true) + 10;
        do {
            $ready = true;
            foreach ($files as $file) {
                if (! is_file($file)) {
                    $ready = false;
                    break;
                }
            }

            if ($ready) {
                return;
            }

            usleep(1000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for MariaDB/MySQL race workers.');
    }
}
