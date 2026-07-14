<?php

namespace App\Http\Controllers;

use App\Constants\TierRanks;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\Photo;
use App\Services\ImageProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use ZipStream\ZipStream;

class PhotoDownloadController extends Controller
{
    public function __construct(
        private readonly ImageProcessor $imageProcessor,
    ) {}

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

    private function sanitizeExifValue($value)
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value ?? '');
    }

    private function injectMetadata($sourcePath, $photo, $userName, ?string $customConditions = null)
    {
        $tempDir = storage_path('app/private/temp');
        if (!is_dir($tempDir))
            mkdir($tempDir, 0755, true);

        $tempPath = $tempDir . '/' . uniqid('dl_') . '.jpg';

        $artist = $this->sanitizeExifValue(trim($photo->artist ?? config('app.name', 'Reisinger Foto Portal'), "\"\'"));
        $copyright = 'Copyright ' . date('Y') . ' ' . $artist;
        $editorialNotice = ($photo->effective_is_editorial_only || $photo->is_editorial_only) ? ' - EDITORIAL USE ONLY / NUR FÜR REDAKTIONELLE NUTZUNG FREIGEGEBEN' : '';
        $agbUrl = 'https://reisinger.pictures/agb';

        if ($customConditions !== null) {
            $ccSanitized = $this->sanitizeExifValue($customConditions);
            $instructions = $this->sanitizeExifValue('Licensed to / Downloaded by: ' . $userName . $editorialNotice);
            $usageTerms = $ccSanitized;
            $rights = $ccSanitized;
        } else {
            $instructions = $this->sanitizeExifValue('Licensed to / Downloaded by: ' . $userName . $editorialNotice);
            $usageTerms = $agbUrl;
            $rights = $agbUrl;
        }

        $title = $this->sanitizeExifValue($photo->title ?? '');
        $description = $this->sanitizeExifValue($photo->description ?? '');
        $keywords = $this->sanitizeExifValue($photo->keywords ?? '');
        $location = $this->sanitizeExifValue($photo->location ?? '');
        $city = $this->sanitizeExifValue($photo->city ?? '');
        $state = $this->sanitizeExifValue($photo->state ?? '');
        $country = $this->sanitizeExifValue($photo->country ?? '');
        $iso_country = $this->sanitizeExifValue($photo->iso_country ?? '');

        $args = [
            'exiftool',
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

        if (!empty($title)) {
            $args[] = "-ObjectName={$title}";
            $args[] = "-XPTitle={$title}";
        }
        if (!empty($description)) {
            $args[] = "-Caption-Abstract={$description}";
            $args[] = "-ImageDescription={$description}";
        }
        if (!empty($keywords)) {
            $args[] = "-Keywords={$keywords}";
        }
        if (!empty($location)) {
            $args[] = "-Sub-location={$location}";
        }
        if (!empty($city)) {
            $args[] = "-City={$city}";
        }
        if (!empty($state)) {
            $args[] = "-Province-State={$state}";
        }
        if (!empty($country)) {
            $args[] = "-Country-PrimaryLocationName={$country}";
        }
        if (!empty($iso_country)) {
            $args[] = "-Country-PrimaryLocationCode={$iso_country}";
        }

        array_push(
            $args,
            "-Artist={$artist}",
            "-By-line={$artist}",
            "-Copyright={$copyright}",
            "-CopyrightNotice={$copyright}",
            "-SpecialInstructions={$instructions}",
            "-UsageTerms={$usageTerms}",
            "-Rights={$rights}",
            '-o', $tempPath,
            $sourcePath
        );

        $process = new Process($args);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error("ExifTool failed on {$sourcePath}: " . $process->getErrorOutput());
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            return $sourcePath;
        }

        return $tempPath;
    }

    public function downloadSingle(Request $request, $id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $gallery = $photo->gallery;
        $user = $this->authorizeGalleryAccess($gallery);

        $tier = $request->query('tier', 'original');
        $userRank = $user && $user->flatrate_level ? (TierRanks::RANKS[$user->flatrate_level] ?? 0) : 0;
        $reqRank = TierRanks::RANKS[$tier] ?? 3;

        $hasFullAccess = $user && ($user->is_admin || $user->is_photographer);
        $isCoveredByFlatrate = $userRank >= $reqRank;
        $hasPurchased = $user && $user->hasPurchasedPhoto($photo->id, $tier);

        if (!$hasFullAccess && !$isCoveredByFlatrate && !$hasPurchased && !$gallery->effective_is_free_download) {
            abort(403, 'Sie besitzen keine gültige Lizenz für diese Bildauflösung (' . $tier . ').');
        }

        $baseStoragePath = rtrim(\Illuminate\Support\Facades\Storage::disk('photos')->path(''), '/\\');
        $sourcePath = $baseStoragePath . '/' . $gallery->id . '/' . $photo->filename;

        if (!file_exists($sourcePath)) {
            Log::error("Download 404: Datei auf Disk nicht gefunden.", ['path' => $sourcePath, 'photo_id' => $photo->id]);
            abort(404, 'Datei nicht gefunden oder noch nicht verarbeitet.');
        }

        $userName = $user ? $user->name : 'Gast';

        DownloadLog::create([
            'user_id' => $user && $user->id ? $user->id : null,
            'guest_id' => $user && $user->guest_id ? $user->guest_id : null,
            'user_name_snapshot' => $userName,
            'gallery_id' => $gallery->id,
            'gallery_name_snapshot' => $gallery->name,
            'item_type' => 'single_image',
            'resolution_tier' => $tier,
            'user_agent' => $request->userAgent()
        ]);

        $maxWidth = ['web' => 2560, 'print' => 4000, 'original' => null][$tier] ?? null;

        $tempDir = storage_path('app/private/temp');
        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

        $scaledBase = $tempDir . '/base_scale_' . $photo->id . '_' . $tier . '.jpg';
        $lockKey = 'scale_' . $photo->id . '_' . $tier;

        \Illuminate\Support\Facades\Cache::lock($lockKey, 60)->block(30, function () use ($sourcePath, $scaledBase, $maxWidth) {
            if (!file_exists($scaledBase)) {
                $this->imageProcessor->scaleImage($sourcePath, $scaledBase, $maxWidth);
            }
        });

        register_shutdown_function(function () use ($scaledBase) {
            if (file_exists($scaledBase)) {
                @unlink($scaledBase);
            }
        });

        $processedPath = $this->injectMetadata($scaledBase, $photo, $userName);

        $downloadName = $photo->id . '_' . $tier . '.jpg';
        return response()->download($processedPath, $downloadName)->deleteFileAfterSend(true);
    }

    public function downloadZip(Request $request, $galleryId)
    {
        $gallery = Gallery::with('photos')->findOrFail($galleryId);
        $user = $this->authorizeGalleryAccess($gallery);

        $tier = $request->query('tier', 'original');
        $userRank = $user && $user->flatrate_level ? (TierRanks::RANKS[$user->flatrate_level] ?? 0) : 0;
        $reqRank = TierRanks::RANKS[$tier] ?? 3;

        $hasFullAccess = $user && ($user->is_admin || $user->is_photographer);
        $isCoveredByFlatrate = $userRank >= $reqRank;

        if (!$hasFullAccess && !$isCoveredByFlatrate && !$gallery->effective_is_free_download) {
            abort(403, 'Sie besitzen keine gültige Lizenz für diese Bildauflösung (' . $tier . ') im ZIP-Download.');
        }

        $baseStoragePath = rtrim(\Illuminate\Support\Facades\Storage::disk('photos')->path(''), '/\\');
        $userName = $user ? $user->name : 'Gast';
        $photoCount = $gallery->photos()->count();

        DownloadLog::create([
            'user_id' => $user && $user->id ? $user->id : null,
            'guest_id' => $user && $user->guest_id ? $user->guest_id : null,
            'user_name_snapshot' => $userName,
            'gallery_id' => $gallery->id,
            'gallery_name_snapshot' => $gallery->name,
            'item_type' => 'full_zip',
            'resolution_tier' => $tier,
            'user_agent' => $request->userAgent(),
            'payload' => ['photo_count' => $photoCount],
            'photo_count' => $photoCount
        ]);

        return response()->streamDownload(function () use ($gallery, $baseStoragePath, $userName, $user, $tier, $hasFullAccess) {
            $zip = new ZipStream(sendHttpHeaders: false);
            $maxWidth = ['web' => 2560, 'print' => 4000, 'original' => null][$tier] ?? null;

            $tempDir = storage_path('app/private/temp');
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

            $tempFiles = [];

            foreach ($gallery->photos as $photo) {
                $sourcePath = $baseStoragePath . '/' . $gallery->id . '/' . $photo->filename;
                if (!file_exists($sourcePath))
                    continue;
                if (!$hasFullAccess && !$gallery->effective_is_free_download) {
                    $wmPath = $baseStoragePath . '/' . $gallery->id . '/_watermarked/' . $photo->filename;
                    if (!file_exists($wmPath)) {
                        if (!is_dir(dirname($wmPath)))
                            mkdir(dirname($wmPath), 0755, true);
                        $this->imageProcessor->applyCenteredWatermark($sourcePath, $wmPath, 2000);
                    }
                    $sourcePath = $wmPath;
                }

                $scaledBase = $tempDir . '/base_scale_' . $photo->id . '_' . $tier . '.jpg';
                $tempFiles[] = $scaledBase;
                $lockKey = 'scale_' . $photo->id . '_' . $tier;

                \Illuminate\Support\Facades\Cache::lock($lockKey, 60)->block(30, function () use ($sourcePath, $scaledBase, $maxWidth) {
                    if (!file_exists($scaledBase)) {
                        $this->imageProcessor->scaleImage($sourcePath, $scaledBase, $maxWidth);
                    }
                });

                $processedPath = $this->injectMetadata($scaledBase, $photo, $userName);
                $downloadName = $photo->id . '_' . strtoupper($tier) . '.jpg';

                $zip->addFileFromPath($downloadName, $processedPath);

                if ($processedPath !== $sourcePath && file_exists($processedPath)) @unlink($processedPath);
            }

            $zip->finish();

            foreach (array_unique($tempFiles) as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
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
        if (in_array($order->status, ['disputed', 'refunded', 'cancelled'])) abort(403, 'Zugriff aufgrund des Bestellstatus gesperrt.');
        $snapshot = $order->invoiceSnapshot;
        if (!$snapshot || empty($snapshot->customer_details['items'])) {
            abort(404, 'Keine Bilder in dieser Bestellung gefunden.');
        }

        $baseStoragePath = rtrim(\Illuminate\Support\Facades\Storage::disk('photos')->path(''), '/\\');
        $userName = $user ? $user->name : 'Kunde';

        DownloadLog::create([
            'user_id' => $user->id,
            'user_name_snapshot' => $userName,
            'gallery_id' => null,
            'gallery_name_snapshot' => 'Order ' . $snapshot->invoice_number,
            'item_type' => 'full_zip',
            'user_agent' => $request->userAgent(),
            'payload' => ['photo_count' => count($snapshot->customer_details['items'])],
            'photo_count' => count($snapshot->customer_details['items'])
        ]);

        return response()->streamDownload(function () use ($snapshot, $baseStoragePath, $userName) {
            $zip = new ZipStream(sendHttpHeaders: false);
            $tempDir = storage_path('app/private/temp');
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

            $tempFiles = [];

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
                $tempFiles[] = $scaledBase;
                $lockKey = 'scale_' . $photo->id . '_' . $tier;

                \Illuminate\Support\Facades\Cache::lock($lockKey, 60)->block(30, function () use ($sourcePath, $scaledBase, $maxWidth) {
                    if (!file_exists($scaledBase)) {
                        $this->imageProcessor->scaleImage($sourcePath, $scaledBase, $maxWidth);
                    }
                });

                $customConditions = $snapshot->customer_details['custom_conditions'] ?? null;
                $processedPath = $this->injectMetadata($scaledBase, $photo, $userName, $customConditions);

                $downloadName = $photo->id . '_' . strtoupper($tier) . '.jpg';

                $zip->addFileFromPath($downloadName, $processedPath);

                if ($processedPath !== $sourcePath && file_exists($processedPath)) @unlink($processedPath);
            }

            $zip->finish();

            foreach (array_unique($tempFiles) as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }, 'Order_' . $snapshot->invoice_number . '.zip');
    }
}
