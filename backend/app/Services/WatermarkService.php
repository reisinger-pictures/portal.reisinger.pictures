<?php

namespace App\Services;

class WatermarkService
{
    public function applyWatermark($sourcePath, $destPath, $maxWidth = null, $galleryType = 'delivery')
    {
        $processor = app(ImageProcessor::class);
        return $processor->applyCenteredWatermark($sourcePath, $destPath, $maxWidth, $galleryType);
    }
}
