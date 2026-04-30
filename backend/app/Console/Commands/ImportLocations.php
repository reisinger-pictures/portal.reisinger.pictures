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

        $zipPostalPath = $tempDir . '/AT_postal.zip';
        $txtPostalPath = $tempDir . '/AT_postal.txt';
        $zipPlacesPath = $tempDir . '/AT_places.zip';
        $txtPlacesPath = $tempDir . '/AT_places.txt';

        // 1. Lade GeoNames Orte-Datensatz (für Einwohner)
        $this->info('Lade GeoNames Orte-Datensatz (AT) für Einwohnerzahlen...');
        $response = Http::get('http://download.geonames.org/export/dump/AT.zip');
        $populations = [];
        if ($response->successful()) {
            file_put_contents($zipPlacesPath, $response->body());
            $zip = new \ZipArchive;
            if ($zip->open($zipPlacesPath) === true) {
                $zip->extractTo($tempDir, 'AT.txt');
                rename($tempDir . '/AT.txt', $txtPlacesPath);
                $zip->close();
            }
            
            if (file_exists($txtPlacesPath) && ($handle = fopen($txtPlacesPath, "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 0, "\t")) !== FALSE) {
                    if (count($data) >= 15) {
                        $name = trim($data[1]);
                        $pop = (int) $data[14];
                        if ($pop > 0) {
                            if (!isset($populations[$name]) || $pop > $populations[$name]) {
                                $populations[$name] = $pop;
                            }
                        }
                    }
                }
                fclose($handle);
            }
        }

        // 2. Lade PLZ Daten
        $this->info('Lade GeoNames PLZ-Datensatz (AT) herunter...');
        $response = Http::get('http://download.geonames.org/export/zip/AT.zip');
        if ($response->successful()) {
            file_put_contents($zipPostalPath, $response->body());
            $zip = new \ZipArchive;
            if ($zip->open($zipPostalPath) === true) {
                $zip->extractTo($tempDir, 'AT.txt');
                rename($tempDir . '/AT.txt', $txtPostalPath);
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

        if (file_exists($txtPostalPath) && ($handle = fopen($txtPostalPath, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 0, "\t")) !== FALSE) {
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
                        'population' => $populations[$cityName] ?? 0
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

        @unlink($zipPostalPath);
        @unlink($txtPostalPath);
        @unlink($zipPlacesPath);
        @unlink($txtPlacesPath);
        $this->info("{$count} österreichische PLZ-Gebiete erfolgreich importiert.");

        // 3. LÄNDER IMPORT
        $this->info('Lade weltweite Länder-Daten (GeoNames) herunter...');
        $countryResponse = Http::get('http://download.geonames.org/export/dump/countryInfo.txt');
        
        if ($countryResponse->successful()) {
            $countryLines = explode("\n", $countryResponse->body());
            $countryInserts = [];
            $fallback = ['AT' => 'Österreich', 'DE' => 'Deutschland', 'CH' => 'Schweiz', 'IT' => 'Italien', 'FR' => 'Frankreich', 'ES' => 'Spanien', 'US' => 'Vereinigte Staaten', 'GB' => 'Vereinigtes Königreich', 'NL' => 'Niederlande', 'BE' => 'Belgien'];
            
            foreach ($countryLines as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '#')) continue;
                
                $data = explode("\t", $line);
                if (count($data) >= 5) {
                    $iso = trim($data[0]);
                    $name = class_exists('Locale') ? \Locale::getDisplayRegion('und-' . $iso, 'de') : ($fallback[$iso] ?? $data[4]);
                    if (empty($name)) $name = $data[4];

                    $countryInserts[] = [
                        'id' => Str::uuid()->toString(),
                        'type' => 'country',
                        'name' => $name,
                        'postal_code' => null,
                        'state' => null,
                        'country' => null,
                        'iso_country' => $iso,
                        'population' => isset($data[7]) ? (int) $data[7] : 0
                    ];
                }
            }
            
            if (!empty($countryInserts)) {
                DB::table('locations')->insert($countryInserts);
                $this->info(count($countryInserts) . ' Länder erfolgreich importiert.');
            }
        }

        // 4. SYNCHRONISATION
        $this->info('Synchronisiere mit Meilisearch...');
        $this->call('scout:sync-index-settings');
        $this->call('scout:import', ['model' => Location::class]);

        $this->info('✅ Import abgeschlossen!');
        return 0;
    }
}
