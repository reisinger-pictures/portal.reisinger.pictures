<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ImportLocations extends Command
{
    protected $signature = 'app:import-locations';

    protected $description = 'Lädt GeoNames Daten (AT PLZ & Länder) herunter und pusht sie nach Meilisearch';

    private int $cleanupFailureCount = 0;

    public function handle(): int
    {
        $lock = Cache::lock('portal:import-locations', 3600);
        if (! $lock->get()) {
            $this->info('Location-Import läuft bereits; überspringe diesen Lauf.');

            return self::SUCCESS;
        }

        try {
            return $this->importLocations();
        } catch (Throwable $exception) {
            Log::error('Location import failed unexpectedly', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->error('Location-Import ist wegen eines unerwarteten Fehlers abgebrochen.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function importLocations(): int
    {
        $this->cleanupFailureCount = 0;
        $this->info('Starte Import der Location-Daten für Smart Assistance...');

        $tempDir = storage_path('app/private/temp');
        if (! $this->ensureDirectory($tempDir, 'temp_directory')) {
            $this->error('Temporäres Importverzeichnis konnte nicht vorbereitet werden.');

            return self::FAILURE;
        }

        $zipPostalPath = $tempDir.'/AT_postal.zip';
        $txtPostalPath = $tempDir.'/AT_postal.txt';
        $zipPlacesPath = $tempDir.'/AT_places.zip';
        $txtPlacesPath = $tempDir.'/AT_places.txt';
        $countryTxtPath = $tempDir.'/countryInfo.txt';

        // 1. Lade GeoNames Orte-Datensatz (für Einwohner)
        $this->info('Lade GeoNames Orte-Datensatz (AT) für Einwohnerzahlen...');
        if (! $this->downloadZipEntry(
            'https://download.geonames.org/export/dump/AT.zip',
            $zipPlacesPath,
            'AT.txt',
            $txtPlacesPath
        )) {
            $this->warn('Download fehlgeschlagen. Greife auf lokalen Cache zurück: '.basename($txtPlacesPath));
        }

        $populations = $this->parsePopulations($txtPlacesPath);

        // 2. Lade PLZ Daten
        $this->info('Lade GeoNames PLZ-Datensatz (AT) herunter...');
        if (! $this->downloadZipEntry(
            'https://download.geonames.org/export/zip/AT.zip',
            $zipPostalPath,
            'AT.txt',
            $txtPostalPath
        )) {
            $this->warn('Download fehlgeschlagen. Greife auf lokalen Cache zurück: '.basename($txtPostalPath));
        }

        $cities = $this->parsePostalCodes($txtPostalPath, $populations);

        // 3. Länder-Datensatz
        $this->info('Lade weltweite Länder-Daten (GeoNames) herunter...');
        if (! $this->downloadFile(
            'https://download.geonames.org/export/dump/countryInfo.txt',
            $countryTxtPath,
            15
        )) {
            $this->warn('Länder-Download fehlgeschlagen. Greife auf lokalen Cache zurück.');
        }

        $countries = $this->parseCountries($countryTxtPath);

        if ($this->cleanupFailureCount > 0) {
            Log::error('Location import aborted: temporary-file cleanup failures', [
                'failure_count' => $this->cleanupFailureCount,
            ]);
            $this->error(
                'Location-Import abgebrochen: Temporäre Dateien konnten nicht vollständig bereinigt werden. '
                .'Bestehende Location-Daten bleiben unverändert.'
            );

            return self::FAILURE;
        }

        // Nothing parsed at all: do NOT wipe existing data. A non-zero exit
        // makes the scheduler/monitoring retry the run without treating the
        // existing location table as disposable.
        if ($cities === [] && $countries === []) {
            Log::error('Location import aborted: no source data available');
            $this->error('Keine Quelldaten verfügbar (Download fehlgeschlagen und kein Cache). Bestehende Location-Daten bleiben unverändert.');

            return self::FAILURE;
        }

        // Refresh only the datasets that were actually parsed, inside a single
        // transaction so a partial import cannot leave the table half-empty.
        // `delete()` (not `truncate()`) keeps the refresh transactional on all
        // supported database drivers.
        DB::transaction(function () use ($cities, $countries) {
            if ($cities !== []) {
                DB::table('locations')->where('type', 'city')->delete();
                foreach (array_chunk($cities, 1000) as $chunk) {
                    DB::table('locations')->insert($chunk);
                }
            }

            if ($countries !== []) {
                DB::table('locations')->where('type', 'country')->delete();
                foreach (array_chunk($countries, 1000) as $chunk) {
                    DB::table('locations')->insert($chunk);
                }
            }
        });

        if ($cities !== []) {
            $this->info(count($cities).' österreichische PLZ-Gebiete erfolgreich importiert.');
        } else {
            $this->error('Kein Cache für PLZ gefunden. Überspringe Städte-Import.');
        }

        if ($countries !== []) {
            $this->info(count($countries).' Länder erfolgreich importiert.');
        } else {
            $this->error('Kein Cache für Länder gefunden.');
        }

        // 4. SYNCHRONISATION
        $this->info('Synchronisiere mit Meilisearch...');
        $this->call('scout:flush', ['model' => Location::class]);
        $this->call('scout:sync-index-settings');
        $this->call('scout:import', ['model' => Location::class]);

        $this->info('✅ Import abgeschlossen!');

        return self::SUCCESS;
    }

    /**
     * Download a file over HTTPS and validate it before replacing the cached
     * copy. Writes atomically (`.part` + rename) so a truncated download never
     * clobbers a previously good cache file.
     */
    protected function downloadFile(string $url, string $destination, int $timeout = 60): bool
    {
        $tempFile = $destination.'.part';

        try {
            // A previous process may have died after creating the staging file.
            // Never append to or reuse that unknown byte sequence.
            if (! $this->removeFileIfExists($tempFile, 'download_part')) {
                return false;
            }

            try {
                $response = Http::timeout($timeout)->get($url);
            } catch (Throwable $exception) {
                $this->logDownloadFailure($url, 'request_failed', [
                    'exception' => $exception::class,
                ]);

                return false;
            }

            if (! $response->successful()) {
                $this->logDownloadFailure($url, 'http_error', [
                    'http_status' => $response->status(),
                ]);

                return false;
            }

            $body = (string) $response->body();
            if ($body === '') {
                $this->logDownloadFailure($url, 'empty_body');

                return false;
            }

            try {
                $writtenBytes = $this->writeDownloadBody($tempFile, $body);
            } catch (Throwable $exception) {
                $this->logDownloadFailure($url, 'write_failed', [
                    'exception' => $exception::class,
                ]);

                return false;
            }

            $expectedBytes = strlen($body);
            if ($writtenBytes !== $expectedBytes) {
                $this->logDownloadFailure($url, 'short_write', [
                    'expected_bytes' => $expectedBytes,
                    'actual_bytes' => $writtenBytes,
                ]);

                return false;
            }

            try {
                $renamed = rename($tempFile, $destination);
            } catch (Throwable $exception) {
                $this->logDownloadFailure($url, 'rename_failed', [
                    'exception' => $exception::class,
                ]);

                return false;
            }

            if (! $renamed) {
                $this->logDownloadFailure($url, 'rename_failed');

                return false;
            }

            return true;
        } finally {
            // This also removes a stale/partial file when the HTTP response
            // was rejected before a new write was attempted.
            $this->removeFileIfExists($tempFile, 'download_part');
        }
    }

    /**
     * Override seam for deterministic short-write regression tests.
     */
    protected function writeDownloadBody(string $path, string $contents): int|false
    {
        return file_put_contents($path, $contents, LOCK_EX);
    }

    /**
     * Download a zip archive and atomically extract a single entry to $target.
     * Returns false when the download, the archive validation, extraction or
     * temporary-artifact cleanup fails, leaving any existing cache untouched.
     */
    protected function downloadZipEntry(string $url, string $zipPath, string $entry, string $target): bool
    {
        if ($entry === '' || basename($entry) !== $entry) {
            $this->logDownloadFailure($url, 'invalid_zip_entry');

            return false;
        }

        if (! $this->downloadFile($url, $zipPath)) {
            $this->removeFileIfExists($zipPath, 'zip_archive');

            return false;
        }

        $zip = null;
        $zipIsOpen = false;
        $extractDir = null;
        $stagedFile = null;

        try {
            $zip = new \ZipArchive;

            try {
                $openResult = $zip->open($zipPath);
            } catch (Throwable $exception) {
                $this->logDownloadFailure($url, 'zip_open_failed', [
                    'exception' => $exception::class,
                ]);

                return false;
            }

            if ($openResult !== true) {
                $this->logDownloadFailure($url, 'invalid_zip_archive');

                return false;
            }
            $zipIsOpen = true;

            try {
                $token = bin2hex(random_bytes(6));
            } catch (Throwable $exception) {
                $this->logDownloadFailure($url, 'temporary_name_generation_failed', [
                    'exception' => $exception::class,
                ]);

                return false;
            }

            $extractDirCandidate = dirname($target).'/extract_'.$token;
            if ($this->pathExists($extractDirCandidate)) {
                $this->logDownloadFailure($url, 'temporary_path_collision', [
                    'path' => $extractDirCandidate,
                ]);

                return false;
            }

            $extractDir = $extractDirCandidate;
            if (! $this->ensureDirectory($extractDir, 'zip_extraction_directory')) {
                return false;
            }

            try {
                $extracted = $zip->extractTo($extractDir, $entry);
            } catch (Throwable $exception) {
                $this->logDownloadFailure($url, 'zip_extraction_failed', [
                    'exception' => $exception::class,
                ]);

                return false;
            }

            $extractedPath = $extractDir.'/'.$entry;
            $extractedSize = is_file($extractedPath) ? filesize($extractedPath) : false;
            if (! $extracted || $extractedSize === false || $extractedSize === 0) {
                $this->logDownloadFailure($url, 'zip_entry_missing_or_empty');

                return false;
            }

            $stagedCandidate = dirname($target).'/.'.basename($target).'.extract_'.$token;
            if ($this->pathExists($stagedCandidate)) {
                $this->logDownloadFailure($url, 'temporary_path_collision', [
                    'path' => $stagedCandidate,
                ]);

                return false;
            }

            $stagedFile = $stagedCandidate;
            try {
                $staged = rename($extractedPath, $stagedFile);
            } catch (Throwable $exception) {
                $this->logDownloadFailure($url, 'zip_staging_failed', [
                    'exception' => $exception::class,
                ]);

                return false;
            }

            if (! $staged) {
                $this->logDownloadFailure($url, 'zip_staging_failed');

                return false;
            }

            if (! $this->closeZipArchive($zip, $url)) {
                return false;
            }
            $zipIsOpen = false;

            // Commit the extracted cache only after every archive/extraction
            // artifact has been removed successfully.
            if (! $this->removeFileIfExists($zipPath, 'zip_archive')) {
                return false;
            }

            if (! $this->removeDirectoryIfExists($extractDir, 'zip_extraction_directory')) {
                return false;
            }
            $extractDir = null;

            try {
                $committed = rename($stagedFile, $target);
            } catch (Throwable $exception) {
                $this->logDownloadFailure($url, 'zip_commit_failed', [
                    'exception' => $exception::class,
                ]);

                return false;
            }

            if (! $committed) {
                $this->logDownloadFailure($url, 'zip_commit_failed');

                return false;
            }

            $stagedFile = null;

            return true;
        } catch (Throwable $exception) {
            $this->logDownloadFailure($url, 'zip_processing_failed', [
                'exception' => $exception::class,
            ]);

            return false;
        } finally {
            if ($zip instanceof \ZipArchive && $zipIsOpen) {
                $this->closeZipArchive($zip, $url);
            }

            if ($stagedFile !== null) {
                $this->removeFileIfExists($stagedFile, 'zip_staged_entry');
            }
            if ($extractDir !== null) {
                $this->removeDirectoryIfExists($extractDir, 'zip_extraction_directory');
            }

            $this->removeFileIfExists($zipPath, 'zip_archive');
        }
    }

    private function closeZipArchive(\ZipArchive $zip, string $url): bool
    {
        try {
            $closed = $zip->close();
        } catch (Throwable $exception) {
            $this->logDownloadFailure($url, 'zip_close_failed', [
                'exception' => $exception::class,
            ]);

            return false;
        }

        if (! $closed) {
            $this->logDownloadFailure($url, 'zip_close_failed');
        }

        return $closed;
    }

    private function ensureDirectory(string $path, string $artifact): bool
    {
        if (is_dir($path)) {
            return true;
        }

        try {
            $created = mkdir($path, 0755, true);
        } catch (Throwable $exception) {
            Log::error('Location import temporary-file setup failed', [
                'artifact' => $artifact,
                'path' => $path,
                'reason' => 'mkdir_threw',
                'exception' => $exception::class,
            ]);

            return false;
        }

        if (! $created && ! is_dir($path)) {
            Log::error('Location import temporary-file setup failed', [
                'artifact' => $artifact,
                'path' => $path,
                'reason' => 'mkdir_returned_false',
            ]);

            return false;
        }

        return true;
    }

    private function removeFileIfExists(string $path, string $artifact): bool
    {
        if (! $this->pathExists($path)) {
            return true;
        }

        try {
            File::delete($path);
        } catch (Throwable $exception) {
            $this->recordCleanupFailure($artifact, $path, 'delete_threw', $exception);

            return false;
        }

        if ($this->pathExists($path)) {
            $this->recordCleanupFailure($artifact, $path, 'path_still_exists');

            return false;
        }

        return true;
    }

    private function removeDirectoryIfExists(string $path, string $artifact): bool
    {
        if (is_link($path)) {
            return $this->removeFileIfExists($path, $artifact);
        }

        if (! is_dir($path)) {
            if ($this->pathExists($path)) {
                $this->recordCleanupFailure($artifact, $path, 'unexpected_artifact_type');
            }

            return ! $this->pathExists($path);
        }

        try {
            File::deleteDirectory($path);
        } catch (Throwable $exception) {
            $this->recordCleanupFailure($artifact, $path, 'recursive_delete_threw', $exception);

            return false;
        }

        if (is_dir($path)) {
            $this->recordCleanupFailure($artifact, $path, 'path_still_exists');

            return false;
        }

        return true;
    }

    private function recordCleanupFailure(
        string $artifact,
        string $path,
        string $reason,
        ?Throwable $exception = null
    ): void {
        $this->cleanupFailureCount++;

        $context = [
            'artifact' => $artifact,
            'path' => $path,
            'reason' => $reason,
        ];
        if ($exception !== null) {
            $context['exception'] = $exception::class;
        }

        Log::error('Location import temporary-file cleanup failed', $context);
    }

    private function pathExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private function logDownloadFailure(string $url, string $reason, array $context = []): void
    {
        Log::warning('Location import download failed', array_merge([
            'url' => $url,
            'reason' => $reason,
        ], $context));
    }

    /**
     * @return array<string, int>
     */
    private function parsePopulations(string $path): array
    {
        if (! file_exists($path) || filesize($path) === 0 || ($handle = fopen($path, 'r')) === false) {
            return [];
        }

        $populations = [];
        while (($data = fgetcsv($handle, 0, "\t")) !== false) {
            if (count($data) >= 15) {
                $name = trim($data[1]);
                $pop = (int) $data[14];
                if ($pop > 0 && (! isset($populations[$name]) || $pop > $populations[$name])) {
                    $populations[$name] = $pop;
                }
            }
        }
        fclose($handle);

        return $populations;
    }

    /**
     * @param  array<string, int>  $populations
     * @return array<int, array<string, mixed>>
     */
    private function parsePostalCodes(string $path, array $populations): array
    {
        if (! file_exists($path) || filesize($path) === 0 || ($handle = fopen($path, 'r')) === false) {
            return [];
        }

        $locations = [];
        while (($data = fgetcsv($handle, 0, "\t")) !== false) {
            if (count($data) >= 4) {
                $cityName = trim($data[2]);
                $locations[] = [
                    'id' => Str::uuid()->toString(),
                    'type' => 'city',
                    'name' => $cityName,
                    'postal_code' => $data[1],
                    'state' => $data[3] ?: null,
                    'country' => 'Österreich',
                    'iso_country' => 'AT',
                    'population' => $populations[$cityName] ?? 0,
                ];
            }
        }
        fclose($handle);

        return $locations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCountries(string $path): array
    {
        if (! file_exists($path) || filesize($path) === 0) {
            return [];
        }

        $countries = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $data = explode("\t", $line);
            if (count($data) >= 5) {
                $iso = trim($data[0]);
                $name = \Locale::getDisplayRegion('und-'.$iso, 'de');
                if (empty($name)) {
                    $name = $data[4];
                }

                $countries[] = [
                    'id' => Str::uuid()->toString(),
                    'type' => 'country',
                    'name' => $name,
                    'postal_code' => null,
                    'state' => null,
                    'country' => null,
                    'iso_country' => $iso,
                    'population' => isset($data[7]) ? (int) $data[7] : 0,
                ];
            }
        }

        return $countries;
    }
}
