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
    protected $description = 'Lädt GeoNames Daten (AT) herunter, bereitet sie auf und pusht sie nach Meilisearch';

    public function handle()
    {
        $this->info('Starte Import der Location-Daten für Smart Assistance...');

        $tempDir = storage_path('app/private/temp');
        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

        $zipPath = $tempDir . '/AT.zip';
        $txtPath = $tempDir . '/AT.txt';

        $this->info('Lade GeoNames Datensatz (AT) herunter...');
        $response = Http::get('http://download.geonames.org/export/dump/AT.zip');
        if (!$response->successful()) {
            $this->error('Download fehlgeschlagen!');
            return 1;
        }
        file_put_contents($zipPath, $response->body());

        $this->info('Entpacke Daten...');
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) === true) {
            $zip->extractTo($tempDir, 'AT.txt');
            $zip->close();
        } else {
            $this->error('Konnte ZIP nicht entpacken!');
            return 1;
        }

        $this->info('Leere bestehende Location-Tabelle...');
        DB::table('locations')->truncate();

        $this->info('Parse und speichere Städte...');
        $locations = [];
        $count = 0;
        
        $statesMapping = [
            '01' => 'Burgenland', '02' => 'Kärnten', '03' => 'Niederösterreich',
            '04' => 'Oberösterreich', '05' => 'Salzburg', '06' => 'Steiermark',
            '07' => 'Tirol', '08' => 'Vorarlberg', '09' => 'Wien'
        ];

        if (($handle = fopen($txtPath, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 0, "\t")) !== FALSE) {
                if (isset($data[6]) && $data[6] === 'P') {
                    $stateCode = $data[10] ?? '';
                    $stateName = $statesMapping[$stateCode] ?? null;

                    $locations[] = [
                        'id' => Str::uuid()->toString(),
                        'type' => 'city',
                        'name' => $data[1],
                        'state' => $stateName,
                        'country' => 'Österreich',
                        'iso_country' => 'AT',
                        'population' => isset($data[14]) ? (int) $data[14] : 0
                    ];
                    $count++;

                    if (count($locations) >= 1000) {
                        DB::table('locations')->insert($locations);
                        $locations = [];
                    }
                }
            }
            fclose($handle);
            if (!empty($locations)) {
                DB::table('locations')->insert($locations);
            }
        }

        @unlink($zipPath);
        @unlink($txtPath);

        $this->info("{$count} österreichische Städte erfolgreich importiert.");

        
        $this->info('Lade weltweite Länder-Daten (GeoNames) herunter...');
        DB::table('locations')->where('type', 'country')->delete(); // Altlasten löschen

        $countryResponse = Http::get('http://download.geonames.org/export/dump/countryInfo.txt');
        if (!$countryResponse->successful()) {
            $this->error('Länder-Download fehlgeschlagen!');
            return 1;
        }

        $countryLines = explode("\n", $countryResponse->body());
        $countryInserts = [];
        
        foreach ($countryLines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) continue;
            
            $data = explode("\t", $line);
            if (count($data) >= 5) {
                // $data[0] = ISO Alpha-2 (e.g. AT)
                // $data[4] = Country Name (e.g. Austria)
                $countryInserts[] = [
                    'id' => Str::uuid()->toString(),
                    'type' => 'country',
                    'name' => $data[4],
                    'state' => null,
                    'country' => null,
                    'iso_country' => $data[0],
                    'population' => isset($data[7]) ? (int) $data[7] : 0
                ];
            }
        }
        
        if (!empty($countryInserts)) {
            DB::table('locations')->insert($countryInserts);
        }
        $this->info(count($countryInserts) . ' Länder erfolgreich importiert.');

        $this->info('Synchronisiere mit Meilisearch...');
        $this->call('scout:sync-index-settings');
        $this->call('scout:import', ['model' => Location::class]);

        $this->info('✅ Import abgeschlossen!');
        return 0;
    }
}
