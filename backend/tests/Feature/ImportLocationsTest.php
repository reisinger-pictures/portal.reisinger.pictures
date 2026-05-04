<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ImportLocationsTest extends TestCase {
    use RefreshDatabase;

    public function test_import_locations_command_runs_without_crashing_on_http_failure() {
        // HTTP Aufrufe faken, um das Netzwerk nicht zu belasten und das Failure-Handling zu testen
        Http::fake([
            'download.geonames.org/*' => Http::response('Not Found', 404),
        ]);

        $this->artisan('app:import-locations')->assertExitCode(0);
        
        // Da die Downloads 404 sind, sollte das Command gracefully durchlaufen, ohne Daten zu seeden.
        $this->assertDatabaseCount('locations', 0);
    }

    public function test_import_locations_command_handles_connection_timeouts() {
        $tempDir = storage_path('app/private/temp');
        @unlink($tempDir . '/AT_postal.txt');
        @unlink($tempDir . '/AT_places.txt');
        @unlink($tempDir . '/countryInfo.txt');

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Connection timed out');
        });

        $this->artisan('app:import-locations')->assertExitCode(0);
        $this->assertDatabaseCount('locations', 0);
    }
}
