<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Request;
use App\Services\WatermarkService;

class FileDeliveryController extends Controller
{
    public function serve(Request $request, $slug, $filename)
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
        $cleanFilename = basename($filename);
        $baseStoragePath = rtrim(\Illuminate\Support\Facades\Storage::disk('photos')->path(''), '/');
        $watermarkService = app(WatermarkService::class);

        if (str_starts_with($request->path(), "api/media/$slug/_thumbs/")) {
            $sourcePath = $baseStoragePath . '/' . $gallery->id . '/_thumbs/' . $cleanFilename;
            $path = $sourcePath;
            $xAccelPath = '/protected-photos/' . $gallery->id . '/_thumbs/' . $cleanFilename;
            
            if ($needsWatermark) {
                $path = $baseStoragePath . '/' . $gallery->id . '/_thumbs/_watermarked/' . $cleanFilename;
                $xAccelPath = '/protected-photos/' . $gallery->id . '/_thumbs/_watermarked/' . $cleanFilename;
                if (!file_exists($path) && file_exists($sourcePath)) {
                    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
                    $watermarkService->applyWatermark($sourcePath, $path);
                }
            }
        } else {
            $sourcePath = $baseStoragePath . '/' . $gallery->id . '/' . $cleanFilename;
            $path = $sourcePath;
            $xAccelPath = '/protected-photos/' . $gallery->id . '/' . $cleanFilename;

            if ($needsWatermark) {
                $path = $baseStoragePath . '/' . $gallery->id . '/_watermarked/' . $cleanFilename;
                $xAccelPath = '/protected-photos/' . $gallery->id . '/_watermarked/' . $cleanFilename;
                if (!file_exists($path) && file_exists($sourcePath)) {
                    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
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
