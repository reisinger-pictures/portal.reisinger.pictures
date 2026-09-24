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

    public function test_standard_seed_and_production_startup_do_not_load_e2e_fixtures(): void
    {
        $databaseSeeder = $this->read('backend/database/seeders/DatabaseSeeder.php');
        $productionCompose = $this->read('deployment/docker-compose.yml');

        $this->assertStringNotContainsString('E2ELocationSeeder', $databaseSeeder);
        $this->assertStringNotContainsString('app:import-locations', $databaseSeeder);
        $this->assertStringNotContainsString('E2ELocationSeeder', $productionCompose);
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
