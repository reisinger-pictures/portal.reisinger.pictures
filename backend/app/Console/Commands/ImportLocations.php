<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class ImportLocations extends Command
{
    protected $signature = 'app:import-locations';
    protected $description = 'Lädt GeoNames Daten (AT PLZ & Länder) herunter und pusht sie nach Meilisearch';

    public function handle()
    {
        $this->info('Starte Import der Location-Daten für Smart Assistance...');

        $tempDir = storage_path('app/private/temp');
        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

        // 1. POSTLEITZAHLEN IMPORT
        $zipPath = $tempDir . '/AT_postal.zip';
        $txtPath = $tempDir . '/AT.txt';

        $this->info('Lade GeoNames PLZ-Datensatz (AT) herunter...');
        $response = Http::get('http://download.geonames.org/export/zip/AT.zip');
        if ($response->successful()) {
            file_put_contents($zipPath, $response->body());
            $zip = new \ZipArchive;
            if ($zip->open($zipPath) === true) {
                $zip->extractTo($tempDir, 'AT.txt');
                $zip->close();
            }
        }

        $this->info('Leere Meilisearch-Index (verhindert Ghost-Records)...');
        $this->call('scout:flush', ['model' => Location::class]);

        $this->info('Leere bestehende Location-Tabelle...');
        DB::table('locations')->truncate();

        $this->info('Parse und speichere Postleitzahlen und Städte...');
        $locations = [];
        $count = 0;

        if (file_exists($txtPath) && ($handle = fopen($txtPath, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 0, "\t")) !== FALSE) {
                if (count($data) >= 4) {
                    $locations[] = [
                        'id' => Str::uuid()->toString(),
                        'type' => 'city',
                        'name' => $data[2], // City Name
                        'postal_code' => $data[1], // PLZ
                        'state' => $data[3] ?: null, // State
                        'country' => 'Österreich',
                        'iso_country' => 'AT',
                        'population' => 0
                    ];
                    $count++;

                    if (count($locations) >= 1000) {
                        DB::table('locations')->insert($locations);
                        $locations = [];
                    }
                }
            }
            fclose($handle);
            if (!empty($locations)) DB::table('locations')->insert($locations);
        }

        @unlink($zipPath);
        @unlink($txtPath);
        $this->info("{$count} österreichische PLZ-Gebiete erfolgreich importiert.");

        // 2. LÄNDER IMPORT
        $this->info('Lade weltweite Länder-Daten (GeoNames) herunter...');
        $countryResponse = Http::get('http://download.geonames.org/export/dump/countryInfo.txt');
        
        if ($countryResponse->successful()) {
            $countryLines = explode("\n", $countryResponse->body());
            $countryInserts = [];
            
            foreach ($countryLines as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '#')) continue;
                
                $data = explode("\t", $line);
                if (count($data) >= 5) {
                    $iso = trim($data[0]);
                    // Übersetzt den ISO-Code via PHP intl in den deutschen Namen (z.B. DE -> Deutschland)
                    $name = class_exists('Locale') ? \Locale::getDisplayRegion('und-' . $iso, 'de') : $data[4];
                    if (empty($name)) $name = $data[4];

                    $countryInserts[] = [
                        'id' => Str::uuid()->toString(),
                        'type' => 'country',
                        'name' => $name,
                        'postal_code' => null,
                        'state' => null,
                        'country' => null,
                        'iso_country' => $data[0],
                        'population' => isset($data[7]) ? (int) $data[7] : 0
                    ];
                }
            }
            
            if (!empty($countryInserts)) {
                DB::table('locations')->insert($countryInserts);
                $this->info(count($countryInserts) . ' Länder erfolgreich in denselben Index importiert.');
            }
        }

        // 3. SYNCHRONISATION
        $this->info('Synchronisiere mit Meilisearch...');
        $this->call('scout:sync-index-settings');
        $this->call('scout:import', ['model' => Location::class]);

        $this->info('✅ Import abgeschlossen!');
        return 0;
    }
}
