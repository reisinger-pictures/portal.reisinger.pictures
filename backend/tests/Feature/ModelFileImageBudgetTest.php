<?php

namespace Tests\Feature;

use App\Services\ModelFileStore;
use App\Services\PhotoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    private function assertImageRejectedBeforeDecode(string $contents): void
    {
        $file = UploadedFile::fake()->createWithContent('oversized.png', $contents);
        $this->assertRejectedBeforeDecode($this->storeWithDecodeSpy(), $file);
    }

    private function assertRejectedBeforeDecode(ModelFileStore $store, UploadedFile $file): void
    {
        try {
            $store->storeUpload('model-photos/test', $file);
            $this->fail('Expected the image to be rejected by its pre-decode budget.');
        } catch (ValidationException $exception) {
            // Rejected before the decoder and before encrypted persistence.
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertSame(0, $store->decodeCalls);
        $this->assertSame([], Storage::disk('local')->allFiles());
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
}
