<?php

namespace Tests\Feature;

use Tests\TestCase;

class E2ELocationFixturePolicyTest extends TestCase
{
    public function test_fresh_e2e_setups_load_and_index_the_fixture_explicitly(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');
        $localSetup = $this->read('scripts/e2e-up.sh');

        $this->assertFixtureSequence($ci, 'php artisan db:seed --force');
        $this->assertFixtureSequence($localSetup, 'php artisan migrate:fresh --seed --env=e2e');

        $this->assertStringNotContainsString('app:import-locations', $ci);
        $this->assertStringNotContainsString('app:import-locations', $localSetup);
    }

    public function test_fresh_e2e_setups_wait_for_bounded_meilisearch_readiness_before_indexing(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');
        $localSetup = $this->read('scripts/e2e-up.sh');
        $readinessScript = $this->read('scripts/wait-for-meilisearch.sh');

        $this->assertSame(2, substr_count($ci, '- name: Wait for Meilisearch'));
        $this->assertStringContainsString(
            'MEILISEARCH_HEALTH_URL: http://127.0.0.1:7701/health',
            $ci
        );
        $this->assertStringContainsString(
            'MEILISEARCH_HEALTH_URL: http://meilisearch:7700/health',
            $ci
        );
        $this->assertStringContainsString(
            'MEILISEARCH_READY_TIMEOUT_SECONDS: "60"',
            $ci
        );
        $this->assertStringContainsString(
            'MEILISEARCH_REQUEST_TIMEOUT_SECONDS: "2"',
            $ci
        );
        $this->assertStringContainsString(
            'MEILISEARCH_REQUEST_TIMEOUT_SECONDS="$E2E_MEILISEARCH_REQUEST_TIMEOUT_SECONDS"',
            $localSetup
        );

        $this->assertReadinessBeforeScout($ci, 'bash scripts/wait-for-meilisearch.sh');
        $this->assertReadinessBeforeScout($localSetup, 'bash "$ROOT/scripts/wait-for-meilisearch.sh"');

        $this->assertStringContainsString('readonly MAX_TIMEOUT_SECONDS=300', $readinessScript);
        $this->assertStringContainsString('deadline=$((SECONDS + timeout_seconds))', $readinessScript);
        $this->assertStringContainsString('if (( request_timeout > remaining )); then', $readinessScript);
        $this->assertStringContainsString('--connect-timeout "$request_timeout"', $readinessScript);
        $this->assertStringContainsString('--max-time "$request_timeout"', $readinessScript);
        $this->assertStringContainsString('if (( sleep_for > remaining )); then', $readinessScript);
    }

    public function test_standard_seed_and_production_startup_do_not_load_e2e_fixtures(): void
    {
        $databaseSeeder = $this->read('backend/database/seeders/DatabaseSeeder.php');
        $productionCompose = $this->read('deployment/docker-compose.yml');

        $this->assertStringNotContainsString('E2ELocationSeeder', $databaseSeeder);
        $this->assertStringNotContainsString('app:import-locations', $databaseSeeder);
        $this->assertStringNotContainsString('E2ELocationSeeder', $productionCompose);
    }

    private function assertReadinessBeforeScout(string $contents, string $waitCommand): void
    {
        $waitPosition = strrpos($contents, $waitCommand);
        $indexPosition = strpos($contents, 'php artisan scout:flush');

        $this->assertNotFalse($waitPosition, "Missing Meilisearch readiness command: {$waitCommand}");
        $this->assertNotFalse($indexPosition, 'Missing Location Scout flush command');
        $this->assertGreaterThan(
            $waitPosition,
            $indexPosition,
            'Meilisearch readiness must complete before the E2E Scout index is modified'
        );
    }

    private function assertFixtureSequence(string $contents, string $initialSetupCommand): void
    {
        $commands = [
            $initialSetupCommand,
            'php artisan db:seed --class=E2ELocationSeeder --force',
            "php artisan scout:flush 'App\\Models\\Location'",
            'php artisan scout:sync-index-settings',
            "php artisan scout:import 'App\\Models\\Location'",
        ];

        $previousPosition = -1;
        foreach ($commands as $command) {
            $position = strpos($contents, $command);
            $this->assertNotFalse($position, "Missing E2E setup command: {$command}");
            $this->assertGreaterThan($previousPosition, $position, "E2E setup command is out of order: {$command}");
            $previousPosition = $position;
        }
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }
}
