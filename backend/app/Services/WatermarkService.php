<?php

namespace App\Services;

use App\Models\Setting;

class WatermarkService
{
    public function applyWatermark($sourcePath, $destPath, $maxWidth = null, $galleryType = 'delivery')
    {
        $svgPath = storage_path('app/private/watermark.svg');
        $text = Setting::where('key', 'watermark_text')->value('value') ?? 'reisinger.pictures';
        $opacity = (float) (Setting::where('key', 'watermark_opacity')->value('value') ?? 0.15);

        // Subtilerer Schutz bei reinen Auswahl-Galerien (30% der normalen Deckkraft)
        if ($galleryType === 'selection') {
            $opacity = $opacity * 0.3;
        }

        $svgFile = file_exists($svgPath) ? $svgPath : null;

        $processor = app(ImageProcessor::class);
        return $processor->generateTiledWatermark($sourcePath, $destPath, $svgFile, $text, $opacity, $maxWidth);
    }
}
