<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\DownloadLog;
use ZipStream\ZipStream;
use Illuminate\Support\Facades\Log;
use App\Services\WatermarkService;
use Symfony\Component\Process\Process;

class DownloadController extends Controller
{
    private function authorizeGalleryAccess($gallery)
    {
        $user = auth('api')->user();
        $isExpired = $gallery->expires_at && \Carbon\Carbon::parse($gallery->expires_at)->isPast();
        $canManage = $user && ($user->is_admin || ($user->is_photographer && $user->canAccessGallery($gallery->id)));

        if ($isExpired && !$canManage) {
            abort(403, 'Galerie abgelaufen.');
        }

        if (!$gallery->is_public) {
            if (!$user)
                abort(401, 'Unauthorized access to this gallery.');
            if (!$user->canAccessGallery($gallery->id)) {
                abort(403, 'Unauthorized access to this gallery.');
            }
        }
        return $user;
    }

    private function injectMetadata($sourcePath, $photo, $userName)
    {
        $tempDir = storage_path('app/private/temp');
        if (!is_dir($tempDir))
            mkdir($tempDir, 0755, true);

        $tempPath = $tempDir . '/' . uniqid('dl_') . '.jpg';
        copy($sourcePath, $tempPath);

        $artist = trim($photo->artist ?? config('app.name', 'Reisinger Foto Portal'), "\"\'");
        $copyright = 'Copyright ' . date('Y') . ' ' . $artist;
        $instructions = 'Licensed to / Downloaded by: ' . $userName;
        $agbUrl = 'https://reisinger.pictures/agb';

        $args = [
            'exiftool',
            '-overwrite_original',
            '-q',
            '-m',
            '-charset',
            'utf8',
            '-charset',
            'iptc=utf8',
            '-charset',
            'exif=utf8',
            '-IPTC:CodedCharacterSet=utf8'
        ];

        if (!empty($photo->title)) { $args[] = "-ObjectName={$photo->title}"; $args[] = "-XPTitle={$photo->title}"; }
        if (!empty($photo->description)) { $args[] = "-Caption-Abstract={$photo->description}"; $args[] = "-ImageDescription={$photo->description}"; }
        if (!empty($photo->keywords)) { $args[] = "-Keywords={$photo->keywords}"; }
        if (!empty($photo->location)) { $args[] = "-Sub-location={$photo->location}"; }
        if (!empty($photo->city)) { $args[] = "-City={$photo->city}"; }
        if (!empty($photo->state)) { $args[] = "-Province-State={$photo->state}"; }
        if (!empty($photo->country)) { $args[] = "-Country-PrimaryLocationName={$photo->country}"; }
        if (!empty($photo->iso_country)) { $args[] = "-Country-PrimaryLocationCode={$photo->iso_country}"; }

        array_push(
            $args,
            "-Artist={$artist}",
            "-By-line={$artist}",
            "-Copyright={$copyright}",
            "-CopyrightNotice={$copyright}",
            "-SpecialInstructions={$instructions}",
            "-UsageTerms={$agbUrl}",
            "-Rights={$agbUrl}",
            $tempPath
        );

        $process = new Process($args);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error("ExifTool failed on {$sourcePath}: " . $process->getErrorOutput());
            @unlink($tempPath);
            return $sourcePath;
        }

