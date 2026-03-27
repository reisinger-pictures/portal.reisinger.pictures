<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Request;
use App\Services\WatermarkService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FileDeliveryController extends Controller
{
    public function serve(Request $request, $slug, $identifier)
    {
        $gallery = Gallery::where('slug', $slug)->firstOrFail();
        $user = auth('api')->user();

        if (!$gallery->is_public) {
            if (!$user) abort(401, 'Unauthorized');
            if (!$user->canAccessGallery($gallery->id)) abort(403, 'Forbidden');
        }

        $needsWatermark = true;
        if ($user && ($user->is_admin || $user->canAccessGallery($gallery->id))) {
            $needsWatermark = false;
        }
        
        $baseStoragePath = rtrim(\Illuminate\Support\Facades\Storage::disk('photos')->path(''), '/');
        $watermarkService = app(WatermarkService::class);
        $processor = app(\App\Services\ImageProcessor::class);

        // --- LAZY THUMBNAIL GENERATION (UUID statt Dateiname) ---
        if (preg_match('#^_thumbs/(\d+)/([a-f0-9\-]+)\.webp$#i', $identifier, $matches)) {
            $size = (int) $matches[1];
            $photoId = $matches[2];
            
            $photo = \App\Models\Photo::where('id', $photoId)->where('gallery_id', $gallery->id)->firstOrFail();
            
            $originalPath = $baseStoragePath . '/' . $gallery->id . '/' . $photo->filename;
            $sourcePath = $baseStoragePath . '/' . $gallery->id . '/_thumbs/' . $size . '/' . $photo->id . '.webp';
            $xAccelPath = '/protected-photos/' . $gallery->id . '/_thumbs/' . $size . '/' . $photo->id . '.webp';

            if (!file_exists($sourcePath) && file_exists($originalPath)) {
                if (!is_dir(dirname($sourcePath))) @mkdir(dirname($sourcePath), 0755, true);
                
                $lockKey = 'thumb_gen_' . md5($sourcePath);
                try {
                    Cache::lock($lockKey, 30)->block(15, function () use ($originalPath, $sourcePath, $size, $processor) {
                        if (file_exists($sourcePath)) return;
                        $processor->generateThumbnail($originalPath, $sourcePath, $size);
                    });
                } catch (\Exception $e) {
                    Log::error("Lazy Thumb Error: " . $e->getMessage());
                    $sourcePath = $originalPath; 
                }
            }

            $path = $sourcePath;

            if ($needsWatermark && $path !== $originalPath) {
                $path = $baseStoragePath . '/' . $gallery->id . '/_thumbs/_watermarked/' . $size . '/' . $photo->id . '.webp';
                $xAccelPath = '/protected-photos/' . $gallery->id . '/_thumbs/_watermarked/' . $size . '/' . $photo->id . '.webp';
                if (!file_exists($path) && file_exists($sourcePath)) {
                    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
                    $watermarkService->applyWatermark($sourcePath, $path);
                }
            }
        } 
        // --- ORIGINAL IMAGE DELIVERY (UUID statt Dateiname) ---
        else {
            if (preg_match('#^([a-f0-9\-]+)\.[a-z0-9]+$#i', $identifier, $matches)) {
                $photoId = $matches[1];
                $photo = \App\Models\Photo::where('id', $photoId)->where('gallery_id', $gallery->id)->firstOrFail();
            } else {
                abort(404, 'Invalid file identifier');
            }

            $sourcePath = $baseStoragePath . '/' . $gallery->id . '/' . $photo->filename;
            $path = $sourcePath;
            $xAccelPath = '/protected-photos/' . $gallery->id . '/' . $photo->filename;

            if ($needsWatermark) {
                $path = $baseStoragePath . '/' . $gallery->id . '/_watermarked/' . $photo->filename;
                $xAccelPath = '/protected-photos/' . $gallery->id . '/_watermarked/' . $photo->filename;
                if (!file_exists($path) && file_exists($sourcePath)) {
                    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
                    $watermarkService->applyWatermark($sourcePath, $path, 2000);
                }
            }
        }

        if (!file_exists($path)) abort(404, 'File not found');

        $mime = mime_content_type($path);
        $proxyHeader = env('PROXY_DELIVERY_HEADER', false);

        if ($proxyHeader) {
            $deliveryPath = (strtolower($proxyHeader) === 'x-sendfile') ? $path : $xAccelPath;
            return response('', 200)->withHeaders([
                'Content-Type' => $mime,
                'Cache-Control' => 'public, max-age=31536000, immutable',
                $proxyHeader => $deliveryPath
            ]);
        }

        return response()->file($path, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable'
        ]);
    }
}
