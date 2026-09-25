<?php

namespace Tests\Feature;

use App\Console\Commands\ImportLocations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use ZipArchive;

class ImportLocationsTest extends TestCase
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

    private function clearLocalCache(): void
    {
        $tempDir = storage_path('app/private/temp');
        foreach (self::CACHE_FILES as $file) {
            File::delete($tempDir.'/'.$file);
        }

        foreach (glob($tempDir.'/extract_*') ?: [] as $directory) {
            File::deleteDirectory($directory);
        }
    }

    public function test_import_locations_command_runs_without_crashing_on_http_failure()
    {
        // Fake HTTP calls to avoid network traffic while testing failure handling.
        Http::fake([
            'download.geonames.org/*' => Http::response('Not Found', 404),
        ]);

        $this->artisan('app:import-locations')->assertExitCode(1);

        // Without sources or cache, no import runs and the scheduler receives a retryable failure.
        $this->assertDatabaseCount('locations', 0);
    }

    public function test_import_locations_command_handles_connection_timeouts()
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $this->artisan('app:import-locations')->assertExitCode(1);
        $this->assertDatabaseCount('locations', 0);
    }

    public function test_zip_download_cleans_part_archive_and_extraction_directory(): void
    {
        $directory = $this->makeIsolatedTempDirectory();
        $zipPath = $directory.'/AT.zip';
        $target = $directory.'/AT.txt';
        $contents = "1010\tWien\tWien\tWien";

        try {
            Http::fake([
                '*' => Http::response($this->makeZipContents('AT.txt', $contents)),
            ]);

            $command = $this->testable_import_locations();

            $this->assertTrue($command->downloadZipEntryForTest(
                'https://download.geonames.org/export/zip/AT.zip',
                $zipPath,
                'AT.txt',
                $target
            ));
            $this->assertSame($contents, File::get($target));
            $this->assertFileDoesNotExist($zipPath);
            $this->assertFileDoesNotExist($zipPath.'.part');
            $this->assertSame([], glob($directory.'/extract_*') ?: []);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_failed_zip_extraction_cleans_artifacts_and_keeps_cached_entry(): void
    {
        $directory = $this->makeIsolatedTempDirectory();
        $zipPath = $directory.'/AT.zip';
        $target = $directory.'/AT.txt';
        $url = 'https://download.geonames.org/export/zip/AT.zip';

        try {
            File::put($target, 'cached-entry');
            Http::fake([
                '*' => Http::response($this->makeZipContents('AT.txt', 'downloaded-entry')),
            ]);
            Log::spy();

            $command = $this->testable_import_locations();

            $this->assertFalse($command->downloadZipEntryForTest(
                $url,
                $zipPath,
                'missing.txt',
                $target
            ));
            $this->assertSame('cached-entry', File::get($target));
            $this->assertFileDoesNotExist($zipPath);
            $this->assertFileDoesNotExist($zipPath.'.part');
            $this->assertSame([], glob($directory.'/extract_*') ?: []);
            Log::shouldHaveReceived('warning')
                ->once()
                ->withArgs(fn (string $message, array $context): bool => $message === 'Location import download failed'
                    && $context['url'] === $url
                    && $context['reason'] === 'zip_entry_missing_or_empty');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_short_download_write_is_rejected_without_replacing_cached_file(): void
    {
        $directory = $this->makeIsolatedTempDirectory();
        $destination = $directory.'/countryInfo.txt';
        $url = 'https://download.geonames.org/export/dump/countryInfo.txt';
        $body = 'complete-download-body';
        $shortWriteBytes = strlen($body) - 4;

        try {
            File::put($destination, 'cached-country-data');
            Http::fake([
                '*' => Http::response($body),
            ]);
            Log::spy();

            $command = $this->testable_import_locations();
            $command->shortWriteBytes = $shortWriteBytes;

            $this->assertFalse($command->downloadFileForTest($url, $destination));
            $this->assertSame('cached-country-data', File::get($destination));
            $this->assertFileDoesNotExist($destination.'.part');
            Log::shouldHaveReceived('warning')
                ->once()
                ->withArgs(fn (string $message, array $context): bool => $message === 'Location import download failed'
                    && $context['url'] === $url
                    && $context['reason'] === 'short_write'
                    && $context['expected_bytes'] === strlen($body)
                    && $context['actual_bytes'] === $shortWriteBytes
                    && ! array_key_exists('body', $context));
        } finally {
            File::deleteDirectory($directory);
        }
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

    private function makeIsolatedTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/import-locations-'.bin2hex(random_bytes(8));
        if (! File::makeDirectory($directory, 0755, true)) {
            throw new \RuntimeException('Could not create isolated location-import test directory.');
        }

        return $directory;
    }

    private function makeZipContents(string $entry, string $contents): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'import-locations-zip-');
        if ($zipPath === false) {
            throw new \RuntimeException('Could not create location-import ZIP fixture.');
        }

        $zip = new ZipArchive;
        $zipClosed = false;

        try {
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true
                || ! $zip->addFromString($entry, $contents)) {
                throw new \RuntimeException('Could not build location-import ZIP fixture.');
            }
            $zip->close();
            $zipClosed = true;

            $bytes = file_get_contents($zipPath);
            if ($bytes === false) {
                throw new \RuntimeException('Could not read location-import ZIP fixture.');
            }

            return $bytes;
        } finally {
            if (! $zipClosed) {
                $zip->close();
            }
            File::delete($zipPath);
        }
    }

    private function testable_import_locations(): ImportLocations
    {
        return new class extends ImportLocations
        {
            public ?int $shortWriteBytes = null;

            public function downloadFileForTest(string $url, string $destination, int $timeout = 60): bool
            {
                return $this->downloadFile($url, $destination, $timeout);
            }

            public function downloadZipEntryForTest(
                string $url,
                string $zipPath,
                string $entry,
                string $target
            ): bool {
                return $this->downloadZipEntry($url, $zipPath, $entry, $target);
            }

            protected function writeDownloadBody(string $path, string $contents): int|false
            {
                if ($this->shortWriteBytes === null) {
                    return parent::writeDownloadBody($path, $contents);
                }

                $written = file_put_contents(
                    $path,
                    substr($contents, 0, $this->shortWriteBytes),
                    LOCK_EX
                );

                return $written === false ? false : $this->shortWriteBytes;
            }
        };
    }
}
