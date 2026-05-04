<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use App\Models\Gallery;

class PhotoProcessingService
{
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
        if ($gallery->type === 'selection' || !$gallery->apply_metadata_to_photos) {
            return $meta;
        }

        // Metadaten via Exiftool auslesen
        $process = new Process([
            'exiftool', '-json', '-Title', '-ObjectName', '-XPTitle', 
            '-ImageDescription', '-Caption-Abstract', '-Keywords', '-Sub-location', '-City', '-Province-State', 
            '-Country-PrimaryLocationName', '-Country-PrimaryLocationCode', '-DateTimeOriginal', '-CreateDate',
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
            $dateStr = $m['DateTimeOriginal'] ?? $m['CreateDate'] ?? null;
            if ($dateStr) {
                try {
                    $meta['captured_at'] = \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', substr($dateStr, 0, 19))->toDateTimeString();
                } catch (\Exception $e) {}
            }
        }

        return $meta;
    }
}
