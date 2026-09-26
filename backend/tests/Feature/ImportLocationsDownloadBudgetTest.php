<?php

namespace Tests\Feature;

use App\Console\Commands\ImportLocations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use ZipArchive;

/**
 * Regression: the importer materialised the whole HTTP body in memory with no
 * cap and only size-checked a zip entry after extracting it to disk. A changed
 * upstream (or a redirect hijack) could exhaust memory or the temp disk.
 */
class ImportLocationsDownloadBudgetTest extends TestCase
{
    public function test_oversized_download_is_rejected_without_replacing_the_cache(): void
    {
        $directory = $this->makeIsolatedTempDirectory();
        $destination = $directory.'/countryInfo.txt';
        $url = 'https://download.geonames.org/export/dump/countryInfo.txt';

        try {
            File::put($destination, 'cached-country-data');
            Http::fake(['*' => Http::response(str_repeat('A', 64))]);
            Log::spy();

            $command = $this->testable_import_locations();
            $command->maxDownload = 16;

            $this->assertFalse($command->downloadFileForTest($url, $destination));

            $this->assertSame('cached-country-data', File::get($destination));
            $this->assertFileDoesNotExist($destination.'.part');
            Log::shouldHaveReceived('warning')
                ->once()
                ->withArgs(fn (string $message, array $context): bool => $message === 'Location import download failed'
                    && $context['reason'] === 'too_large'
                    && $context['max_bytes'] === 16
                    && ! array_key_exists('body', $context));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_declared_oversized_content_length_is_rejected_before_reading(): void
    {
        $directory = $this->makeIsolatedTempDirectory();
        $destination = $directory.'/countryInfo.txt';
        $url = 'https://download.geonames.org/export/dump/countryInfo.txt';

        try {
            File::put($destination, 'cached-country-data');
            Http::fake(['*' => Http::response('small', 200, ['Content-Length' => '999999999'])]);
            Log::spy();

            $command = $this->testable_import_locations();
            $command->maxDownload = 1024;

            $this->assertFalse($command->downloadFileForTest($url, $destination));

            $this->assertSame('cached-country-data', File::get($destination));
            $this->assertFileDoesNotExist($destination.'.part');
            Log::shouldHaveReceived('warning')
                ->once()
                ->withArgs(fn (string $message, array $context): bool => $message === 'Location import download failed'
                    && $context['reason'] === 'too_large'
                    && $context['content_length'] === 999999999);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_oversized_zip_entry_is_rejected_before_the_target_is_replaced(): void
    {
        $directory = $this->makeIsolatedTempDirectory();
        $zipPath = $directory.'/AT.zip';
        $target = $directory.'/AT.txt';
        $url = 'https://download.geonames.org/export/zip/AT.zip';

        try {
            File::put($target, 'cached-entry');
            Http::fake(['*' => Http::response($this->makeZipContents('AT.txt', str_repeat('X', 32)))]);
            Log::spy();

            $command = $this->testable_import_locations();
            $command->maxEntry = 16;

            $this->assertFalse($command->downloadZipEntryForTest($url, $zipPath, 'AT.txt', $target));

            $this->assertSame('cached-entry', File::get($target));
            $this->assertFileDoesNotExist($zipPath);
            $this->assertFileDoesNotExist($zipPath.'.part');
            $this->assertSame([], glob($directory.'/extract_*') ?: []);
            Log::shouldHaveReceived('warning')
                ->once()
                ->withArgs(fn (string $message, array $context): bool => $message === 'Location import download failed'
                    && $context['reason'] === 'zip_entry_too_large'
                    && $context['entry_bytes'] === 32
                    && $context['max_bytes'] === 16);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function makeIsolatedTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/import-locations-budget-'.bin2hex(random_bytes(8));
        if (! File::makeDirectory($directory, 0700, true)) {
            throw new \RuntimeException('Could not create isolated location-import test directory.');
        }

        return $directory;
    }

    private function makeZipContents(string $entry, string $contents): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'import-locations-budget-zip-');
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
            public ?int $maxDownload = null;

            public ?int $maxEntry = null;

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

            protected function maxDownloadBytes(): int
            {
                return $this->maxDownload ?? parent::maxDownloadBytes();
            }

            protected function maxExtractedEntryBytes(): int
            {
                return $this->maxEntry ?? parent::maxExtractedEntryBytes();
            }
        };
    }
}
