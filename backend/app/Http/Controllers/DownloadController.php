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

        // FIX: Korrekter String für den Trim-Befehl ohne Syntax-Fehler
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

        // 1. Rechte prüfen
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
            'item_identifier' => $photo->filename,
            'user_agent' => $request->userAgent()
        ]);

        // 2. Skalieren & Wasserzeichen-Prüfung
        $processor = app(\App\Services\ImageProcessor::class);
        $maxWidth = ['web' => 2560, 'print' => 4000, 'original' => null][$tier] ?? null;
        
        $tempDir = storage_path('app/private/temp');
        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
        
        $workingPath = $tempDir . '/' . uniqid('scale_') . '.jpg';

        // Wenn nicht Full-Access und nicht bezahlt/in Flatrate, gäbe es ein Wasserzeichen.
        // Da wir den Zugang oben aber blockieren, falls nicht lizenziert, müssen wir hier 
        // kein Wasserzeichen mehr rendern - der Download ist ja legal!
        $processor->scaleImage($sourcePath, $workingPath, $maxWidth);

        // 3. Metadaten Injizieren
        $processedPath = $this->injectMetadata($workingPath, $photo, $userName);
        
        // Cleanup des temporären Skalierungs-Files, falls Injektion ein neues erstellt hat
        if ($workingPath !== $processedPath && file_exists($workingPath)) @unlink($workingPath);

        $downloadName = pathinfo($photo->filename, PATHINFO_FILENAME) . '_' . $tier . '.jpg';
        return response()->download($processedPath, $downloadName)->deleteFileAfterSend(true);
    }

    public function downloadZip(Request $request, $galleryId)
    {
        $gallery = Gallery::with('photos')->findOrFail($galleryId);
        $user = $this->authorizeGalleryAccess($gallery);

        $baseStoragePath = rtrim(\Illuminate\Support\Facades\Storage::disk('photos')->path(''), '/');
        $userName = $user ? $user->name : 'Gast';

        DownloadLog::create([
            'user_id' => $user ? $user->id : null,
            'user_name_snapshot' => $userName,
            'gallery_id' => $gallery->id,
            'gallery_name_snapshot' => $gallery->name,
            'item_type' => 'full_zip',
            'item_identifier' => $gallery->slug . '.zip',
            'user_agent' => $request->userAgent()
        ]);

        return response()->streamDownload(function () use ($gallery, $baseStoragePath, $userName, $user) {
            $zip = new ZipStream(sendHttpHeaders: false);
            $watermarkService = app(WatermarkService::class);

            foreach ($gallery->photos as $photo) {
                $sourcePath = $baseStoragePath . '/' . $gallery->id . '/' . $photo->filename;
                if (!file_exists($sourcePath))
                    continue;

                $hasFullAccess = $user && ($user->is_admin || $user->canAccessGallery($gallery->id));
        if (!$hasFullAccess) {
            $wmPath = $baseStoragePath . '/' . $gallery->id . '/_watermarked/' . $photo->filename;
                    if (!file_exists($wmPath)) {
                        if (!is_dir(dirname($wmPath)))
                            mkdir(dirname($wmPath), 0755, true);
                        $watermarkService->applyWatermark($sourcePath, $wmPath, 2000);
                    }
                    $sourcePath = $wmPath;
                }

                $processedPath = $this->injectMetadata($sourcePath, $photo, $userName);
                $zip->addFileFromPath($photo->filename, $processedPath);

                if ($processedPath !== $sourcePath) {
                    @unlink($processedPath);
                }
            }

            $zip->finish();
        }, $gallery->slug . '.zip');
    }


    public function downloadOrderZip(Request $request, $orderId)
    {
        $user = auth('api')->user();
        $order = \App\Models\Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->with('invoiceSnapshot')
            ->firstOrFail();

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
            'item_identifier' => 'order_' . $snapshot->invoice_number . '.zip',
            'user_agent' => $request->userAgent()
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
                $workingPath = $tempDir . '/' . uniqid('scale_') . '.jpg';

                // Bild auf gekaufte Lizenz-Größe herunterskalieren (Ohne Wasserzeichen, da lizenziert)
                $processor->scaleImage($sourcePath, $workingPath, $maxWidth);
                
                // Metadaten injizieren
                $processedPath = $this->injectMetadata($workingPath, $photo, $userName);

                // Suffix für eindeutige Identifikation anhängen
                $nameInfo = pathinfo($photo->filename);
                $downloadName = $nameInfo['filename'] . '_' . strtoupper($tier) . '.jpg';

                $zip->addFileFromPath($downloadName, $processedPath);

                if ($processedPath !== $sourcePath && file_exists($processedPath)) @unlink($processedPath);
                if ($workingPath !== $sourcePath && file_exists($workingPath)) @unlink($workingPath);
            }

            $zip->finish();
        }, 'Order_' . $snapshot->invoice_number . '.zip');
    }
}