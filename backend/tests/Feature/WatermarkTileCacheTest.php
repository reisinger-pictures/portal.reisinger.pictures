<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WatermarkTileCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_watermark_generates_and_caches_tile_master()
    {
        // Flow AD: Watermark Tile Cache Validation
        $sourcePath = storage_path('app/private/temp/wm_source_test.jpg');
        $destPath = storage_path('app/private/temp/wm_dest_test.jpg');
        
        if (!is_dir(dirname($sourcePath))) {
            mkdir(dirname($sourcePath), 0755, true);
        }
        copy(base_path('tests/Fixtures/sample.jpg'), $sourcePath);

        $processor = app(ImageProcessor::class);

        // Alte Caches vor dem Test entfernen
        $caches = glob(storage_path('app/private/watermark_master_tile_*.png'));
        if ($caches) {
            array_map('unlink', $caches);
        }

        // Generierung triggern (erstellt das gekachelte Wasserzeichen & das gecachte Master-PNG)
        $processor->generateTiledWatermark($sourcePath, $destPath, null, 'Test Watermark', 0.15);

        // Prüfen, ob eine gecachte Master-PNG-Datei generiert wurde
        $newCaches = glob(storage_path('app/private/watermark_master_tile_*.png'));
        $this->assertNotEmpty($newCaches, 'Tile Cache PNG wurde nicht im private Storage generiert!');

        // Aufräumen
        @unlink($sourcePath);
        @unlink($destPath);
        foreach ($newCaches as $c) @unlink($c);
    }

    public function test_updating_watermark_opacity_clears_old_caches() {
        $admin = \App\Models\User::factory()->create();
        $admin->roles()->attach(\App\Models\Role::firstOrCreate(['name' => 'admin']));
        $token = auth('api')->login($admin);

        $dummyCache = storage_path('app/private/watermark_master_dummy123.png');
        if (!is_dir(dirname($dummyCache))) mkdir(dirname($dummyCache), 0755, true);
        file_put_contents($dummyCache, 'dummy content');

        $this->assertTrue(file_exists($dummyCache));

        $response = $this->withHeaders(['Authorization' => "Bearer " . $token])
                         ->postJson("/api/management/settings/watermark", [
                             'text' => 'new.watermark',
                             'opacity' => 0.4
                         ]);

        $response->assertStatus(200);
        $this->assertFalse(file_exists($dummyCache));
    }

}
