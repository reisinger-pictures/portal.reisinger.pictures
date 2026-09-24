<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportLocations extends Command
{
    protected $signature = 'app:import-locations';

    protected $description = 'Lädt GeoNames Daten (AT PLZ & Länder) herunter und pusht sie nach Meilisearch';

    public function handle(): int
    {
        $lock = Cache::lock('portal:import-locations', 3600);
        if (! $lock->get()) {
            $this->info('Location-Import läuft bereits; überspringe diesen Lauf.');

            return self::SUCCESS;
        }

        try {
            return $this->importLocations();
        } finally {
            $lock->release();
        }
    }

    private function importLocations(): int
    {
        $this->info('Starte Import der Location-Daten für Smart Assistance...');

        $tempDir = storage_path('app/private/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
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

        @unlink($zipPostalPath);
        @unlink($zipPlacesPath);

        // Nothing parsed at all: do NOT wipe existing data, just report and stop.
        if ($cities === [] && $countries === []) {
            $this->error('Keine Quelldaten verfügbar (Download fehlgeschlagen und kein Cache). Bestehende Location-Daten bleiben unverändert.');

            return 0;
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

        return 0;
    }

    /**
     * Download a file over HTTPS and validate it before replacing the cached
     * copy. Writes atomically (`.part` + rename) so a truncated download never
     * clobbers a previously good cache file.
     */
    private function downloadFile(string $url, string $destination, int $timeout = 60): bool
    {
        try {
            $response = Http::timeout($timeout)->get($url);
        } catch (\Throwable $e) {
            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $body = (string) $response->body();
        if ($body === '') {
            return false;
        }

        $tempFile = $destination.'.part';
        if (file_put_contents($tempFile, $body) === false) {
            @unlink($tempFile);

            return false;
        }

        if (! rename($tempFile, $destination)) {
            @unlink($tempFile);

            return false;
        }

        return true;
    }

    /**
     * Download a zip archive and atomically extract a single entry to $target.
     * Returns false when the download, the archive validation or the extraction
     * fails, leaving any existing cache file untouched.
     */
    private function downloadZipEntry(string $url, string $zipPath, string $entry, string $target): bool
    {
        if (! $this->downloadFile($url, $zipPath)) {
            return false;
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->warn("Ungültiges ZIP-Archiv: {$url}");

            return false;
        }

        $extractDir = dirname($target).'/extract_'.bin2hex(random_bytes(6));
        if (! is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        try {
            if (! $zip->extractTo($extractDir, $entry)) {
                return false;
            }

            $extracted = $extractDir.'/'.$entry;
            if (! is_file($extracted) || filesize($extracted) === 0) {
                return false;
            }

            return rename($extracted, $target);
        } finally {
            $zip->close();

            foreach (glob($extractDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($extractDir);
        }
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
