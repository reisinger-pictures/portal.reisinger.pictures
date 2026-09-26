<?php

namespace Tests\Feature;

use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class PhotoDownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wall-clock budget for the exiftool read-back that verifies a download.
     *
     * A healthy `exiftool` invocation needs roughly 0.1 s and measured process
     * lifetimes stay far below one second even at high host concurrency, so
     * Symfony's 60 s default is not a safety limit for the binary: it is a
     * limit on how long the *test process* may be starved by the host. On an
     * oversubscribed runner that budget expires while exiftool has long
     * finished, which turned a slow-but-successful verification into a hard
     * failure. The bound below only keeps a genuinely stuck process from
     * hanging the suite; the production timeout is deliberately untouched.
     */
    private const EXIFTOOL_VERIFICATION_TIMEOUT = 300.0;

    /**
     * One retry absorbs a single starved poll. exiftool only reads the
     * delivered file here, so a retry is side-effect free and cannot mask a
     * metadata mismatch: the assertions run on the output of whichever attempt
     * completed.
     */
    private const EXIFTOOL_VERIFICATION_ATTEMPTS = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryStorageDisk('photos');
    }

    public function test_authorized_user_can_download_single_image_and_metadata_is_injected()
    {
        $user = User::factory()->create(['name' => 'Max Mustermann', 'flatrate_level' => 'original', 'brand' => 'rp']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);

        $photographer = User::factory()->create(['name' => 'Test Fotograf']);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        $this->assertTrue(file_exists($fixturePath), 'Fixture sample.jpg fehlt!');

        $content = file_get_contents($fixturePath);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $content);

        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/photos/'.$photo->id.'/download');

        $response->assertStatus(200);
        $response->assertDownload();

        $this->assertDatabaseHas('download_logs', [
            'user_id' => $user->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'original',
        ]);

        $downloadedFilePath = $response->getFile()->getPathname();

        $imageSize = @getimagesize($downloadedFilePath);
        $this->assertNotFalse($imageSize, 'Die heruntergeladene Datei ist kein valides Bild.');

        $metaData = $this->readExifMetadata($downloadedFilePath, 'single download with injected metadata');

        $this->assertArrayHasKey('SpecialInstructions', $metaData, 'SpecialInstructions fehlen in den Metadaten.');
        $this->assertStringContainsString('Max Mustermann', $metaData['SpecialInstructions']);

        $this->assertTrue(
            isset($metaData['Creator']) || isset($metaData['By-line']) || isset($metaData['Artist']),
            'Urheber (Creator/By-line/Artist) fehlt in den Metadaten.'
        );
    }

    public function test_editorial_only_flag_is_injected_into_metadata()
    {
        $user = User::factory()->create(['name' => 'Editorial Tester', 'flatrate_level' => 'original', 'brand' => 'rp']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false, 'is_editorial_only' => true]);
        $user->galleries()->attach($gallery);

        $photographer = User::factory()->create(['name' => 'Test Fotograf']);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'user_id' => $photographer->id]);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        $content = file_get_contents($fixturePath);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $content);

        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/photos/'.$photo->id.'/download');

        $response->assertStatus(200);

        $downloadedFilePath = $response->getFile()->getPathname();

        $metaData = $this->readExifMetadata($downloadedFilePath, 'editorial-only single download');

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
        Storage::disk('photos')->put($gallery->id.'/'.$p1->filename, $content);
        Storage::disk('photos')->put($gallery->id.'/'.$p2->filename, $content);

        Storage::disk('photos')->makeDirectory($gallery->id.'/_watermarked');
        Storage::disk('photos')->put($gallery->id.'/_watermarked/'.$p1->filename, $content);
        Storage::disk('photos')->put($gallery->id.'/_watermarked/'.$p2->filename, $content);

        $response = $this->get('/api/galleries/'.$gallery->id.'/download-zip');
        $response->assertStatus(200);

        $tempZipPath = storage_path('app/private/temp/test_dl_'.uniqid().'.zip');
        if (! is_dir(dirname($tempZipPath))) {
            mkdir(dirname($tempZipPath), 0755, true);
        }

        ob_start();
        $response->sendContent();
        $zipContent = ob_get_clean();
        file_put_contents($tempZipPath, $zipContent);

        $zip = new ZipArchive;
        $res = $zip->open($tempZipPath);
        $this->assertTrue($res === true, 'Das generierte ZIP-Archiv ist korrupt.');

        $this->assertNotFalse($zip->locateName($p1->id.'_ORIGINAL.jpg'), 'pic1_ORIGINAL.jpg fehlt im ZIP');
        $this->assertNotFalse($zip->locateName($p2->id.'_ORIGINAL.jpg'), 'pic2_ORIGINAL.jpg fehlt im ZIP');
        $zip->close();

        unlink($tempZipPath);

        $this->assertDatabaseHas('download_logs', [
            'user_id' => null,
            'user_name_snapshot' => 'Gast',
            'item_type' => 'full_zip',
            'gallery_id' => $gallery->id,
            'photo_count' => 2,
        ]);
    }

    public function test_empty_public_gallery_zip_returns_422_without_a_download_log(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => true,
            'is_free_download' => true,
            'slug' => 'empty-zip',
        ]);

        $this->get("/api/galleries/{$gallery->id}/download-zip")
            ->assertStatus(422)
            ->assertJson(['error' => 'Der ZIP-Download enthält keine Bilder.']);

        $this->assertDatabaseCount('download_logs', 0);
    }

    public function test_user_cannot_download_private_photo_from_unauthorized_gallery()
    {
        $user1 = User::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $token1 = auth('api')->login($user1);

        $this->withHeaders(['Authorization' => "Bearer $token1"])
            ->get("/api/photos/{$photo->id}/download")
            ->assertStatus(403);

        $this->withHeaders(['Authorization' => "Bearer $token1"])
            ->get("/api/galleries/{$gallery->id}/download-zip")
            ->assertStatus(403);
    }

    public function test_disputed_or_refunded_order_blocks_download()
    {
        $user = User::factory()->create(['flatrate_level' => 'none', 'brand' => 'rp']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, file_get_contents($fixturePath));

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'disputed',
            'brand' => 'rp',
            'total_amount' => 3500,
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'RE-DISPUTE',
            'brand' => 'rp',
            'customer_details' => [
                'name' => 'Test Kunde',
                'items' => [
                    ['photoId' => $photo->id, 'tier' => 'original', 'price' => 3500],
                ],
            ],
            'total_net' => 3500,
            'total_gross' => 3500,
            'tax_rate' => 0,
        ]);

        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->get("/api/photos/{$photo->id}/download?tier=original")
            ->assertStatus(403);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->get("/api/orders/{$order->id}/download-zip")
            ->assertStatus(403);

        $order->update(['status' => 'paid']);

        // Positional throttle middleware uses the authenticated user ID as its
        // key, so the requests above and below share one counter. This test is
        // about the disputed-to-paid transition, not rate limiting.
        RateLimiter::clear(sha1((string) $user->getAuthIdentifier()));

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->get("/api/photos/{$photo->id}/download?tier=original")
            ->assertStatus(200);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->get("/api/orders/{$order->id}/download-zip")
            ->assertStatus(200);
    }

    public function test_paid_order_zip_log_links_order_and_gallery(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => true,
        ]);
        $photos = Photo::factory()->count(2)->create(['gallery_id' => $gallery->id]);

        // The prepared archive is derived from real files on the photos disk, so
        // the fixture has to write the source images just like the production
        // import does. Without them the ZIP branch prepares nothing and returns
        // the documented 422 instead of a download log.
        $content = (string) file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        foreach ($photos as $photo) {
            Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $content);
        }

        $order = Order::factory()->paid()->create([
            'user_id' => $user->id,
            'brand' => 'rp',
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-DOWNLOAD-LOG',
            'brand' => 'rp',
            'customer_details' => [
                'items' => $photos->map(fn (Photo $photo): array => [
                    'photoId' => $photo->id,
                    'tier' => 'original',
                    'price' => 1750,
                ])->all(),
            ],
            'total_net' => 3500,
            'total_gross' => 3500,
            'tax_rate' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->get("/api/orders/{$order->id}/download-zip")
            ->assertStatus(200);

        $log = DownloadLog::sole();
        $this->assertSame($order->id, $log->order_id);
        $this->assertSame($gallery->id, $log->gallery_id);
        // photo_count is derived from the two prepared files, not from the
        // number of persisted Photo rows and not from the legacy default of 1.
        $this->assertSame(2, $log->photo_count);
    }

    public function test_paid_order_zip_without_source_file_returns_422_without_a_download_log(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => true,
        ]);
        // Orderable and visible, but the source file never reached the photos
        // disk. Preparation therefore yields zero files and the archive branch
        // must fail closed instead of logging a zero-count ZIP.
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $order = Order::factory()->paid()->create([
            'user_id' => $user->id,
            'brand' => 'rp',
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-EMPTY-ZIP',
            'brand' => 'rp',
            'customer_details' => [
                'items' => [
                    ['photoId' => $photo->id, 'tier' => 'original', 'price' => 3500],
                ],
            ],
            'total_net' => 3500,
            'total_gross' => 3500,
            'tax_rate' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->getJson("/api/orders/{$order->id}/download-zip")
            ->assertStatus(422)
            ->assertJson(['message' => 'Der ZIP-Download enthält keine Bilder.']);

        $this->assertDatabaseCount('download_logs', 0);
    }

    public function test_paid_order_zip_counts_only_prepared_files_when_one_source_is_missing(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => true,
        ]);
        $present = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $missing = Photo::factory()->create(['gallery_id' => $gallery->id]);

        // Only the first ordered item has a source file. The derivation must
        // report one prepared file, not the two ordered items.
        Storage::disk('photos')->put(
            $gallery->id.'/'.$present->filename,
            (string) file_get_contents(base_path('tests/Fixtures/sample.jpg')),
        );

        $order = Order::factory()->paid()->create([
            'user_id' => $user->id,
            'brand' => 'rp',
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-PARTIAL-ZIP-COUNT',
            'brand' => 'rp',
            'customer_details' => [
                'items' => [
                    ['photoId' => $present->id, 'tier' => 'original', 'price' => 1750],
                    ['photoId' => $missing->id, 'tier' => 'original', 'price' => 1750],
                ],
            ],
            'total_net' => 3500,
            'total_gross' => 3500,
            'tax_rate' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->get("/api/orders/{$order->id}/download-zip")
            ->assertStatus(200);

        $log = DownloadLog::sole();
        $this->assertSame(1, $log->photo_count);
        $this->assertSame(1, $log->payload['photo_count']);
        $this->assertSame([(string) $present->id], $log->payload['photo_ids']);
    }

    public function test_gallery_zip_fails_closed_when_watermark_bucket_is_missing(): void
    {
        $user = User::factory()->create(['flatrate_level' => 'web', 'brand' => 'rp']);
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => false,
            'is_free_download' => false,
            'brand' => 'rp',
        ]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            file_get_contents(base_path('tests/Fixtures/sample.jpg')),
        );
        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/galleries/'.$gallery->id.'/download-zip?tier=web')
            ->assertStatus(500)
            ->assertJson(['error' => 'SECURITY: Watermark-Fail.']);

        $this->assertDatabaseCount('download_logs', 0);
    }

    public function test_flatrate_user_can_view_original_without_watermark()
    {
        $user = User::factory()->create(['flatrate_level' => 'web', 'brand' => 'rp']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, file_get_contents($fixturePath));

        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg');

        $response->assertStatus(200);
    }

    /**
     * Read back the metadata the download pipeline actually wrote.
     *
     * The assertions stay on the real exiftool output; only the wall-clock
     * budget of this verification process is decoupled from the host load that
     * the production path inherits.
     *
     * @return array<string, mixed>
     */
    private function readExifMetadata(string $path, string $context): array
    {
        $this->assertFileExists($path, "Die heruntergeladene Datei [{$context}] fehlt auf der Platte.");

        $process = null;
        $failure = null;

        for ($attempt = 1; $attempt <= self::EXIFTOOL_VERIFICATION_ATTEMPTS; $attempt++) {
            $process = new Process(['exiftool', '-json', $path]);
            $process->setTimeout(self::EXIFTOOL_VERIFICATION_TIMEOUT);

            try {
                $process->run();
                $failure = null;

                break;
            } catch (ProcessTimedOutException $exception) {
                // A timeout already stopped the child, and this invocation only
                // reads the delivered file, so retrying cannot double-apply a
                // side effect.
                $failure = $exception;
            }
        }

        $this->assertNull(
            $failure,
            sprintf(
                'exiftool did not finish within %.1fs for [%s] after %d attempts on this host.',
                self::EXIFTOOL_VERIFICATION_TIMEOUT,
                $context,
                self::EXIFTOOL_VERIFICATION_ATTEMPTS,
            ),
        );

        $this->assertInstanceOf(Process::class, $process);
        $this->assertTrue(
            $process->isSuccessful(),
            "ExifTool konnte nicht ausgeführt werden [{$context}] (exit {$process->getExitCode()}): ".$process->getErrorOutput(),
        );

        $decoded = json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "ExifTool lieferte kein JSON [{$context}]: ".$process->getOutput());
        $this->assertArrayHasKey(0, $decoded, "ExifTool lieferte kein Metadaten-Objekt [{$context}].");

        return $decoded[0];
    }
}
