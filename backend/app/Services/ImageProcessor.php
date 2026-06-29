<?php

namespace App\Services;

use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ImageProcessor
{
    public function generateThumbnail($sourcePath, $destPath, $size, $quality = 80)
    {
        $img = $this->loadGdImage($sourcePath);
        if (!$img) return false;

        $width = imagesx($img);
        $height = imagesy($img);

        if ($width > $size) {
            $newHeight = (int)($height * ($size / $width));
            $newImg = imagecreatetruecolor($size, $newHeight);
            imagealphablending($newImg, false);
            imagesavealpha($newImg, true);
            imagecopyresampled($newImg, $img, 0, 0, 0, 0, $size, $newHeight, $width, $height);
            imagedestroy($img);
            $img = $newImg;
        }

        $success = imagewebp($img, $destPath, $quality);
        imagedestroy($img);
        return $success;
    }

    public function scaleImage($sourcePath, $destPath, $maxWidth) {
        if (!$maxWidth) { copy($sourcePath, $destPath); return true; }
        
        $img = $this->loadGdImage($sourcePath);
        if (!$img) { throw new \Exception("Konnte Originalbild für Wasserzeichen nicht laden: " . $sourcePath); }

        $width = imagesx($img);
        $height = imagesy($img);

        if ($width > $maxWidth) {
            $ratio = $maxWidth / $width;
            $newWidth = $maxWidth;
            $newHeight = (int)($height * $ratio);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
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

        if ($success && file_exists($destPath) && in_array($ext, ['jpg', 'jpeg'])) {
            $process = new Process(['exiftool', '-TagsFromFile', $sourcePath, '-All:All', '-Orientation=1', '-n', '-overwrite_original', $destPath]);
            $process->run();
        }

        return $success;
    }

    public function applyCenteredWatermark($sourcePath, $destPath, $maxWidth = null, $galleryType = 'delivery') {
        $img = $this->loadGdImage($sourcePath);
        if (!$img) { copy($sourcePath, $destPath); return false; }

        $width = imagesx($img);
        $height = imagesy($img);

        if ($maxWidth && $width > $maxWidth) {
            $ratio = $maxWidth / $width;
            $newWidth = $maxWidth;
            $newHeight = (int)($height * $ratio);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($img);
            $img = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }

        $shortestSide = min($width, $height);
        $targetWmSize = (int)($shortestSide / 3);

        $bucket = 2000;
        if ($targetWmSize <= 500) $bucket = 500;
        elseif ($targetWmSize <= 1000) $bucket = 1000;

        $prefix = $galleryType === 'selection' ? 'master_selection_' : 'master_';
        $pfx = BrandRegistry::prefix();
        $wmPath = \Illuminate\Support\Facades\Storage::disk('photos')->path('_watermarks/' . $pfx . $prefix . $bucket . '.png');
        if (!file_exists($wmPath)) {
            $wmPath = \Illuminate\Support\Facades\Storage::disk('photos')->path('_watermarks/' . $prefix . $bucket . '.png');
        }
        
        if (file_exists($wmPath)) {
            $wm = @imagecreatefrompng($wmPath);
            if ($wm) {
                $wmWidth = imagesx($wm);
                $wmHeight = imagesy($wm);

                $wmRatio = $wmWidth / $wmHeight;
                if ($wmWidth > $wmHeight) {
                    $newWmWidth = $targetWmSize;
                    $newWmHeight = (int)($targetWmSize / $wmRatio);
                } else {
                    $newWmHeight = $targetWmSize;
                    $newWmWidth = (int)($targetWmSize * $wmRatio);
                }

                $wmResized = imagecreatetruecolor($newWmWidth, $newWmHeight);
                imagealphablending($wmResized, false);
                imagesavealpha($wmResized, true);
                $transparent = imagecolorallocatealpha($wmResized, 0, 0, 0, 127);
                imagefill($wmResized, 0, 0, $transparent);
                
                imagecopyresampled($wmResized, $wm, 0, 0, 0, 0, $newWmWidth, $newWmHeight, $wmWidth, $wmHeight);
                
                $dstX = (int)(($width - $newWmWidth) / 2);
                $dstY = (int)(($height - $newWmHeight) / 2);

                imagealphablending($img, true);
                imagecopy($img, $wmResized, $dstX, $dstY, 0, 0, $newWmWidth, $newWmHeight);
                
                imagedestroy($wm);
                imagedestroy($wmResized);
            }
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
        imagedestroy($img);

        if ($success && file_exists($destPath) && in_array($ext, ['jpg', 'jpeg'])) {
            $process = new Process(['exiftool', '-TagsFromFile', $sourcePath, '-All:All', '-Orientation=1', '-n', '-overwrite_original', $destPath]);
            $process->run();
        }

        return $success;
    }

    private function loadGdImage($path) {
        $info = @getimagesize($path);
        if (!$info) return null;
        $mime = $info['mime'];
        $img = null;
        switch ($mime) {
            case 'image/jpeg': $img = @imagecreatefromjpeg($path); break;
            case 'image/png': $img = @imagecreatefrompng($path); break;
            case 'image/webp': $img = @imagecreatefromwebp($path); break;
        }
        if ($img && function_exists('exif_read_data') && $mime === 'image/jpeg') {
            $exif = @exif_read_data($path);
            if ($exif && isset($exif['Orientation'])) {
                switch($exif['Orientation']) {
                    case 3: $img = imagerotate($img, 180, 0); break;
                    case 6: $img = imagerotate($img, -90, 0); break;
                    case 8: $img = imagerotate($img, 90, 0); break;
                }
            }
        }
        return $img;
    }
}
