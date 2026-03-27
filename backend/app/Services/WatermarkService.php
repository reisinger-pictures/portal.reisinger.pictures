<?php

namespace App\Services;

use App\Models\Setting;

class WatermarkService
{
    public function applyWatermark($sourcePath, $destPath, $maxWidth = null)
    {
        $svgPath = storage_path('app/private/watermark.svg');
        
        if (!file_exists($svgPath)) {
            copy($sourcePath, $destPath);
            return true;
        }

        $scale = (float) (Setting::where('key', 'watermark_scale')->value('value') ?? 0.10);
        $opacity = (float) (Setting::where('key', 'watermark_opacity')->value('value') ?? 0.6);
        $position = Setting::where('key', 'watermark_position')->value('value') ?? 'bottom-right';

        $processor = app(ImageProcessor::class);
        return $processor->generateWatermarkedImage($sourcePath, $destPath, $svgPath, $scale, $opacity, $position, $maxWidth);
    }
}