        return $tempPath;
    }

    public function downloadSingle(Request $request, $photoId)
    {
        $photo = Photo::with('gallery')->findOrFail($photoId);
        $gallery = $photo->gallery;
        $user = $this->authorizeGalleryAccess($gallery);

        $tier = $request->query('tier', 'original');
        $ranks = ['none' => 0, 'web' => 1, 'print' => 2, 'original' => 3];
        $userRank = $user && $user->flatrate_level ? ($ranks[$user->flatrate_level] ?? 0) : 0;
        $reqRank = $ranks[$tier] ?? 3;

        $hasFullAccess = $user && ($user->is_admin || $user->is_photographer);
        $isCoveredByFlatrate = $userRank >= $reqRank;
        $hasPurchased = $user && $user->hasPurchasedPhoto($photo->id, $tier);

        if (!$hasFullAccess && !$isCoveredByFlatrate && !$hasPurchased && !$gallery->effective_is_free_download) {
            abort(403, 'Sie besitzen keine gültige Lizenz für diese Bildauflösung ('.$tier.').');
        }

        $baseStoragePath = rtrim(\Illuminate\Support\Facades\Storage::disk('photos')->path(''), '/');
        $sourcePath = $baseStoragePath . '/' . $gallery->id . '/' . $photo->filename;

        if (!file_exists($sourcePath)) abort(404, 'Datei nicht gefunden');

        $userName = $user ? $user->name : 'Gast';

        DownloadLog::create([
            'user_id' => $user ? $user->id : null,
            'user_name_snapshot' => $userName,
            'gallery_id' => $gallery->id,
            'gallery_name_snapshot' => $gallery->name,
            'item_type' => 'single_image',
            'resolution_tier' => $tier,
            'user_agent' => $request->userAgent()
        ]);

        $processor = app(\App\Services\ImageProcessor::class);
        $maxWidth = ['web' => 2560, 'print' => 4000, 'original' => null][$tier] ?? null;

        $tempDir = storage_path('app/private/temp');
        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

        $scaledBase = $tempDir . '/base_scale_' . $photo->id . '_' . $tier . '.jpg';
        $lockKey = 'scale_' . $photo->id . '_' . $tier;

        \Illuminate\Support\Facades\Cache::lock($lockKey, 60)->block(30, function () use ($processor, $sourcePath, $scaledBase, $maxWidth) {
            if (!file_exists($scaledBase)) {
                $processor->scaleImage($sourcePath, $scaledBase, $maxWidth);
            }
        });

        $workingPath = $tempDir . '/' . uniqid('inject_') . '.jpg';
        copy($scaledBase, $workingPath);
        $processedPath = $this->injectMetadata($workingPath, $photo, $userName);

        if ($workingPath !== $processedPath && file_exists($workingPath)) @unlink($workingPath);

        $downloadName = $photo->id . '_' . $tier . '.jpg';
        return response()->download($processedPath, $downloadName)->deleteFileAfterSend(true);
    }

    public function downloadZip(Request $request, $galleryId)
    {
        $gallery = Gallery::with('photos')->findOrFail($galleryId);
        $user = $this->authorizeGalleryAccess($gallery);

        $tier = $request->query('tier', 'original');
        $ranks = ['none' => 0, 'web' => 1, 'print' => 2, 'original' => 3];
        $userRank = $user && $user->flatrate_level ? ($ranks[$user->flatrate_level] ?? 0) : 0;
        $reqRank = $ranks[$tier] ?? 3;

        $hasFullAccess = $user && ($user->is_admin || $user->is_photographer);
        $isCoveredByFlatrate = $userRank >= $reqRank;

        if (!$hasFullAccess && !$isCoveredByFlatrate && !$gallery->effective_is_free_download) {
            abort(403, 'Sie besitzen keine gültige Lizenz für diese Bildauflösung ('.$tier.') im ZIP-Download.');
        }

        $baseStoragePath = rtrim(\Illuminate\Support\Facades\Storage::disk('photos')->path(''), '/');
        $userName = $user ? $user->name : 'Gast';
        $photoCount = $gallery->photos()->count();

        DownloadLog::create([
            'user_id' => $user ? $user->id : null,
            'user_name_snapshot' => $userName,
            'gallery_id' => $gallery->id,
            'gallery_name_snapshot' => $gallery->name,
            'item_type' => 'full_zip',
            'resolution_tier' => $tier,
            'user_agent' => $request->userAgent(),
            'payload' => ['photo_count' => $photoCount]
        ]);

        return response()->streamDownload(function () use ($gallery, $baseStoragePath, $userName, $user, $tier, $hasFullAccess) {
            $zip = new ZipStream(sendHttpHeaders: false);
            $watermarkService = app(WatermarkService::class);
            $processor = app(\App\Services\ImageProcessor::class);
            $maxWidth = ['web' => 2560, 'print' => 4000, 'original' => null][$tier] ?? null;

            $tempDir = storage_path('app/private/temp');
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

            foreach ($gallery->photos as $photo) {
                $sourcePath = $baseStoragePath . '/' . $gallery->id . '/' . $photo->filename;
                if (!file_exists($sourcePath))
                    continue;
                if (!$hasFullAccess && !$gallery->effective_is_free_download) {
                    $wmPath = $baseStoragePath . '/' . $gallery->id . '/_watermarked/' . $photo->filename;
                    if (!file_exists($wmPath)) {
                        if (!is_dir(dirname($wmPath)))
                            mkdir(dirname($wmPath), 0755, true);
                        $watermarkService->applyWatermark($sourcePath, $wmPath, 2000);
                    }
                    $sourcePath = $wmPath;
                }

                $scaledBase = $tempDir . '/base_scale_' . $photo->id . '_' . $tier . '.jpg';
                $lockKey = 'scale_' . $photo->id . '_' . $tier;

                \Illuminate\Support\Facades\Cache::lock($lockKey, 60)->block(30, function () use ($processor, $sourcePath, $scaledBase, $maxWidth) {
                    if (!file_exists($scaledBase)) {
                        $processor->scaleImage($sourcePath, $scaledBase, $maxWidth);
                    }
                });

                $workingPath = $tempDir . '/' . uniqid('inject_') . '.jpg';
                copy($scaledBase, $workingPath);

                $processedPath = $this->injectMetadata($workingPath, $photo, $userName);
                $downloadName = $photo->id . '_' . strtoupper($tier) . '.jpg';
                
                $zip->addFileFromPath($downloadName, $processedPath);

                if ($processedPath !== $sourcePath && file_exists($processedPath)) @unlink($processedPath);
                if ($workingPath !== $sourcePath && file_exists($workingPath)) @unlink($workingPath);
            }

            $zip->finish();
        }, $gallery->slug . '_' . $tier . '.zip');
    }


    public function downloadOrderZip(Request $request, $orderId)
    {
        $user = auth('api')->user();
        $order = \App\Models\Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->with('invoiceSnapshot')
            ->firstOrFail();

        if ($order->is_quote_request && $order->status === 'pending') abort(403, 'Angebot noch nicht abgerechnet.');
        $snapshot = $order->invoiceSnapshot;
        if (!$snapshot || empty($snapshot->customer_details['items'])) {
            abort(404, 'Keine Bilder in dieser Bestellung gefunden.');
        }

        $baseStoragePath = rtrim(\Illuminate\Support\Facades\Storage::disk('photos')->path(''), '/');
        $userName = $user ? $user->name : 'Kunde';
        $processor = app(\App\Services\ImageProcessor::class);

        DownloadLog::create([
            'user_id' => $user->id,
            'user_name_snapshot' => $userName,
            'gallery_id' => null,
            'gallery_name_snapshot' => 'Order ' . $snapshot->invoice_number,
            'item_type' => 'full_zip',
            'user_agent' => $request->userAgent(),
            'payload' => ['photo_count' => count($snapshot->customer_details['items'])]
        ]);

        return response()->streamDownload(function () use ($snapshot, $baseStoragePath, $userName, $processor) {
            $zip = new ZipStream(sendHttpHeaders: false);
            $tempDir = storage_path('app/private/temp');
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

            $items = $snapshot->customer_details['items'];
            foreach ($items as $item) {
                $photoId = $item['photoId'] ?? null;
                $tier = $item['tier'] ?? 'original';
                if (!$photoId) continue;

                $photo = \App\Models\Photo::with('gallery')->find($photoId);
                if (!$photo) continue;

                $sourcePath = $baseStoragePath . '/' . $photo->gallery_id . '/' . $photo->filename;
                if (!file_exists($sourcePath)) continue;

                $maxWidth = ['web' => 2560, 'print' => 4000, 'original' => null][$tier] ?? null;
                $scaledBase = $tempDir . '/base_scale_' . $photo->id . '_' . $tier . '.jpg';
                $lockKey = 'scale_' . $photo->id . '_' . $tier;

                \Illuminate\Support\Facades\Cache::lock($lockKey, 60)->block(30, function () use ($processor, $sourcePath, $scaledBase, $maxWidth) {
                    if (!file_exists($scaledBase)) {
                        $processor->scaleImage($sourcePath, $scaledBase, $maxWidth);
                    }
                });

                $workingPath = $tempDir . '/' . uniqid('inject_') . '.jpg';
                copy($scaledBase, $workingPath);
                $processedPath = $this->injectMetadata($workingPath, $photo, $userName);

                $downloadName = $photo->id . '_' . strtoupper($tier) . '.jpg';

                $zip->addFileFromPath($downloadName, $processedPath);

                if ($processedPath !== $sourcePath && file_exists($processedPath)) @unlink($processedPath);
                if ($workingPath !== $sourcePath && file_exists($workingPath)) @unlink($workingPath);
            }

            $zip->finish();
        }, 'Order_' . $snapshot->invoice_number . '.zip');
    }
}
