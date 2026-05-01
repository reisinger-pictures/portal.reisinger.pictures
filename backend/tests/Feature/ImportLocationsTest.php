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
}
