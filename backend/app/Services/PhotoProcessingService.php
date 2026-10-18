<?php

namespace App\Services;

use App\Models\Gallery;
use Carbon\Carbon;
use Symfony\Component\Process\Process;

class PhotoProcessingService
{
    public const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    public const MAX_IMAGE_DIMENSION = 15_000;

    public const MAX_IMAGE_PIXELS = 40_000_000;

    /**
     * Detect and preflight a raster image using its on-disk contents, never
     * the upload's client-provided MIME header.
     *
     * @return array{width:int, height:int, mime_type:string}|null
     */
    public static function inspectRasterImage(string $path, int $maxBytes = self::MAX_IMAGE_BYTES): ?array
    {
        if (! is_file($path) || $maxBytes < 1) {
            return null;
        }

        $bytes = @filesize($path);
        if (! is_int($bytes) || $bytes < 1 || $bytes > $maxBytes) {
            return null;
        }

        $image = @getimagesize($path);
        if (! is_array($image)) {
            return null;
        }

        $mime = $image['mime'] ?? null;
        if (! is_string($mime) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($detectedMime !== $mime) {
            return null;
        }

        $width = (int) ($image[0] ?? 0);
        $height = (int) ($image[1] ?? 0);
        if (! self::isWithinImageBudget($bytes, $width, $height, $maxBytes)) {
            return null;
        }

        return [
            'width' => $width,
            'height' => $height,
            'mime_type' => $mime,
        ];
    }

    public static function isWithinImageBudget(int $bytes, int $width, int $height, int $maxBytes = self::MAX_IMAGE_BYTES): bool
    {
        if ($bytes < 1 || $bytes > $maxBytes
            || $width < 1 || $height < 1
            || $width > self::MAX_IMAGE_DIMENSION
            || $height > self::MAX_IMAGE_DIMENSION) {
            return false;
        }

        // Division keeps the check safe even for dimensions near PHP_INT_MAX.
        return $width <= intdiv(self::MAX_IMAGE_PIXELS, $height);
    }

    /**
     * Extrahiert Metadaten via ExifTool und wendet Galerie-Vorgaben an.
     * Hinweis: Thumbnail-Generierung wurde hier entfernt, da diese nun
     * Lazy (on-the-fly) über den FileDeliveryController passiert.
     */
    public function processImage(string $targetPath, string $thumbPath, Gallery $gallery): array
    {
        $size = @getimagesize($targetPath);
        $width = $size ? (int) $size[0] : 0;
        $height = $size ? (int) $size[1] : 0;

        $applyDefaults = $gallery->type !== 'selection' && $gallery->apply_metadata_to_photos;

        // Basis-Metadaten initialisieren (inkl. möglicher Galerie-Defaults)
        $meta = [
            'width' => $width,
            'height' => $height,
            'title' => $applyDefaults ? $gallery->default_title : null,
            'description' => $applyDefaults ? $gallery->default_description : null,
            'keywords' => $applyDefaults ? $gallery->default_keywords : null,
            'location' => $applyDefaults ? $gallery->default_location : null,
            'city' => $applyDefaults ? $gallery->default_city : null,
            'state' => $applyDefaults ? $gallery->default_state : null,
            'country' => $applyDefaults ? $gallery->default_country : null,
            'iso_country' => $applyDefaults ? $gallery->default_iso_country : null,
        ];

        // Selection Galerien überspringen die Exif-Extraktion aus Performance-Gründen
        if ($gallery->type === 'selection' || ! $gallery->apply_metadata_to_photos) {
            return $meta;
        }

        // Metadaten via ExifTool auslesen. runExifTool ist als protected-Methode
        // extrahiert, damit der externe Binary-Aufruf (exiftool) in Tests stumm
        // geschaltet werden kann, ohne das echte Binary aufzurufen.
        $metaData = $this->runExifTool($targetPath);

        if (is_array($metaData) && isset($metaData[0])) {
            $m = $metaData[0];
            $meta['title'] = $m['Title'] ?? $m['ObjectName'] ?? $m['XPTitle'] ?? $meta['title'];
            $meta['description'] = $m['ImageDescription'] ?? $m['Caption-Abstract'] ?? $meta['description'];

            $meta['keywords'] = is_array($m['Keywords'] ?? null) ? implode(', ', $m['Keywords']) : ($m['Keywords'] ?? $meta['keywords']);
            $meta['location'] = $m['Sub-location'] ?? $meta['location'];
            $meta['city'] = $m['City'] ?? $meta['city'];
            $meta['state'] = $m['Province-State'] ?? $meta['state'];
            $meta['country'] = $m['Country-PrimaryLocationName'] ?? $meta['country'];
            $meta['iso_country'] = $m['Country-PrimaryLocationCode'] ?? $meta['iso_country'];
            $dateStr = $m['DateTimeOriginal'] ?? $m['CreateDate'] ?? null;
            if ($dateStr) {
                try {
                    $meta['captured_at'] = Carbon::createFromFormat('Y:m:d H:i:s', substr($dateStr, 0, 19))->toDateTimeString();
                } catch (\Exception $e) {
                }
            }
        }

        return $meta;
    }

    /**
     * Führt ExifTool aus und liefert die dekodierten Metadaten (array) oder null.
     * Als protected extrahiert → in Tests als Seam überschreibbar, ohne das
     * exiftool-Binary wirklich aufzurufen.
     *
     * @return array|null
     */
    protected function runExifTool(string $targetPath)
    {
        $process = new Process([
            'exiftool', '-json', '-Title', '-ObjectName', '-XPTitle',
            '-ImageDescription', '-Caption-Abstract', '-Keywords', '-Sub-location', '-City', '-Province-State',
            '-Country-PrimaryLocationName', '-Country-PrimaryLocationCode', '-DateTimeOriginal', '-CreateDate',
            $targetPath,
        ]);
        $process->run();

        return json_decode($process->getOutput(), true);
    }
}
