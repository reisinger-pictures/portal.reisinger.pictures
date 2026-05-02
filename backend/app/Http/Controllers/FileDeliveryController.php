<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Http\Request;
use App\Services\WatermarkService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FileDeliveryController extends Controller
{
    public function serve(Request $request, $slug, $identifier)
    {
        $gallery = \Illuminate\Support\Str::isUuid($slug) 
            ? Gallery::where('id', $slug)->first() 
            : Gallery::where('slug', $slug)->first();
        if (!$gallery) return response()->json(['error' => 'Galerie nicht gefunden'], 404);

        $user = auth('api')->user();
        $isExpired = $gallery->expires_at && \Carbon\Carbon::parse($gallery->expires_at)->isPast();
        $canManage = $user && ($user->is_admin || ($user->is_photographer && $user->canAccessGallery($gallery->id)));

        if ($isExpired && !$canManage) return response()->json(['error' => 'Galerie abgelaufen'], 403);
        
        if (!$gallery->is_public) {
            if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);
            if (!$user->canAccessGallery($gallery->id)) return response()->json(['error' => 'Forbidden'], 403);
        }

        $baseStoragePath = rtrim(\Illuminate\Support\Facades\Storage::disk('photos')->path(''), '/\\');
        $processor = app(\App\Services\ImageProcessor::class);
        $watermarkService = app(WatermarkService::class);
        
        // 1. Konzeptueller Check: Hat der User das Recht auf die cleane Originaldatei?
        $logicalNeedsWatermark = true;
        if ($gallery->effective_is_free_download) $logicalNeedsWatermark = false;
        elseif ($user && ($user->is_admin || $user->is_photographer)) $logicalNeedsWatermark = false;
        elseif ($user && $user->canAccessGallery($gallery->id)) {
            $ranks = ['none' => 0, 'web' => 1, 'print' => 2, 'original' => 3];
            if (($ranks[$user->flatrate_level ?? 'none'] ?? 0) >= 1) $logicalNeedsWatermark = false;
        }

        // 2. Pfad-Prüfung und HTTP 403 Schutz
        $isWatermarkedRequest = str_starts_with($identifier, 'watermarked/');
        if ($isWatermarkedRequest) {
            $identifier = substr($identifier, 12);
        } else {
            if ($logicalNeedsWatermark) {
                return response()->json(['error' => 'Zugriff auf Original-Ressource verweigert. Wasserzeichen erforderlich.'], 403);
            }
        }

        // 3. Physischer Check: Wasserzeichen generieren, wenn angefordert UND global konfiguriert
        $globalWatermarkExists = \Illuminate\Support\Facades\Storage::disk('photos')->exists('_watermarks/master_500.png');
        $generateWatermark = $isWatermarkedRequest && $globalWatermarkExists;
        $path = null;

        // --- LAZY THUMBNAILS ---
        if (preg_match('#^_thumbs/(\d+)/([a-f0-9\-]+)\.webp$#i', $identifier, $matches)) {
            $size = (int) $matches[1];
            $photoId = $matches[2];

            $photo = Photo::where('id', $photoId)->where('gallery_id', $gallery->id)->first();
            if (!$photo) return response()->json(['error' => 'Foto nicht gefunden'], 404);

            $originalPath = $baseStoragePath . '/' . $gallery->id . '/' . $photo->filename;
            if (!file_exists($originalPath)) return response()->json(['error' => 'Original fehlt auf der Festplatte'], 404);

            $thumbPath = $baseStoragePath . '/' . $gallery->id . '/_thumbs/' . $size . '/' . $photo->id . '.webp';
            
            if (!file_exists($thumbPath)) {
                if (!is_dir(dirname($thumbPath))) @mkdir(dirname($thumbPath), 0755, true);
                try {
                    $processor->generateThumbnail($originalPath, $thumbPath, $size);
                } catch (\Throwable $e) {}
            }

            if (!file_exists($thumbPath)) return response()->json(['error' => 'Thumbnail fehlt'], 500);

            $path = $thumbPath;

            if ($generateWatermark) {
                $wmPath = $baseStoragePath . '/' . $gallery->id . '/_thumbs/_watermarked/' . $size . '/' . $photo->id . '.webp';
                if (!file_exists($wmPath)) {
                    if (!is_dir(dirname($wmPath))) @mkdir(dirname($wmPath), 0755, true);
                    try {
                        $watermarkService->applyWatermark($path, $wmPath, null, $gallery->type);
                        if (!file_exists($wmPath)) throw new \Exception("Watermark file missing.");
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'SECURITY: Watermark-Fail.'], 500);
                    }
                }
                $path = $wmPath; 
            }
        } 
        // --- ORIGINAL BILDER ---
        else {
            if (preg_match('#^([a-f0-9\-]+)\.[a-z0-9]+$#i', $identifier, $matches)) {
                $photoId = $matches[1];
                $photo = Photo::where('id', $photoId)->where('gallery_id', $gallery->id)->first();
                if (!$photo) return response()->json(['error' => 'Foto nicht gefunden'], 404);
            } else {
                return response()->json(['error' => 'Ungültiges URL-Format'], 400);
            }

            $originalPath = $baseStoragePath . '/' . $gallery->id . '/' . $photo->filename;
            if (!file_exists($originalPath)) return response()->json(['error' => 'Original fehlt auf der Festplatte'], 404);
            
            $path = $originalPath;

            if ($generateWatermark) {
                $wmPath = $baseStoragePath . '/' . $gallery->id . '/_watermarked/' . $photo->filename;
                if (!file_exists($wmPath)) {
                    if (!is_dir(dirname($wmPath))) @mkdir(dirname($wmPath), 0755, true);
                    try {
                        $watermarkService->applyWatermark($path, $wmPath, 2000, $gallery->type);
                        if (!file_exists($wmPath)) throw new \Exception("Watermark file missing.");
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'SECURITY: Watermark-Fail.'], 500);
                    }
                }
                $path = $wmPath;
            }
        }

        if (!file_exists($path)) return response()->json(['error' => 'Datei nicht gefunden'], 404);

        $cacheKey = 'photo_hit_' . $photo->id;
        if (!Cache::has($cacheKey)) {
            $photo->update(['last_accessed_at' => now()]);
            Cache::put($cacheKey, true, now()->addHours(24));
        }

        $headers = ['Content-Type' => $photo->mime_type ?? mime_content_type($path), 'Cache-Control' => 'private, max-age=31536000, immutable'];

        if ($proxyHeader = env('PROXY_DELIVERY_HEADER')) {
            $headers[$proxyHeader] = $path;
            return response()->make('', 200, $headers);
        }

        return response()->file($path, $headers);
    }
}
