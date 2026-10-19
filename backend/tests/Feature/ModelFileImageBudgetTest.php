<?php

namespace Tests\Feature;

use App\Services\AIService;
use App\Services\ModelFileStore;
use App\Services\PhotoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Tests\TestCase;

class ModelFileImageBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_oversized_side_dimension_is_rejected_before_gd_decode(): void
    {
        $this->assertImageRejectedBeforeDecode(
            $this->pngHeader(PhotoProcessingService::MAX_IMAGE_DIMENSION + 1, 1),
        );
    }

    public function test_oversized_total_pixels_are_rejected_before_gd_decode(): void
    {
        $this->assertImageRejectedBeforeDecode($this->pngHeader(10_000, 4_001));
    }

    public function test_pdf_upload_is_not_sent_through_a_raster_decoder(): void
    {
        $store = $this->storeWithDecodeSpy();
        $file = UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        $stored = $store->storeUpload('model-age-proofs/test', $file);

        $this->assertSame('application/pdf', $stored['mime']);
        $this->assertStringEndsWith('.pdf', $stored['path']);
        $this->assertSame(0, $store->decodeCalls);
        $this->assertTrue($store->isEncrypted($stored['path']));
    }

    public function test_upload_byte_limit_is_checked_before_reading_or_decoding(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'model-image-budget-');
        $this->assertIsString($path);
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        fwrite($handle, $this->pngHeader(1, 1));
        $this->assertTrue(ftruncate($handle, 10 * 1024 * 1024 + 1));
        fclose($handle);

        try {
            $baseUpload = new SymfonyUploadedFile($path, 'oversized.png', 'image/png', UPLOAD_ERR_OK, true);
            $upload = UploadedFile::createFromBase($baseUpload, true);
            $this->assertRejectedBeforeDecode($this->storeWithDecodeSpy(), $upload);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The stored-byte gate is the processable-image budget, not a second copy
     * of the (smaller) source gate. Pinned without a 20 MiB fixture: the test
     * subclass only exposes the value the production path uses.
     *
     * The literal 20 MiB is pinned as well: asserting only the identity to
     * `PhotoProcessingService::MAX_IMAGE_BYTES` stays green through a
     * coordinated change of both constants, while the docs still claim
     * "20 MiB" and silently rot.
     *
     * `AIService::MAX_IMAGE_BYTES` is deliberately uncoupled (see the note in
     * `ModelFileStore::maxStoredUploadBytes()`): the AI path reads the gallery
     * disk, not persisted model photos, so the shared value is today's
     * coincidence, not a contract. Pinned so a drift becomes visible instead of
     * silent.
     */
    public function test_stored_byte_budget_is_the_processable_image_budget(): void
    {
        $store = new class extends ModelFileStore
        {
            public function storedByteBudget(): int
            {
                return $this->maxStoredUploadBytes();
            }
        };

        $this->assertSame(20 * 1024 * 1024, $store->storedByteBudget());
        $this->assertSame(PhotoProcessingService::MAX_IMAGE_BYTES, $store->storedByteBudget());
        $this->assertSame(AIService::MAX_IMAGE_BYTES, $store->storedByteBudget());
    }

    public function test_re_encoded_bytes_above_the_stored_budget_are_rejected_before_persisting(): void
    {
        // The gate is `strlen($sanitized) > maxStoredUploadBytes()`; a small
        // injected budget exercises exactly that comparison through the real
        // decode → imagejpeg(..., 90) → strlen path, without a 20 MiB fixture.
        // Measured on PHP 8.5.11 / GD 2.3.3 with the fixture below: source
        // (q40) 14,336 bytes, re-encode (q90) 39,393 bytes — the 20 KiB budget
        // sits between them with margin in both directions. The sizes are
        // re-derivable from the same fixture code (see `noiseJpeg()`).
        $budget = 20 * 1024;
        $store = new class($budget) extends ModelFileStore
        {
            public function __construct(private readonly int $budget) {}

            protected function maxStoredUploadBytes(): int
            {
                return $this->budget;
            }
        };

        $source = $this->noiseJpeg(200, 200, 40);
        $encoded = $this->reencodeAsJpeg($this->decode($source), 90);

        // The fixture discriminates: the source is under the budget, only the
        // re-encode pushes it over. Asserted by measurement, not assumption.
        $this->assertLessThan($budget, strlen($source));
        $this->assertGreaterThan($budget, strlen($encoded));

        $file = UploadedFile::fake()->createWithContent('portrait.jpg', $source);
        $directory = $this->uniqueStoreDirectory();

        try {
            $store->storeUpload($directory, $file);
            $this->fail('Expected the re-encoded size to be rejected by the stored-byte budget.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        // Rejected before any encrypted or plaintext byte reached the disk.
        // Two scopes, both asserted:
        //  - this test's own directory is empty — the precise claim, the
        //    place the upload would have landed,
        //  - the whole fake disk is empty — the stronger claim that nothing
        //    was persisted at all.
        // The global scope is safe because `Storage::fake('local')` in
        // setUp() empties the fake root, so no foreign file can survive into
        // this test.
        $this->assertSame([], Storage::disk('local')->allFiles($directory));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_stored_size_is_the_encoded_size_and_within_the_shared_budget(): void
    {
        $store = new ModelFileStore;
        $source = $this->noiseJpeg(200, 200, 40);

        $stored = $store->storeUpload('model-photos/test', UploadedFile::fake()->createWithContent('portrait.jpg', $source));

        $this->assertSame('image/jpeg', $stored['mime']);
        $this->assertTrue($store->isEncrypted($stored['path']));
        // The reported size is the persisted post-re-encode size, so the value
        // a caller writes to `size_bytes` is the one the budget bounded.
        $this->assertSame(strlen($store->getDecrypted($stored['path'])), $stored['size']);
        // And the re-encode really does inflate the source (the reason a
        // source-only gate cannot be the stored invariant).
        $this->assertGreaterThan(strlen($source), $stored['size']);
        $this->assertLessThanOrEqual(PhotoProcessingService::MAX_IMAGE_BYTES, $stored['size']);
    }

    private function assertImageRejectedBeforeDecode(string $contents): void
    {
        $file = UploadedFile::fake()->createWithContent('oversized.png', $contents);
        $this->assertRejectedBeforeDecode($this->storeWithDecodeSpy(), $file);
    }

    private function assertRejectedBeforeDecode(ModelFileStore $store, UploadedFile $file): void
    {
        $directory = $this->uniqueStoreDirectory();

        try {
            $store->storeUpload($directory, $file);
            $this->fail('Expected the image to be rejected by its pre-decode budget.');
        } catch (ValidationException $exception) {
            // Rejected before the decoder and before encrypted persistence.
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertSame(0, $store->decodeCalls);
        // Rejected before the decoder and before encrypted persistence. Both
        // scopes are asserted: the test's own directory is empty (the precise
        // claim) and the whole fake disk is empty (the stronger claim). The
        // global scope is safe because `Storage::fake('local')` in setUp()
        // empties the fake root, so no foreign file can survive into this
        // test.
        $this->assertSame([], Storage::disk('local')->allFiles($directory));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * A store directory no other test writes to, so the "nothing was
     * persisted" assertion names the exact place the upload would have
     * landed. The global assertion next to it additionally proves that the
     * whole fake disk stayed clean.
     */
    private function uniqueStoreDirectory(): string
    {
        return 'model-photos/'.Str::random(24);
    }

    private function storeWithDecodeSpy(): ModelFileStore
    {
        return new class extends ModelFileStore
        {
            public int $decodeCalls = 0;

            protected function decodeImage(string $contents): \GdImage|false
            {
                $this->decodeCalls++;

                return false;
            }
        };
    }

    private function pngHeader(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n"
            .pack('N', 13)
            .'IHDR'
            .pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)
            .pack('N', 0);
    }

    /**
     * Deterministic high-frequency raster: poorly compressible at low quality,
     * so the quality-90 re-encode inside storeUpload() inflates it.
     * Measured on PHP 8.5.11 / GD 2.3.3 with this exact fixture:
     * 14,336 bytes (q40) → 39,393 bytes (q90), i.e. ~2.75x.
     * The factor belongs to this encoder/format pair and is no constant: the
     * PNG and WebP paths take a different re-encode (`imagepng()` /
     * `imagewebp()`) and inflate by their own factor.
     * Deterministic (no RNG) so the measured sizes in the tests are stable.
     */
    private function noiseJpeg(int $width, int $height, int $quality): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(\GdImage::class, $image);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($image, $x, $y, imagecolorallocate(
                    $image,
                    ($x * 37 + $y * 11) % 256,
                    ($x * 17 + $y * 53) % 256,
                    ($x * 97 + $y * 7) % 256,
                ));
            }
        }

        return $this->reencodeAsJpeg($image, $quality);
    }

    private function decode(string $bytes): \GdImage
    {
        $image = imagecreatefromstring($bytes);
        $this->assertInstanceOf(\GdImage::class, $image);

        return $image;
    }

    private function reencodeAsJpeg(\GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);
        $encoded = (string) ob_get_clean();
        imagedestroy($image);

        return $encoded;
    }
}
