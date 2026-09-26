<?php

namespace Tests\Feature;

use App\Services\ImageProcessor;
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
