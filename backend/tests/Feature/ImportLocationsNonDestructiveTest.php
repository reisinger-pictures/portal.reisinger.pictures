<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression: the importer fetched over plain HTTP and truncated the table
 * unconditionally, wiping existing data when the download failed.
 */
class ImportLocationsNonDestructiveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string>
     */
    private const CACHE_FILES = [
        'AT_postal.zip',
        'AT_postal.txt',
        'AT_places.zip',
        'AT_places.txt',
        'countryInfo.txt',
        'AT_postal.zip.part',
        'AT_postal.txt.part',
        'AT_places.zip.part',
        'AT_places.txt.part',
        'countryInfo.txt.part',
    ];

    /**
     * @var array<int, string>
     */
    private const DOWNLOAD_PART_FILES = [
        'AT_postal.zip.part',
        'AT_places.zip.part',
        'countryInfo.txt.part',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearLocalCache();
    }

    protected function tearDown(): void
    {
        $this->clearLocalCache();

        parent::tearDown();
    }

    public function test_failed_download_is_retryable_and_keeps_existing_locations(): void
    {
        $id = $this->insertLocation('city', 'Existing City', '1010');
        Log::spy();

        Http::fake([
            'download.geonames.org/*' => Http::response('gateway error', 502),
        ]);

        $this->artisan('app:import-locations')
            ->expectsOutputToContain('Bestehende Location-Daten bleiben unverändert.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('locations', [
            'id' => $id,
            'name' => 'Existing City',
        ]);

        // A second run must acquire the released lock and retry all downloads.
        $this->artisan('app:import-locations')->assertExitCode(1);

        $this->assertDatabaseHas('locations', [
            'id' => $id,
            'name' => 'Existing City',
        ]);
        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://download.geonames.org/'
        ));
        Log::shouldHaveReceived('error')
            ->twice()
            ->with('Location import aborted: no source data available');
        Log::shouldHaveReceived('warning')
            ->times(6)
            ->withArgs(fn (string $message, array $context): bool => $message === 'Location import download failed'
                && $context['reason'] === 'http_error'
                && $context['http_status'] === 502);
    }

    public function test_empty_successful_responses_are_fail_closed_and_clean_staging_artifacts(): void
    {
        $id = $this->insertLocation('country', 'Existing Country', null);
        $tempDir = storage_path('app/private/temp');
        File::put($tempDir.'/AT_postal.txt', 'unparseable cached postal data');
        File::put($tempDir.'/countryInfo.txt', 'unparseable cached country data');
        File::put($tempDir.'/AT_postal.zip', 'stale archive');
        File::put($tempDir.'/AT_places.zip', 'stale archive');
        foreach (self::DOWNLOAD_PART_FILES as $file) {
            File::put($tempDir.'/'.$file, 'stale partial download');
        }
        Log::spy();

        Http::fake([
            'download.geonames.org/*' => Http::response('', 200),
        ]);

        $this->artisan('app:import-locations')->assertExitCode(1);

        $this->assertDatabaseHas('locations', [
            'id' => $id,
            'name' => 'Existing Country',
        ]);
        $this->assertSame('unparseable cached postal data', File::get($tempDir.'/AT_postal.txt'));
        $this->assertSame('unparseable cached country data', File::get($tempDir.'/countryInfo.txt'));
        $this->assertFileDoesNotExist($tempDir.'/AT_postal.zip');
        $this->assertFileDoesNotExist($tempDir.'/AT_places.zip');
        foreach (self::DOWNLOAD_PART_FILES as $file) {
            $this->assertFileDoesNotExist($tempDir.'/'.$file);
        }
        Log::shouldHaveReceived('warning')
            ->times(3)
            ->withArgs(fn (string $message, array $context): bool => $message === 'Location import download failed'
                && $context['reason'] === 'empty_body');
        Log::shouldHaveReceived('error')
            ->once()
            ->with('Location import aborted: no source data available');
    }

    public function test_502_uses_cached_dataset_without_replacing_other_existing_location_types(): void
    {
        config(['scout.driver' => 'null']);
        $countryId = $this->insertLocation('country', 'Existing Country', null);
        File::put(
            storage_path('app/private/temp/AT_postal.txt'),
            "AT\t1020\tWien\tWien"
        );

        Http::fake([
            'download.geonames.org/*' => Http::response('gateway error', 502),
        ]);

        $this->artisan('app:import-locations')->assertExitCode(0);

        $this->assertDatabaseHas('locations', [
            'id' => $countryId,
            'name' => 'Existing Country',
        ]);
        $this->assertDatabaseHas('locations', [
            'type' => 'city',
            'postal_code' => '1020',
            'name' => 'Wien',
        ]);
    }

    private function clearLocalCache(): void
    {
        $tempDir = storage_path('app/private/temp');
        if (! is_dir($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        foreach (self::CACHE_FILES as $file) {
            File::delete($tempDir.'/'.$file);
        }

        foreach (glob($tempDir.'/extract_*') ?: [] as $directory) {
            File::deleteDirectory($directory);
        }
    }

    private function insertLocation(string $type, string $name, ?string $postalCode): string
    {
        $id = (string) Str::uuid();
        DB::table('locations')->insert([
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'state' => $type === 'city' ? 'Wien' : null,
            'country' => $type === 'city' ? 'Österreich' : null,
            'iso_country' => $type === 'city' ? 'AT' : null,
            'postal_code' => $postalCode,
            'population' => 1,
        ]);

        return $id;
    }
}
