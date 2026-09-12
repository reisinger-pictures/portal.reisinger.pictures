<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression: the importer fetched over plain HTTP and truncated the table
 * unconditionally, wiping existing data when the download failed.
 */
class ImportLocationsNonDestructiveTest extends TestCase
{
    use RefreshDatabase;

    private function clearLocalCache(): void
    {
        $tempDir = storage_path('app/private/temp');
        foreach (['AT_postal.zip', 'AT_postal.txt', 'AT_places.zip', 'AT_places.txt', 'countryInfo.txt'] as $file) {
            @unlink($tempDir.'/'.$file);
        }
    }

    public function test_failed_download_keeps_existing_locations_and_uses_https(): void
    {
        $this->clearLocalCache();

        $id = (string) Str::uuid();
        DB::table('locations')->insert([
            'id' => $id,
            'type' => 'city',
            'name' => 'Existing City',
            'state' => 'Wien',
            'country' => 'Österreich',
            'iso_country' => 'AT',
            'postal_code' => '1010',
            'population' => 1,
        ]);

        Http::fake([
            'download.geonames.org/*' => Http::response('gateway error', 502),
        ]);

        $this->artisan('app:import-locations')->assertExitCode(0);

        $this->assertDatabaseHas('locations', [
            'id' => $id,
            'name' => 'Existing City',
        ]);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://download.geonames.org/');
        });
    }
}
