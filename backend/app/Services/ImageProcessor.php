<?php

namespace App\Services;

use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ImageProcessor
{
    private const WATERMARK_BUCKETS = [500, 1000, 2000];

    /**
     * A derivative without this provenance sidecar is treated as a stale
     * legacy cache entry and is rebuilt.  The marker binds the output to the
     * source bytes, watermark asset, gallery type, and output geometry.
     */
    private const WATERMARK_MARKER_VERSION = 1;

    public const WATERMARK_MARKER_SUFFIX = '.watermark.json';

    /**
     * Validate an image before it is used as a delivery or watermark source.
     */
    public function isValidImageFile(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $info = @getimagesize($path);

        return is_array($info) && in_array($info['mime'] ?? null, [
            'image/jpeg',
            'image/png',
            'image/webp',
        ], true);
    }

    /**
     * Return whether the exact watermark bucket required for this source can
     * be decoded.  A missing or corrupt bucket is never treated as a disabled
     * watermark.
     */
    public function watermarkAssetAvailable(string $sourcePath, string $galleryType = 'delivery', ?int $maxWidth = null): bool
    {
        if (! in_array($galleryType, ['delivery', 'selection'], true)) {
            return false;
        }

        $info = @getimagesize($sourcePath);
        if (! is_array($info)) {
            return false;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($maxWidth !== null && $maxWidth > 0 && $width > $maxWidth) {
            $height = max(1, (int) ($height * ($maxWidth / $width)));
            $width = $maxWidth;
        }
        $bucket = $this->watermarkBucket($width, $height);
        if ($bucket === null) {
            return false;
        }

        return $this->resolveWatermarkAsset($galleryType, $bucket) !== null;
    }

    /**
     * A cached derivative is safe to reuse only when its provenance marker
     * proves that this exact output was produced from the current source and
     * watermark asset.  A merely valid, re-encoded image is not proof that a
     * watermark was applied (the historical fail-open cache can look exactly
     * like that), so legacy/unmarked files always fail closed and are rebuilt
     * by the callers.
     */
    public function isSafeWatermarkedOutput(
        string $sourcePath,
        string $destPath,
        string $galleryType = 'delivery',
        ?int $maxWidth = null,
    ): bool {
        if (! in_array($galleryType, ['delivery', 'selection'], true)) {
            return false;
        }
        if (! $this->isValidImageFile($sourcePath) || ! $this->isValidImageFile($destPath)) {
            return false;
        }

        $sourceHash = @hash_file('sha256', $sourcePath);
        $destHash = @hash_file('sha256', $destPath);
        if (! is_string($sourceHash) || ! is_string($destHash) || hash_equals($sourceHash, $destHash)) {
            return false;
        }

        $markerPath = $this->watermarkMarkerPath($destPath);
        $markerContents = @file_get_contents($markerPath);
        if (! is_string($markerContents) || $markerContents === '') {
            return false;
        }

        try {
            $marker = json_decode($markerContents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (! is_array($marker)) {
            return false;
        }

        $markerMaxWidth = $marker['max_width'] ?? null;
        if ($markerMaxWidth !== null && (! is_int($markerMaxWidth) || $markerMaxWidth <= 0)) {
            return false;
        }
        $requestedMaxWidth = $maxWidth !== null && $maxWidth > 0 ? $maxWidth : null;
        // With the legacy two-argument API, use the marker's geometry when the
        // caller does not provide a max width.  Controllers pass the expected
        // width explicitly; direct callers still get a useful compatibility
        // path without accepting an unmarked file.
        $verificationMaxWidth = $requestedMaxWidth ?? $markerMaxWidth;
        $context = $this->watermarkContext($sourcePath, $galleryType, $verificationMaxWidth);
        if ($context === null) {
            return false;
        }

        $expected = [
            'version' => self::WATERMARK_MARKER_VERSION,
            'source_sha256' => $sourceHash,
            'output_sha256' => $destHash,
            'watermark_sha256' => $context['watermark_hash'],
            'gallery_type' => $galleryType,
            'bucket' => $context['bucket'],
            'max_width' => $requestedMaxWidth ?? $markerMaxWidth,
        ];

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $marker) || $marker[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    public function generateThumbnail($sourcePath, $destPath, $size, $quality = 80)
    {
        if (! $this->isValidImageFile($sourcePath)) {
            $this->removeFailedOutput($destPath, $sourcePath);

            return false;
        }

        $img = $this->loadGdImage($sourcePath);
        if (! $img) {
            $this->removeFailedOutput($destPath, $sourcePath);

            return false;
        }

        if (! $this->ensureOutputDirectory($destPath)) {
            imagedestroy($img);
            $this->removeFailedOutput($destPath, $sourcePath);

            return false;
        }

        $width = imagesx($img);
        $height = imagesy($img);

        if ($width > $size) {
            // Extreme landscape ratios would floor to 0; GD requires >= 1px.
            $newHeight = max(1, (int) ($height * ($size / $width)));
            $newImg = $this->createTrueColorImage($size, $newHeight);
            // imagecreatetruecolor() returns false under memory pressure. Feed
            // that value to imagecopyresampled() and the TypeError escapes as an
            // uncaught 500, so the failure is handled explicitly here.
            if (! $newImg
                || ! imagecopyresampled($newImg, $img, 0, 0, 0, 0, $size, $newHeight, $width, $height)) {
                if ($newImg) {
                    imagedestroy($newImg);
                }
                imagedestroy($img);
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }
            imagedestroy($img);
            $img = $newImg;
        }

        imagealphablending($img, false);
        imagesavealpha($img, true);
        $success = imagewebp($img, $destPath, $quality);
        imagedestroy($img);

        // A partially written .webp would later 500 the delivery controller.
        // Treat an invalid output like any other failed generation and remove it.
        if (! $success || ! $this->isValidImageFile($destPath)) {
            $this->removeFailedOutput($destPath, $sourcePath);

            return false;
        }

        return true;
    }

    public function scaleImage($sourcePath, $destPath, $maxWidth)
    {
        if (! $this->isValidImageFile($sourcePath)) {
            $this->removeFailedOutput($destPath, $sourcePath);

            return false;
        }

        if (! $maxWidth) {
            if (! $this->ensureOutputDirectory($destPath)) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }
            $success = @copy($sourcePath, $destPath);

            return $success && $this->isValidImageFile($destPath);
        }

        $img = $this->loadGdImage($sourcePath);
        if (! $img) {
            $this->removeFailedOutput($destPath, $sourcePath);

            return false;
        }

        if (! $this->ensureOutputDirectory($destPath)) {
            imagedestroy($img);
            $this->removeFailedOutput($destPath, $sourcePath);

            return false;
        }

        $width = imagesx($img);
        $height = imagesy($img);

        if ($width > $maxWidth) {
            $ratio = $maxWidth / $width;
            $newWidth = $maxWidth;
            $newHeight = max(1, (int) ($height * $ratio));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if (! $resized || ! imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
                if ($resized) {
                    imagedestroy($resized);
                }
                imagedestroy($img);
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }
            imagedestroy($img);
            $img = $resized;
        }

        $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
        $success = false;
        imagealphablending($img, false);
        imagesavealpha($img, true);

        if ($ext === 'webp') {
            $success = imagewebp($img, $destPath, 90);
        } elseif ($ext === 'png') {
            $success = imagepng($img, $destPath, 8);
        } else {
            $success = imagejpeg($img, $destPath, 90);
        }
        imagedestroy($img);

        if (! $success || ! $this->isValidImageFile($destPath)) {
            $this->removeFailedOutput($destPath, $sourcePath);

            return false;
        }

        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            $process = new Process(['exiftool', '-TagsFromFile', $sourcePath, '-All:All', '-Orientation=1', '-n', '-overwrite_original', $destPath]);
            $process->run();
        }

        return true;
    }

    public function applyCenteredWatermark($sourcePath, $destPath, $maxWidth = null, $galleryType = 'delivery')
    {
        // A failed/old generation must never leave a marker pointing at a new
        // or partially written image.
        $this->removeWatermarkMarker($destPath);

        $img = $this->loadGdImage($sourcePath);
        if (! $img) {
            // Never copy the source as a fallback: that would turn a failed
            // watermark operation into an unwatermarked delivery.
            $this->removeFailedOutput($destPath, $sourcePath);

            return false;
        }

        $wm = null;
        $wmResized = null;

        try {
            $width = imagesx($img);
            $height = imagesy($img);
            if ($width < 1 || $height < 1) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }

            if ($maxWidth !== null && $maxWidth > 0 && $width > $maxWidth) {
                $ratio = $maxWidth / $width;
                $newWidth = $maxWidth;
                $newHeight = max(1, (int) ($height * $ratio));
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                if (! $resized || ! imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
                    if ($resized) {
                        imagedestroy($resized);
                    }
                    $this->removeFailedOutput($destPath, $sourcePath);

                    return false;
                }
                imagedestroy($img);
                $img = $resized;
                $width = $newWidth;
                $height = $newHeight;
            }

            $bucket = $this->watermarkBucket($width, $height);
            if ($bucket === null) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }

            $wmPath = $this->resolveWatermarkAsset($galleryType, $bucket);
            if ($wmPath === null) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }

            $wm = @imagecreatefrompng($wmPath);
            if (! $wm) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }

            $wmWidth = imagesx($wm);
            $wmHeight = imagesy($wm);
            if ($wmWidth < 1 || $wmHeight < 1) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }

            $targetWmSize = max(1, (int) (min($width, $height) / 3));
            $wmRatio = $wmWidth / $wmHeight;
            if ($wmWidth > $wmHeight) {
                $newWmWidth = $targetWmSize;
                $newWmHeight = max(1, (int) ($targetWmSize / $wmRatio));
            } else {
                $newWmHeight = $targetWmSize;
                $newWmWidth = max(1, (int) ($targetWmSize * $wmRatio));
            }

            $wmResized = imagecreatetruecolor($newWmWidth, $newWmHeight);
            if (! $wmResized) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }
            imagealphablending($wmResized, false);
            imagesavealpha($wmResized, true);
            $transparent = imagecolorallocatealpha($wmResized, 0, 0, 0, 127);
            if ($transparent === false || ! imagefill($wmResized, 0, 0, $transparent)) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }
            if (! imagecopyresampled($wmResized, $wm, 0, 0, 0, 0, $newWmWidth, $newWmHeight, $wmWidth, $wmHeight)) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }

            $dstX = max(0, (int) (($width - $newWmWidth) / 2));
            $dstY = max(0, (int) (($height - $newWmHeight) / 2));
            imagealphablending($img, true);
            if (! imagecopy($img, $wmResized, $dstX, $dstY, 0, 0, $newWmWidth, $newWmHeight)) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }

            if (! $this->ensureOutputDirectory($destPath)) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }

            $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
            $success = false;
            imagealphablending($img, false);
            imagesavealpha($img, true);

            if ($ext === 'webp') {
                $success = imagewebp($img, $destPath, 80);
            } elseif ($ext === 'png') {
                $success = imagepng($img, $destPath, 8);
            } else {
                $success = imagejpeg($img, $destPath, 90);
            }

            if (! $success || ! $this->isValidImageFile($destPath)) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }

            if (in_array($ext, ['jpg', 'jpeg'], true)) {
                $process = new Process(['exiftool', '-TagsFromFile', $sourcePath, '-All:All', '-Orientation=1', '-n', '-overwrite_original', $destPath]);
                $process->run();
            }

            if (! $this->writeWatermarkMarker($sourcePath, $destPath, $galleryType, $maxWidth, $bucket, $wmPath)) {
                $this->removeFailedOutput($destPath, $sourcePath);

                return false;
            }

            return true;
        } finally {
            if ($wmResized) {
                imagedestroy($wmResized);
            }
            if ($wm) {
                imagedestroy($wm);
            }
            if ($img) {
                imagedestroy($img);
            }
        }
    }

    /**
     * Resolve the exact watermark asset and normalized geometry used for a
     * source.  Keeping this in one place makes cache verification agree with
     * generation, including the max-width resize performed before overlay.
     *
     * @return array{bucket:int, watermark_hash:string}|null
     */
    private function watermarkContext(string $sourcePath, string $galleryType, ?int $maxWidth): ?array
    {
        if (! in_array($galleryType, ['delivery', 'selection'], true)) {
            return null;
        }

        $info = @getimagesize($sourcePath);
        if (! is_array($info)) {
            return null;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($maxWidth !== null && $maxWidth > 0 && $width > $maxWidth) {
            $height = max(1, (int) ($height * ($maxWidth / $width)));
            $width = $maxWidth;
        }

        $bucket = $this->watermarkBucket($width, $height);
        if ($bucket === null) {
            return null;
        }

        $watermarkPath = $this->resolveWatermarkAsset($galleryType, $bucket);
        if ($watermarkPath === null) {
            return null;
        }

        $watermarkHash = @hash_file('sha256', $watermarkPath);
        if (! is_string($watermarkHash) || $watermarkHash === '') {
            return null;
        }

        return [
            'bucket' => $bucket,
            'watermark_hash' => $watermarkHash,
        ];
    }

    /**
     * Persist provenance atomically after the image and metadata have been
     * written.  A missing marker is intentional fail-closed behavior: callers
     * will regenerate an old cache entry instead of trusting its bytes.
     */
    private function writeWatermarkMarker(
        string $sourcePath,
        string $destPath,
        string $galleryType,
        ?int $maxWidth,
        int $bucket,
        string $watermarkPath,
    ): bool {
        if ($sourcePath === $destPath || ! in_array($galleryType, ['delivery', 'selection'], true)) {
            return false;
        }

        $sourceHash = @hash_file('sha256', $sourcePath);
        $outputHash = @hash_file('sha256', $destPath);
        $watermarkHash = @hash_file('sha256', $watermarkPath);
        if (! is_string($sourceHash) || $sourceHash === ''
            || ! is_string($outputHash) || $outputHash === ''
            || ! is_string($watermarkHash) || $watermarkHash === '') {
            return false;
        }

        $marker = [
            'version' => self::WATERMARK_MARKER_VERSION,
            'source_sha256' => $sourceHash,
            'output_sha256' => $outputHash,
            'watermark_sha256' => $watermarkHash,
            'gallery_type' => $galleryType,
            'bucket' => $bucket,
            'max_width' => $maxWidth !== null && $maxWidth > 0 ? $maxWidth : null,
        ];

        try {
            $contents = json_encode($marker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return false;
        }

        $directory = dirname($destPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        $temporaryPath = @tempnam($directory, '.watermark-');
        if (! is_string($temporaryPath) || $temporaryPath === '') {
            return false;
        }

        $written = @file_put_contents($temporaryPath, $contents, LOCK_EX);
        if ($written !== strlen($contents) || ! @rename($temporaryPath, $this->watermarkMarkerPath($destPath))) {
            @unlink($temporaryPath);
            @unlink($this->watermarkMarkerPath($destPath));

            return false;
        }

        return true;
    }

    private function watermarkMarkerPath(string $destPath): string
    {
        return $destPath.self::WATERMARK_MARKER_SUFFIX;
    }

    private function removeWatermarkMarker(string $destPath): void
    {
        $markerPath = $this->watermarkMarkerPath($destPath);
        if (! $this->unlinkPath($markerPath)) {
            // `photos` uses throw => false, so a failed unlink is silent
            // otherwise. Surface a residual marker so the leak is observable.
            Log::warning('image_processor.watermark_marker_unlink_failed', [
                'path' => $markerPath,
            ]);
        }
    }

    private function watermarkBucket(int $width, int $height): ?int
    {
        if ($width < 1 || $height < 1) {
            return null;
        }

        $targetSize = (int) (min($width, $height) / 3);
        if ($targetSize <= 500) {
            return 500;
        }
        if ($targetSize <= 1000) {
            return 1000;
        }

        return 2000;
    }

    private function resolveWatermarkAsset(string $galleryType, int $bucket): ?string
    {
        if (! in_array($bucket, self::WATERMARK_BUCKETS, true)) {
            return null;
        }

        $name = ($galleryType === 'selection' ? 'master_selection_' : 'master_').$bucket.'.png';
        $brandPrefix = BrandRegistry::prefix();
        $relativeCandidates = array_values(array_unique([
            '_watermarks/'.$brandPrefix.$name,
            '_watermarks/'.$name,
        ]));
        $disk = Storage::disk('photos');

        foreach ($relativeCandidates as $relativePath) {
            $path = $disk->path($relativePath);
            if (! is_file($path)) {
                continue;
            }

            $watermark = @imagecreatefrompng($path);
            if (! $watermark) {
                // A present-but-corrupt preferred asset must not silently fall
                // through to an unwatermarked output.
                return null;
            }

            $valid = imagesx($watermark) > 0 && imagesy($watermark) > 0;
            imagedestroy($watermark);
            if (! $valid) {
                return null;
            }

            return $path;
        }

        return null;
    }

    private function ensureOutputDirectory(string $path): bool
    {
        $directory = dirname($path);
        if (is_dir($directory)) {
            return true;
        }

        return @mkdir($directory, 0755, true) || is_dir($directory);
    }

    private function removeFailedOutput(string $path, string $sourcePath): void
    {
        if ($path !== $sourcePath && ! $this->unlinkPath($path)) {
            // A silent failed unlink leaves a partially written derivative that
            // FileDeliveryController would later reject with a 500.
            Log::warning('image_processor.failed_output_unlink_failed', [
                'path' => $path,
                'source' => $sourcePath,
            ]);
        }
        $this->removeWatermarkMarker($path);
    }

    /**
     * Remove a path and report whether it is actually gone.
     *
     * `@unlink` on the `throw => false` photos disk fails silently. The
     * postcondition is the observable truth used by callers and logs.
     */
    protected function unlinkPath(string $path): bool
    {
        if (! is_file($path) && ! is_link($path)) {
            return true;
        }

        @unlink($path);

        return ! is_file($path) && ! is_link($path);
    }

    /**
     * GD allocation seam. Kept separate so the false-return path can be
     * exercised without exhausting the process memory limit in tests.
     */
    protected function createTrueColorImage(int $width, int $height): \GdImage|false
    {
        return imagecreatetruecolor($width, $height);
    }

    private function loadGdImage($path)
    {
        $info = @getimagesize($path);
        if (! $info) {
            return null;
        }
        $mime = $info['mime'];
        $img = null;
        switch ($mime) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg($path);
                break;
            case 'image/png':
                $img = @imagecreatefrompng($path);
                break;
            case 'image/webp':
                $img = @imagecreatefromwebp($path);
                break;
        }
        if ($img && function_exists('exif_read_data') && $mime === 'image/jpeg') {
            $exif = @exif_read_data($path);
            if ($exif && isset($exif['Orientation'])) {
                switch ($exif['Orientation']) {
                    case 3:
                        $rotated = imagerotate($img, 180, 0);
                        if ($rotated) {
                            imagedestroy($img);
                            $img = $rotated;
                        } else {
                            imagedestroy($img);

                            return null;
                        }
                        break;
                    case 6:
                        $rotated = imagerotate($img, -90, 0);
                        if ($rotated) {
                            imagedestroy($img);
                            $img = $rotated;
                        } else {
                            imagedestroy($img);

                            return null;
                        }
                        break;
                    case 8:
                        $rotated = imagerotate($img, 90, 0);
                        if ($rotated) {
                            imagedestroy($img);
                            $img = $rotated;
                        } else {
                            imagedestroy($img);

                            return null;
                        }
                        break;
                }
            }
        }

        return $img;
    }
}
