<?php

namespace Tests\Feature;

use App\Services\ImageProcessor;
use App\Services\PhotoProcessingService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Regression coverage for the GD hardening of {@see ImageProcessor::generateThumbnail()}
 * and the residual-file logging of its cleanup helpers.
 *
 * A false `imagecreatetruecolor()` used to be passed to `imagecopyresampled()`,
 * which throws an uncaught TypeError (a 500 with an internal type error), and a
 * partial `imagewebp()` output was left behind for FileDeliveryController to
 * choke on.
 */
class ImageProcessorHardeningTest extends TestCase
{
    public function test_supported_raster_formats_remain_valid(): void
    {
        if (! function_exists('imagecreatetruecolor')
            || ! function_exists('imagejpeg')
            || ! function_exists('imagepng')) {
            $this->markTestSkipped('GD JPEG/PNG encoders are required for raster-format coverage.');
        }

        $directory = $this->makeTempDirectory();
        $image = imagecreatetruecolor(8, 8);
        $color = imagecolorallocate($image, 20, 40, 60);
        imagefill($image, 0, 0, $color);

        try {
            $paths = [
                $directory.'/sample.jpg' => 'image/jpeg',
                $directory.'/sample.png' => 'image/png',
            ];
            imagejpeg($image, $directory.'/sample.jpg', 80);
            imagepng($image, $directory.'/sample.png');

            if (function_exists('imagewebp')) {
                $paths[$directory.'/sample.webp'] = 'image/webp';
                imagewebp($image, $directory.'/sample.webp');
            }

            $processor = new ImageProcessor;
            foreach ($paths as $path => $expectedMime) {
                $this->assertSame($expectedMime, $processor->detectMimeType($path));
                $this->assertTrue($processor->isValidImageFile($path));
            }
        } finally {
            imagedestroy($image);
            $this->deleteTempDirectory($directory);
        }
    }

    public function test_generate_thumbnail_fails_closed_when_gd_cannot_allocate_the_resized_image(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            $this->markTestSkipped('GD extension not available');
        }

        $directory = $this->makeTempDirectory();
        $source = $directory.'/source.jpg';
        $dest = $directory.'/dest.webp';
        $marker = $dest.ImageProcessor::WATERMARK_MARKER_SUFFIX;

        try {
            $this->writeJpeg($source, 200, 100);
            // A failed generation must not leave a stale image or a marker that
            // points at it.
            file_put_contents($dest, 'stale-partial-output');
            file_put_contents($marker, '{"version":1}');

            $processor = $this->processorWithFailedTrueColor();

            $this->assertFalse($processor->generateThumbnail($source, $dest, 100));

            $this->assertFileExists($source);
            $this->assertFileDoesNotExist($dest);
            $this->assertFileDoesNotExist($marker);
        } finally {
            $this->deleteTempDirectory($directory);
        }
    }

    public function test_residual_failed_output_and_marker_are_logged_when_unlink_fails(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            $this->markTestSkipped('GD extension not available');
        }

        $directory = $this->makeTempDirectory();
        $source = $directory.'/source.jpg';
        $dest = $directory.'/dest.webp';
        $marker = $dest.ImageProcessor::WATERMARK_MARKER_SUFFIX;

        try {
            $this->writeJpeg($source, 200, 100);
            file_put_contents($dest, 'residual-output');
            file_put_contents($marker, 'residual-marker');
            Log::spy();

            $processor = $this->processorWithFailedTrueColor(unlinkFails: true);

            $this->assertFalse($processor->generateThumbnail($source, $dest, 100));

            // The `photos` disk uses throw => false, so the residual paths must
            // be surfaced instead of silently assumed gone.
            Log::shouldHaveReceived('warning')
                ->with('image_processor.failed_output_unlink_failed', Mockery::on(
                    static fn (array $context): bool => $context['path'] === $dest
                ))
                ->once();
            Log::shouldHaveReceived('warning')
                ->with('image_processor.watermark_marker_unlink_failed', Mockery::on(
                    static fn (array $context): bool => $context['path'] === $marker
                ))
                ->once();

            $this->assertFileExists($dest);
            $this->assertFileExists($marker);
        } finally {
            $this->deleteTempDirectory($directory);
        }
    }

    public function test_oversized_side_dimension_is_rejected_before_gd_decode(): void
    {
        $directory = $this->makeTempDirectory();
        $source = $directory.'/oversized.png';
        $dest = $directory.'/thumbnail.webp';

        try {
            $this->writePngHeader($source, PhotoProcessingService::MAX_IMAGE_DIMENSION + 1, 1);
            $processor = $this->processorWithDecodeSpy();

            $this->assertFalse($processor->generateThumbnail($source, $dest, 800));
            $this->assertSame(0, $processor->decodeCalls);
        } finally {
            $this->deleteTempDirectory($directory);
        }
    }

    public function test_oversized_total_pixels_are_rejected_before_gd_decode(): void
    {
        $directory = $this->makeTempDirectory();
        $source = $directory.'/oversized.png';
        $dest = $directory.'/thumbnail.webp';

        try {
            $this->writePngHeader($source, 10_000, 4_001);
            $processor = $this->processorWithDecodeSpy();

            $this->assertFalse($processor->generateThumbnail($source, $dest, 800));
            $this->assertSame(0, $processor->decodeCalls);
        } finally {
            $this->deleteTempDirectory($directory);
        }
    }

    public function test_oversized_source_bytes_are_rejected_before_gd_decode(): void
    {
        $directory = $this->makeTempDirectory();
        $source = $directory.'/oversized.png';
        $dest = $directory.'/thumbnail.webp';
        $handle = fopen($source, 'wb');
        $this->assertIsResource($handle);

        try {
            fwrite($handle, $this->pngHeader(1, 1));
            $this->assertTrue(ftruncate($handle, PhotoProcessingService::MAX_IMAGE_BYTES + 1));
            fclose($handle);
            $processor = $this->processorWithDecodeSpy();

            $this->assertFalse($processor->generateThumbnail($source, $dest, 800));
            $this->assertSame(0, $processor->decodeCalls);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->deleteTempDirectory($directory);
        }
    }

    private function processorWithFailedTrueColor(bool $unlinkFails = false): ImageProcessor
    {
        return new class($unlinkFails) extends ImageProcessor
        {
            public function __construct(private readonly bool $unlinkFails) {}

            protected function createTrueColorImage(int $width, int $height): \GdImage|false
            {
                return false;
            }

            protected function unlinkPath(string $path): bool
            {
                if ($this->unlinkFails && (is_file($path) || is_link($path))) {
                    return false;
                }

                return parent::unlinkPath($path);
            }
        };
    }

    private function processorWithDecodeSpy(): ImageProcessor
    {
        return new class extends ImageProcessor
        {
            public int $decodeCalls = 0;

            protected function decodeImage(string $path, string $mime): \GdImage|false
            {
                $this->decodeCalls++;

                return false;
            }
        };
    }

    private function writePngHeader(string $path, int $width, int $height): void
    {
        file_put_contents($path, $this->pngHeader($width, $height));
    }

    private function pngHeader(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n"
            .pack('N', 13)
            .'IHDR'
            .pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)
            .pack('N', 0);
    }

    private function writeJpeg(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 10, 20, 30);
        imagefill($image, 0, 0, $color);
        imagejpeg($image, $path, 80);
        imagedestroy($image);
    }

    private function makeTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/image-processor-hardening-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create temporary directory [{$directory}].");
        }

        return $directory;
    }

    private function deleteTempDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($directory);
    }
}
