<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_locations_command_runs_without_crashing_on_http_failure()
    {
        // Lokalen Cache löschen, damit der Fallback nicht greift
        $tempDir = storage_path('app/private/temp');
        @unlink($tempDir.'/AT_postal.txt');
        @unlink($tempDir.'/AT_places.txt');
        @unlink($tempDir.'/countryInfo.txt');

        // HTTP Aufrufe faken, um das Netzwerk nicht zu belasten und das Failure-Handling zu testen
        Http::fake([
            'download.geonames.org/*' => Http::response('Not Found', 404),
        ]);

        $this->artisan('app:import-locations')->assertExitCode(0);

        // Da die Downloads 404 sind, sollte das Command gracefully durchlaufen, ohne Daten zu seeden.
        $this->assertDatabaseCount('locations', 0);
    }

    public function test_import_locations_command_handles_connection_timeouts()
    {
        $tempDir = storage_path('app/private/temp');
        @unlink($tempDir.'/AT_postal.txt');
        @unlink($tempDir.'/AT_places.txt');
        @unlink($tempDir.'/countryInfo.txt');

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $this->artisan('app:import-locations')->assertExitCode(0);
        $this->assertDatabaseCount('locations', 0);
    }

    public function test_location_import_is_scheduled_once_with_single_server_guards(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'app:import-locations'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('30 3 * * 1', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_location_import_lock_prevents_concurrent_refreshes(): void
    {
        Http::fake();
        $lock = Cache::lock('portal:import-locations', 3600);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('app:import-locations')->assertExitCode(0);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }
}
