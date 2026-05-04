<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use ZipArchive;

class DownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
    }

    public function test_authorized_user_can_download_single_image_and_metadata_is_injected()
    {
        $user = User::factory()->create(['name' => 'Max Mustermann', 'flatrate_level' => 'original']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        
        $photographer = User::factory()->create(['name' => 'Test Fotograf']);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        // Lege ein ECHTES Dummy-File (valid JPEG) in den fake Storage, damit ExifTool nicht crasht
        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        $this->assertTrue(file_exists($fixturePath), "Fixture sample.jpg fehlt!");
        
        $content = file_get_contents($fixturePath);
        Storage::disk('photos')->put($gallery->id . '/' . $photo->filename, $content);
        
        $token = auth('api')->login($user);
        
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('/api/photos/' . $photo->id . '/download');

        $response->assertStatus(200);
        $response->assertDownload();

        // 1. Audit Log prüfen
        $this->assertDatabaseHas('download_logs', [
            'user_id' => $user->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'original'
        ]);

        // 2. Metadaten des injizierten Bildes prüfen
        $downloadedFilePath = $response->getFile()->getPathname();
        
        // Prüfe ob es ein valides Bild ist
        $imageSize = @getimagesize($downloadedFilePath);
        $this->assertNotFalse($imageSize, 'Die heruntergeladene Datei ist kein valides Bild.');

        // Exiftool aufrufen, um die IPTC Daten zu lesen
        $process = new Process(['exiftool', '-json', $downloadedFilePath]);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'ExifTool konnte nicht ausgeführt werden.');
        
        $metaData = json_decode($process->getOutput(), true)[0];

        // Prüfen ob die Instruktionen injiziert wurden
        $this->assertArrayHasKey('SpecialInstructions', $metaData, 'SpecialInstructions fehlen in den Metadaten.');
        $this->assertStringContainsString('Max Mustermann', $metaData['SpecialInstructions']);
        
        // Prüfen ob Urheber erhalten blieb
        $this->assertTrue(
            isset($metaData['Creator']) || isset($metaData['By-line']) || isset($metaData['Artist']),
            'Urheber (Creator/By-line/Artist) fehlt in den Metadaten.'
        );
    }

    public function test_editorial_only_flag_is_injected_into_metadata()
    {
        $user = User::factory()->create(['name' => 'Editorial Tester', 'flatrate_level' => 'original']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false, 'is_editorial_only' => true]);
        $user->galleries()->attach($gallery);
        
        $photographer = User::factory()->create(['name' => 'Test Fotograf']);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        $content = file_get_contents($fixturePath);
        Storage::disk('photos')->put($gallery->id . '/' . $photo->filename, $content);
        
        $token = auth('api')->login($user);
        
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('/api/photos/' . $photo->id . '/download');

        $response->assertStatus(200);

        $downloadedFilePath = $response->getFile()->getPathname();
        
        $process = new Process(['exiftool', '-json', $downloadedFilePath]);
        $process->run();
        $metaData = json_decode($process->getOutput(), true)[0];

        $this->assertArrayHasKey('SpecialInstructions', $metaData);
        $this->assertStringContainsString('EDITORIAL USE ONLY', $metaData['SpecialInstructions']);
    }

    public function test_guest_can_download_public_gallery_zip_and_structure_is_valid()
    {
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => true, 'is_free_download' => true, 'slug' => 'test-zip']);
        $p1 = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $p2 = Photo::factory()->create(['gallery_id' => $gallery->id]);
        
        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        $content = file_get_contents($fixturePath);
        Storage::disk('photos')->put($gallery->id . '/' . $p1->filename, $content);
        Storage::disk('photos')->put($gallery->id . '/' . $p2->filename, $content);
        
        // Mock watermarked files to avoid dependency on ImageMagick during tests
        Storage::disk('photos')->makeDirectory($gallery->id . '/_watermarked');
        Storage::disk('photos')->put($gallery->id . '/_watermarked/' . $p1->filename, $content);
        Storage::disk('photos')->put($gallery->id . '/_watermarked/' . $p2->filename, $content);

        // Aufruf ohne Authentifizierung
        $response = $this->get('/api/galleries/' . $gallery->id . '/download-zip');
        $response->assertStatus(200);
        
        // StreamedResponse fangen und in lokale temporäre Datei schreiben
        $tempZipPath = storage_path('app/private/temp/test_dl_' . uniqid() . '.zip');
        if (!is_dir(dirname($tempZipPath))) mkdir(dirname($tempZipPath), 0755, true);
        
        ob_start();
        $response->sendContent();
        $zipContent = ob_get_clean();
        file_put_contents($tempZipPath, $zipContent);

        // ZIP Struktur validieren
        $zip = new ZipArchive();
        $res = $zip->open($tempZipPath);
        $this->assertTrue($res === true, 'Das generierte ZIP-Archiv ist korrupt.');
        
        // Prüfen, ob beide Dateien im Zip sind
        $this->assertNotFalse($zip->locateName($p1->id . '_ORIGINAL.jpg'), 'pic1_ORIGINAL.jpg fehlt im ZIP');
        $this->assertNotFalse($zip->locateName($p2->id . '_ORIGINAL.jpg'), 'pic2_ORIGINAL.jpg fehlt im ZIP');
        $zip->close();
        
        unlink($tempZipPath);

        $this->assertDatabaseHas('download_logs', [
            'user_id' => null,
            'user_name_snapshot' => 'Gast',
            'item_type' => 'full_zip',
            'gallery_id' => $gallery->id
        ]);
    }

    public function test_user_cannot_download_private_photo_from_unauthorized_gallery() {
        $user1 = User::factory()->create(); // no access
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $token1 = auth('api')->login($user1);

        // Single Image Download
        $this->withHeaders(['Authorization' => "Bearer $token1"])
             ->get("/api/photos/{$photo->id}/download")
             ->assertStatus(403);

        // ZIP Download
        $this->withHeaders(['Authorization' => "Bearer $token1"])
             ->get("/api/galleries/{$gallery->id}/download-zip")
             ->assertStatus(403);
    }

    public function test_disputed_or_refunded_order_blocks_download()
    {
        $user = User::factory()->create(['flatrate_level' => 'none']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        Storage::disk('photos')->put($gallery->id . '/' . $photo->filename, file_get_contents($fixturePath));

        // Erstelle eine Order im Status 'disputed'
        $order = \App\Models\Order::create([
            'user_id' => $user->id,
            'status' => 'disputed',
            'total_amount' => 3500
        ]);

        \App\Models\InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'RE-DISPUTE',
            'customer_details' => [
                'name' => 'Test Kunde',
                'items' => [
                    ['photoId' => $photo->id, 'tier' => 'original', 'price' => 3500]
                ]
            ],
            'total_net' => 3500,
            'total_gross' => 3500,
            'tax_rate' => 0
        ]);

        $token = auth('api')->login($user);

        // 1. Einzel-Download darf NICHT funktionieren (403)
        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->get("/api/photos/{$photo->id}/download?tier=original")
             ->assertStatus(403);

        // 2. Order-ZIP Download darf NICHT funktionieren (403)
        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->get("/api/orders/{$order->id}/download-zip")
             ->assertStatus(403);
             
        // 3. Setze Order auf 'paid' -> Alles sollte wieder klappen (200)
        $order->update(['status' => 'paid']);
        
        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->get("/api/photos/{$photo->id}/download?tier=original")
             ->assertStatus(200);
             
        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->get("/api/orders/{$order->id}/download-zip")
             ->assertStatus(200);
    }

    public function test_flatrate_user_can_view_original_without_watermark() {
        $user = User::factory()->create(['flatrate_level' => 'web']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        Storage::disk('photos')->put($gallery->id . '/' . $photo->filename, file_get_contents($fixturePath));

        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer " . $token])
             ->get('/api/media/' . $gallery->slug . '/' . $photo->id . '.jpg');

        $response->assertStatus(200);
    }

}
