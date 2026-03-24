<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use App\Models\Gallery;

class PhotoProcessingService
{
    public function processImage(string $targetPath, string $thumbPath, Gallery $gallery): array
    {
        $size = @getimagesize($targetPath);
        $width = $size ? (int) $size[0] : 0;
        $height = $size ? (int) $size[1] : 0;

        $applyDefaults = $gallery->type !== 'selection' && $gallery->apply_metadata_to_photos;

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
        if ($gallery->type === 'selection' || !$gallery->apply_metadata_to_photos) {
            $this->generateThumbnail($targetPath, $thumbPath, $width);
            return $meta;
        }

        // Metadaten via Exiftool auslesen
        $process = new Process([
            'exiftool', '-json', '-Title', '-ObjectName', '-XPTitle', 
            '-ImageDescription', '-Caption-Abstract', '-Keywords', '-Sub-location', '-City', '-Province-State', 
            '-Country-PrimaryLocationName', '-Country-PrimaryLocationCode',
            $targetPath
        ]);
        $process->run();
        $metaData = json_decode($process->getOutput(), true);
        
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
        }

        $this->generateThumbnail($targetPath, $thumbPath, $width);

        return $meta;
    }

    private function generateThumbnail(string $targetPath, string $thumbPath, int $width)
    {
        // 1. Priorität: PHP Imagick Extension (Produktion)
        if (class_exists('Imagick')) {
            try {
                $im = new \Imagick($targetPath);
                $im->autoOrient();
                if ($width > 1024) {
                    $im->thumbnailImage(1024, 0, false);
                }
                $im->setImageFormat('webp');
                $im->setImageCompressionQuality(80);
                $im->writeImage($thumbPath);
                $im->clear();
                $im->destroy();
                return;
            } catch (\Exception $e) {
                Log::warning("Imagick Extension Error: " . $e->getMessage());
            }
        }

        // 2. Fallback: ImageMagick via Kommandozeile (Local Dev / Windows)
        try {
            // Versuche ImageMagick 7 (magick)
            $process = new Process(['magick', $targetPath, '-auto-orient', '-resize', '1024x>', '-quality', '80', $thumbPath]);
            $process->run();

            if (!$process->isSuccessful()) {
                // Fallback auf ImageMagick 6 Legacy (convert)
                $process = new Process(['convert', $targetPath, '-auto-orient', '-resize', '1024x>', '-quality', '80', $thumbPath]);
                $process->run();

                if (!$process->isSuccessful()) {
                    throw new \RuntimeException('ImageMagick CLI Error: ' . $process->getErrorOutput());
                }
            }
        } catch (\Exception $e) {
            Log::error("Thumbnail CLI Fallback failed: " . $e->getMessage());
            throw $e; // Hard-Fail für den Test!
        }
    }
}
