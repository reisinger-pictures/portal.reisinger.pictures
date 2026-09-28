<?php

namespace Tests\Feature;

use App\Services\ImageProcessor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Regression coverage for two findings in {@see ImageProcessor}:
 *
 * - AIS-4: a zero-byte or corrupt source must fail closed without throwing and
 *   without leaving a partial derivative behind for FileDeliveryController.
 * - AIS-7: a cleanup unlink that leaves the file on disk must be logged at
 *   warning level instead of being silently swallowed by a bare `@unlink`.
 */
class ImageProcessorUnlinkLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            $this->markTestSkipped('GD extension is not available.');
        }
    }

    // -------------------------------------------------------------------------
    // AIS-4 — no leftover output and no throw on an unreadable source
    // -------------------------------------------------------------------------

    public function test_generate_thumbnail_rejects_a_zero_byte_source_without_leftover_output(): void
    {
        $directory = $this->makeTempDirectory();
        $source = $directory.'/source.jpg';
        $dest = $directory.'/dest.webp';

        try {
            file_put_contents($source, '');
            // A stale, partially written derivative must not survive the failure.
            file_put_contents($dest, 'stale-partial-output');

            $this->assertFalse(app(ImageProcessor::class)->generateThumbnail($source, $dest, 100));

            $this->assertFileDoesNotExist($dest);
        } finally {
            $this->deleteDirectory($directory);
        }
    }

    public function test_generate_thumbnail_rejects_a_corrupt_source_without_leftover_output(): void
    {
        $directory = $this->makeTempDirectory();
        $source = $directory.'/source.jpg';
        $dest = $directory.'/dest.webp';

        try {
            file_put_contents($source, 'this is definitely not an image');
            file_put_contents($dest, 'stale-partial-output');

            $this->assertFalse(app(ImageProcessor::class)->generateThumbnail($source, $dest, 100));

            $this->assertFileDoesNotExist($dest);
        } finally {
            $this->deleteDirectory($directory);
        }
    }

    // -------------------------------------------------------------------------
    // AIS-7 — a residual temp file is observable instead of silently ignored
    // -------------------------------------------------------------------------

    public function test_residual_watermark_temp_file_is_logged_when_unlink_fails(): void
    {
        $root = $this->useTemporaryStorageDisk('photos');
        $outputDirectory = $root.'/work';
        mkdir($outputDirectory, 0700, true);

        $source = $outputDirectory.'/source.jpg';
        $dest = $outputDirectory.'/dest.png';
        $marker = $dest.ImageProcessor::WATERMARK_MARKER_SUFFIX;

        $this->writeJpeg($source, 200, 100);
        $this->writeWatermarkAsset($root.'/_watermarks/master_500.png');
        // A directory in place of the rename target makes @rename() fail, so
        // the cleanup branch (and only that branch) is exercised.
        mkdir($marker, 0700, true);

        Log::spy();

        $processor = $this->processorWithFailingUnlink();

        $this->assertFalse($processor->applyCenteredWatermark($source, $dest, null, 'delivery'));

        Log::shouldHaveReceived('warning')
            ->with('image_processor.watermark_temp_unlink_failed', Mockery::on(
                static fn (array $context): bool => str_starts_with(
                    $context['path'],
                    $outputDirectory.DIRECTORY_SEPARATOR.'.watermark-'
                )
            ))
            ->once();

        // The warning reflects a real residual file, not a speculative log.
        $leftovers = array_values(array_filter(
            scandir($outputDirectory) ?: [],
            static fn (string $entry): bool => str_starts_with($entry, '.watermark-')
        ));
        $this->assertNotEmpty($leftovers, 'The temp file must survive so the warning reflects reality.');
    }

    public function test_residual_marker_file_is_logged_once_per_cleanup_when_unlink_fails(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Requires the BSD immutable flag (macOS) to make unlink() fail on a real file.');
        }

        $root = $this->useTemporaryStorageDisk('photos');
        $outputDirectory = $root.'/work';
        mkdir($outputDirectory, 0700, true);

        $source = $outputDirectory.'/source.jpg';
        $dest = $outputDirectory.'/dest.png';
        $marker = $dest.ImageProcessor::WATERMARK_MARKER_SUFFIX;

        $this->writeJpeg($source, 200, 100);
        $this->writeWatermarkAsset($root.'/_watermarks/master_500.png');

        // A real marker file carrying the BSD user-immutable flag. Unlike a
        // directory, unlinkPath() sees it as a file, so the marker-unlink path
        // is genuinely exercised: rename() onto it fails (EPERM), which drives
        // the cleanup branch, and unlink() on it fails too.
        file_put_contents($marker, 'residual-marker');
        $this->setImmutable($marker);

        try {
            Log::spy();

            $this->assertFalse(app(ImageProcessor::class)->applyCenteredWatermark($source, $dest, null, 'delivery'));

            // Two cleanup sites legitimately touch the marker for one failed
            // generation: the pre-generation removal at the top of
            // applyCenteredWatermark() and the removeFailedOutput() run on the
            // writeWatermarkMarker() false return. A redundant call inside
            // writeWatermarkMarker() would make this a third warning for the
            // same path, which this assertion forbids.
            Log::shouldHaveReceived('warning')
                ->with('image_processor.watermark_marker_unlink_failed', Mockery::on(
                    static fn (array $context): bool => ($context['path'] ?? null) === $marker
                ))
                ->twice();
        } finally {
            $this->clearImmutable($marker);
        }
    }

    private function setImmutable(string $path): void
    {
        $process = new Process(['chflags', 'uchg', $path]);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped('Unable to set the immutable flag on ['.$path.']: '.trim($process->getErrorOutput()));
        }
    }

    private function clearImmutable(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            (new Process(['chflags', 'nouchg', $path]))->run();
        }
    }

    private function processorWithFailingUnlink(): ImageProcessor
    {
        return new class extends ImageProcessor
        {
            protected function unlinkPath(string $path): bool
            {
                if (is_file($path) || is_link($path)) {
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

    private function writeWatermarkAsset(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $image = imagecreatetruecolor(32, 32);
        $color = imagecolorallocatealpha($image, 255, 255, 255, 40);
        imagefill($image, 0, 0, $color);
        imagepng($image, $path);
        imagedestroy($image);
    }

    private function makeTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/image-processor-unlink-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create temporary directory [{$directory}].");
        }

        return $directory;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        (new Filesystem)->deleteDirectory($directory);
    }
}
