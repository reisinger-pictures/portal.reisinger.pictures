<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Imagick;
use ImagickPixel;

class ImageProcessor
{
    private function runMagick($args)
    {
        $process = new Process(array_merge(['magick'], $args));
        $process->run();
        if ($process->isSuccessful()) return true;

        // Fallback für ältere Systeme (convert)
        $process = new Process(array_merge(['convert'], $args));
        $process->run();
        if ($process->isSuccessful()) return true;

        Log::error("ImageMagick CLI Error: " . $process->getErrorOutput());
        return false;
    }

    public function generateThumbnail($sourcePath, $destPath, $size, $quality = 80)
    {
        // 1. PHP Extension (Produktion)
        if (class_exists('Imagick')) {
            try {
                Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 1);
                $im = new Imagick($sourcePath);
                $im->autoOrient();
                if ($im->getImageWidth() > $size) {
                    $im->thumbnailImage($size, 0, false);
                }
                $im->setImageFormat('webp');
                $im->setImageCompressionQuality($quality);
                $im->writeImage($destPath);
                $im->clear(); 
                $im->destroy();
                return true;
            } catch (\Exception $e) {
                Log::warning("Imagick PHP Error: " . $e->getMessage());
            }
        }

        // 2. CLI Fallback (Lokale Entwicklung)
        return $this->runMagick([
            $sourcePath,
            '-auto-orient',
            '-resize', $size . 'x>',
            '-quality', (string)$quality,
            'webp:' . $destPath
        ]);
    }

    public function generateWatermarkedImage($sourcePath, $destPath, $svgPath, $scale, $opacity, $position, $maxWidth = null)
    {
        $dimensions = @getimagesize($sourcePath);
        if (!$dimensions) {
            copy($sourcePath, $destPath);
            return false;
        }
        $imgWidth = $dimensions[0];
        $imgHeight = $dimensions[1];
        
        if ($maxWidth && $imgWidth > $maxWidth) {
            $ratio = $maxWidth / $imgWidth;
            $imgWidth = $maxWidth;
            $imgHeight = $imgHeight * $ratio;
        }

        $longest = max($imgWidth, $imgHeight);
        $targetWmWidth = $longest * $scale;

        // Rendern des SVG in Buckets, um CPU zu sparen
        $buckets = [500, 1000, 2000, 4000];
        $selectedBucket = 8000;
        foreach ($buckets as $bucket) {
            if ($bucket >= $targetWmWidth) {
                $selectedBucket = $bucket;
                break;
            }
        }

        $configHash = md5(filemtime($svgPath) . $opacity);
        $masterPngPath = storage_path("app/private/watermark_master_{$configHash}_bucket_{$selectedBucket}.png");

        if (!file_exists($masterPngPath)) {
            $this->generateMasterPng($svgPath, $masterPngPath, $opacity, $selectedBucket);
        }

        // 1. PHP Extension (Produktion)
        if (class_exists('Imagick')) {
            try {
                Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 1);
                $im = new Imagick($sourcePath);
                $im->autoOrient();
                if ($maxWidth && $im->getImageWidth() > $maxWidth) {
                    $im->resizeImage($maxWidth, 0, Imagick::FILTER_LANCZOS, 1);
                }
                
                $wm = new Imagick($masterPngPath);
                $wm->resizeImage((int) $targetWmWidth, 0, Imagick::FILTER_LANCZOS, 1);
                $wGeo = $wm->getImageGeometry();
                
                $geo = $im->getImageGeometry();
                $pad = max($geo['width'], $geo['height']) * 0.02;
                
                $x = $geo['width'] - $wGeo['width'] - $pad;
                $y = $geo['height'] - $wGeo['height'] - $pad;
                
                if ($position === 'center') {
                    $x = ($geo['width'] / 2) - ($wGeo['width'] / 2);
                    $y = ($geo['height'] / 2) - ($wGeo['height'] / 2);
                } elseif ($position === 'bottom-left') {
                    $x = $pad;
                } elseif ($position === 'top-right') {
                    $y = $pad;
                } elseif ($position === 'top-left') {
                    $x = $pad;
                    $y = $pad;
                }
                
                $im->compositeImage($wm, Imagick::COMPOSITE_OVER, (int) $x, (int) $y);
                $im->setImageCompressionQuality(80);
                $im->writeImage($destPath);
                
                $wm->clear(); 
                $wm->destroy();
                $im->clear(); 
                $im->destroy();
                return true;
            } catch (\Exception $e) {
                Log::warning("Imagick PHP Watermark Error: " . $e->getMessage());
            }
        }

        // 2. CLI Fallback (Lokale Entwicklung)
        $scaledWmPath = storage_path("app/private/temp_wm_" . uniqid() . ".png");
        $this->runMagick([$masterPngPath, '-resize', (int)$targetWmWidth . 'x', $scaledWmPath]);

        $gravityMap = [
            'bottom-right' => 'SouthEast', 'bottom-left' => 'SouthWest',
            'top-right' => 'NorthEast', 'top-left' => 'NorthWest', 'center' => 'Center'
        ];
        $gravity = $gravityMap[$position] ?? 'SouthEast';
        $pad = (int)(max($imgWidth, $imgHeight) * 0.02);
        $geometry = $position === 'center' ? '+0+0' : "+{$pad}+{$pad}";

        $args = [];
        if ($maxWidth) {
            $args = [$sourcePath, '-auto-orient', '-resize', $maxWidth . 'x>', $scaledWmPath, '-gravity', $gravity, '-geometry', $geometry, '-composite', '-quality', '80', $destPath];
        } else {
            $args = [$sourcePath, '-auto-orient', $scaledWmPath, '-gravity', $gravity, '-geometry', $geometry, '-composite', '-quality', '80', $destPath];
        }

        $success = $this->runMagick($args);
        @unlink($scaledWmPath);
        return $success;
    }

    private function generateMasterPng($svgPath, $outPath, $opacity, $renderWidth)
    {
        // 1. PHP Extension (Produktion)
        if (class_exists('Imagick')) {
            try {
                Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 1);
                $im = new Imagick();
                $im->setBackgroundColor(new ImagickPixel('transparent'));
                $im->setResolution(300, 300);
                $im->readImage($svgPath);
                $im->setImageFormat("png32");
                if ($opacity < 1.0) {
                    $im->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);
                }
                $im->resizeImage($renderWidth, 0, Imagick::FILTER_LANCZOS, 1);
                $im->writeImage($outPath);
                $im->clear(); 
                $im->destroy();
                return true;
            } catch (\Exception $e) {
                Log::warning("Imagick PHP SVG Error: " . $e->getMessage());
            }
        }

        // 2. CLI Fallback (Lokale Entwicklung)
        $args = ['-background', 'none', '-density', '300', $svgPath, '-resize', $renderWidth . 'x'];
        if ($opacity < 1.0) {
            array_push($args, '-channel', 'A', '-evaluate', 'multiply', (string)$opacity, '+channel');
        }
        $args[] = 'png32:' . $outPath;
        return $this->runMagick($args);
    }
}
