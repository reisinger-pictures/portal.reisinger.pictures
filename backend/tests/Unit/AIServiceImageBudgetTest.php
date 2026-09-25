<?php

namespace Tests\Unit;

use App\Exceptions\AIImageProcessingException;
use App\Models\Photo;
use App\Services\AIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AIServiceImageBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);
    }

    public function test_oversized_bytes_are_rejected_without_provider_work(): void
    {
        Http::fake();
        $temporaryFiles = $this->temporaryImageFiles();
        $photo = $this->storePhoto(str_repeat('x', AIService::MAX_IMAGE_BYTES + 1));

        try {
            app(AIService::class)->generateMetadata($photo);
            $this->fail('Expected an oversized image to be rejected.');
        } catch (AIImageProcessingException $exception) {
            $this->assertSame(AIImageProcessingException::REASON_BYTES, $exception->reason);
            $this->assertSame(AIService::IMAGE_TOO_LARGE_ERROR, $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNoNewTemporaryFiles($temporaryFiles);
    }

    public function test_oversized_total_pixels_are_rejected_before_gd_decode(): void
    {
        Http::fake();
        $temporaryFiles = $this->temporaryImageFiles();
        $photo = $this->storePhoto($this->pngHeader(10_000, 10_000));

        // The fixture is intentionally only a header. If the service tried to
        // decode it before checking dimensions, this test would take the decode
        // failure path instead of the pixel-budget path.
        $this->assertNotFalse(@getimagesizefromstring($this->pngHeader(10_000, 10_000)));

        try {
            app(AIService::class)->generateMetadata($photo);
            $this->fail('Expected an oversized image to be rejected.');
        } catch (AIImageProcessingException $exception) {
            $this->assertSame(AIImageProcessingException::REASON_PIXELS, $exception->reason);
            $this->assertSame(AIService::IMAGE_TOO_LARGE_ERROR, $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNoNewTemporaryFiles($temporaryFiles);
    }

    public function test_oversized_side_dimension_is_rejected_before_gd_decode(): void
    {
        Http::fake();
        $temporaryFiles = $this->temporaryImageFiles();
        $photo = $this->storePhoto($this->pngHeader(AIService::MAX_IMAGE_DIMENSION + 1, 1));

        try {
            app(AIService::class)->generateMetadata($photo);
            $this->fail('Expected an oversized image to be rejected.');
        } catch (AIImageProcessingException $exception) {
            $this->assertSame(AIImageProcessingException::REASON_PIXELS, $exception->reason);
            $this->assertSame(AIService::IMAGE_TOO_LARGE_ERROR, $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNoNewTemporaryFiles($temporaryFiles);
    }

    public function test_decode_failure_cleans_temporary_file_and_does_not_call_provider(): void
    {
        Http::fake();
        $temporaryFiles = $this->temporaryImageFiles();
        $photo = $this->storePhoto($this->pngHeader(8, 8));
        $this->assertNotFalse(@getimagesizefromstring($this->pngHeader(8, 8)));

        try {
            app(AIService::class)->generateMetadata($photo);
            $this->fail('Expected an invalid image to be rejected.');
        } catch (AIImageProcessingException $exception) {
            $this->assertSame(AIImageProcessingException::REASON_DECODE, $exception->reason);
            $this->assertSame(AIService::IMAGE_INVALID_ERROR, $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNoNewTemporaryFiles($temporaryFiles);
    }

    public function test_valid_real_image_is_processed_and_provider_receives_one_request(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            $this->markTestSkipped('The pinned GD image is required for real-image coverage.');
        }

        $temporaryFiles = $this->temporaryImageFiles();
        $photo = $this->storePhoto($this->createJpeg());

        Http::fake([
            '*/chat/completions' => Http::response(json_encode([
                'choices' => [[
                    'message' => [
                        'content' => '{"title":"T","description":"D","keywords":"k","location":"L"}',
                    ],
                ]],
            ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']),
        ]);

        $result = app(AIService::class)->generateMetadata($photo);

        $this->assertSame('T', $result['title']);
        Http::assertSentCount(1);
        $this->assertNoNewTemporaryFiles($temporaryFiles);
    }

    public function test_image_budget_preflight_precedes_gd_decode_and_cleanup_is_in_finally(): void
    {
        $source = file_get_contents(app_path('Services/AIService.php'));
        $this->assertIsString($source);

        $loadStart = strpos($source, 'private function loadAndCompressImage');
        $loadEnd = strpos($source, 'private function copyStreamWithinBudget', $loadStart);
        $this->assertNotFalse($loadStart);
        $this->assertNotFalse($loadEnd);

        $loadSource = substr($source, $loadStart, $loadEnd - $loadStart);
        $budgetPosition = strpos($loadSource, 'getimagesizefromstring($contents)');
        $decodePosition = strpos($loadSource, 'imagecreatefromstring($contents)');
        $finallyPosition = strpos($loadSource, '} finally {');
        $unlinkPosition = strpos($loadSource, '@unlink($tmpPath)');
        $imageDataPosition = strpos($source, '$imageData = $this->loadAndCompressImage($photo);');
        $providerCallPosition = strpos($source, 'return $this->callAI($messages, $sessionId);');

        $this->assertNotFalse($budgetPosition);
        $this->assertNotFalse($decodePosition);
        $this->assertNotFalse($finallyPosition);
        $this->assertNotFalse($unlinkPosition);
        $this->assertNotFalse($imageDataPosition);
        $this->assertNotFalse($providerCallPosition);
        $this->assertTrue($budgetPosition < $decodePosition);
        $this->assertTrue($finallyPosition < $unlinkPosition);
        $this->assertTrue($imageDataPosition < $providerCallPosition);
        $this->assertStringContainsString('MAX_IMAGE_BYTES', $source);
        $this->assertStringContainsString('MAX_IMAGE_PIXELS', $source);
        $this->assertStringContainsString('MAX_IMAGE_DIMENSION', $source);
        $this->assertStringContainsString('MAX_IMAGE_BYTES + 1', $loadSource);
        $this->assertStringNotContainsString('stream_copy_to_stream', $loadSource);
    }

    private function storePhoto(string $contents): Photo
    {
        $this->useTemporaryStorageDisk('photos');

        $photo = new Photo;
        $photo->setAttribute('gallery_id', 'gallery-1');
        $photo->setAttribute('filename', 'sample.jpg');
        Storage::disk('photos')->put('gallery-1/sample.jpg', $contents);

        return $photo;
    }

    /**
     * @return array<int, string>
     */
    private function temporaryImageFiles(): array
    {
        $files = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai_img_*');

        return $files === false ? [] : $files;
    }

    /**
     * @param  array<int, string>  $before
     */
    private function assertNoNewTemporaryFiles(array $before): void
    {
        $after = $this->temporaryImageFiles();
        sort($before);
        sort($after);

        $this->assertSame([], array_values(array_diff($after, $before)));
    }

    private function pngHeader(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n"
            .pack('N', 13)
            .'IHDR'
            .pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)
            .pack('N', 0);
    }

    private function createJpeg(): string
    {
        $image = imagecreatetruecolor(8, 8);
        $background = imagecolorallocate($image, 20, 120, 200);
        imagefill($image, 0, 0, $background);

        ob_start();
        $encoded = imagejpeg($image, null, 90);
        $contents = ob_get_clean();
        imagedestroy($image);

        $this->assertTrue($encoded);
        $this->assertIsString($contents);
        $this->assertNotSame('', $contents);

        return $contents;
    }
}
