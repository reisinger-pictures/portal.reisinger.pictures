<?php

namespace App\Http\Controllers;

use App\Constants\TierRanks;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\ImageProcessor;
use App\Services\MediaVisibilityService;
use App\Services\PurchaseService;
use App\Support\ActorIdentity;
use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use ZipStream\ZipStream;

class PhotoDownloadController extends Controller
{
    public function __construct(
        private readonly ImageProcessor $imageProcessor,
        private readonly MediaVisibilityService $mediaVisibility,
    ) {}

    /**
     * Allowed download tiers mapped to their maximum width (null = original).
     * Anything outside this whitelist is rejected with 422 — an unknown tier
     * must never silently fall back to the highest resolution.
     */
    private const DOWNLOAD_TIERS = ['web' => 2560, 'print' => 4000, 'original' => null];

    /**
     * Keep audit payloads useful for reconciliation without allowing an
     * unbounded order/archive request to turn the JSON column into a data dump.
     */
    private const MAX_AUDIT_IDS = 500;

    /**
     * Resolve a requested download tier to its maximum width, rejecting unknown
     * tiers instead of treating them as "original".
     */
    private function resolveDownloadTier(string $tier): ?int
    {
        if (! array_key_exists($tier, self::DOWNLOAD_TIERS)) {
            abort(422, 'Unbekannter Auflösungs-Tier.');
        }

        return self::DOWNLOAD_TIERS[$tier];
    }

    /**
     * @return array{0: Gallery, 1: ?User}
     */
    private function authorizeGalleryAccess(Gallery|string $gallery): array
    {
        $galleryId = $gallery instanceof Gallery ? $gallery->getKey() : trim($gallery);
        $current = $this->mediaVisibility->requireVisibleGallery($galleryId);
        $user = $this->currentActorForAuthorization();
        $svc = app(AuthorizationService::class);

        if (! BrandRegistry::galleryTreeMatchesCurrent($current)) {
            abort(404, 'Gallery not found.');
        }

        $isExpired = $current->expires_at && Carbon::parse($current->expires_at)->isPast();
        // Brand-aware management check (a brand-bound admin/photographer from a
        // foreign brand must not manage or bypass watermarking on this gallery).
        $canManage = $user && $svc->canManageGallery($user, $current->id);

        if ($isExpired && ! $canManage) {
            abort(403, 'Galerie abgelaufen.');
        }

        if (! $current->effective_is_public) {
            if (! $user) {
                abort(401, 'Unauthorized access to this gallery.');
            }
            if (! $svc->canAccessGallery($user, $current->id)) {
                abort(403, 'Unauthorized access to this gallery.');
            }
        }

        return [$current, $user];
    }

    private function currentActorForAuthorization(?User $actor = null): ?User
    {
        $actor ??= auth('api')->user();
        if (! $actor instanceof User) {
            return null;
        }

        $registeredId = ActorIdentity::registeredId($actor);
        if ($registeredId === null) {
            return $actor;
        }

        $current = User::query()->find($registeredId);
        if (! $current instanceof User) {
            abort(401, 'Unauthorized access to this gallery.');
        }

        return $current;
    }

    private function requireCurrentActorForAuthorization(?User $actor = null): User
    {
        $current = $this->currentActorForAuthorization($actor);
        if (! $current instanceof User) {
            abort(401, 'Unauthorized access to this order.');
        }

        return $current;
    }

    private function assertGalleryIsDownloadable(Gallery $gallery): void
    {
        if ($gallery->isSelection()) {
            abort(403, 'Auswahl-Galerien erlauben keinen Download.');
        }
    }

    private function sanitizeExifValue($value)
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value ?? '');
    }

    protected function injectMetadata($sourcePath, $photo, $userName, ?string $customConditions = null)
    {
        $tempDir = storage_path('app/private/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempPath = $tempDir.'/'.uniqid('dl_').'.jpg';

        $artist = $this->sanitizeExifValue(trim($photo->artist ?? config('app.name', 'Reisinger Foto Portal'), "\"\'"));
        $copyright = 'Copyright '.date('Y').' '.$artist;
        $editorialNotice = ($photo->effective_is_editorial_only || $photo->is_editorial_only) ? ' - EDITORIAL USE ONLY / NUR FÜR REDAKTIONELLE NUTZUNG FREIGEGEBEN' : '';
        $agbUrl = 'https://reisinger.pictures/agb';

        if ($customConditions !== null) {
            $ccSanitized = $this->sanitizeExifValue($customConditions);
            $instructions = $this->sanitizeExifValue('Licensed to / Downloaded by: '.$userName.$editorialNotice);
            $usageTerms = $ccSanitized;
            $rights = $ccSanitized;
        } else {
            $instructions = $this->sanitizeExifValue('Licensed to / Downloaded by: '.$userName.$editorialNotice);
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
            '-IPTC:CodedCharacterSet=utf8',
        ];

        if (! empty($title)) {
            $args[] = "-ObjectName={$title}";
            $args[] = "-XPTitle={$title}";
        }
        if (! empty($description)) {
            $args[] = "-Caption-Abstract={$description}";
            $args[] = "-ImageDescription={$description}";
        }
        if (! empty($keywords)) {
            $args[] = "-Keywords={$keywords}";
        }
        if (! empty($location)) {
            $args[] = "-Sub-location={$location}";
        }
        if (! empty($city)) {
            $args[] = "-City={$city}";
        }
        if (! empty($state)) {
            $args[] = "-Province-State={$state}";
        }
        if (! empty($country)) {
            $args[] = "-Country-PrimaryLocationName={$country}";
        }
        if (! empty($iso_country)) {
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

        if (! $process->isSuccessful()) {
            Log::error("ExifTool failed on {$sourcePath}: ".$process->getErrorOutput());
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }

            return $sourcePath;
        }

        return $tempPath;
    }

    /**
     * Re-run every single-download boundary from current database state. This
     * method is intentionally used both before processing and after all
     * derivatives have been prepared.
     *
     * @return array{0: Photo, 1: Gallery, 2: ?User, 3: bool}
     */
    private function authorizeSingleDownload(string $photoId, string $tier): array
    {
        $this->resolveDownloadTier($tier);

        $photo = $this->mediaVisibility->requireVisiblePhoto($photoId);
        [$gallery, $user] = $this->authorizeGalleryAccess($photo->gallery);
        $this->assertGalleryIsDownloadable($gallery);
        $svc = app(AuthorizationService::class);

        $userRank = $user && $user->flatrate_level
            ? (TierRanks::RANKS[$user->flatrate_level] ?? 0)
            : 0;
        $requestedRank = TierRanks::RANKS[$tier];
        $hasFullAccess = (bool) ($user && $svc->canManageGallery($user, $gallery->id));
        $isCoveredByFlatrate = $userRank >= $requestedRank;
        $hasPurchased = (bool) ($user
            && app(PurchaseService::class)->hasPurchasedPhoto($user, $photo->id, $tier));

        if (! $hasFullAccess && ! $isCoveredByFlatrate && ! $hasPurchased && ! $gallery->effective_is_free_download) {
            abort(403, 'Sie besitzen keine gültige Lizenz für diese Bildauflösung ('.$tier.').');
        }

        return [$photo, $gallery, $user, $hasFullAccess];
    }

    /**
     * @return array{0: Gallery, 1: ?User, 2: bool}
     */
    private function authorizeGalleryZipDownload(string $galleryId, string $tier): array
    {
        $this->resolveDownloadTier($tier);

        [$gallery, $user] = $this->authorizeGalleryAccess($galleryId);
        $this->assertGalleryIsDownloadable($gallery);
        $svc = app(AuthorizationService::class);

        $userRank = $user && $user->flatrate_level
            ? (TierRanks::RANKS[$user->flatrate_level] ?? 0)
            : 0;
        $requestedRank = TierRanks::RANKS[$tier];
        $hasFullAccess = (bool) ($user && $svc->canManageGallery($user, $gallery->id));
        $isCoveredByFlatrate = $userRank >= $requestedRank;

        if (! $hasFullAccess && ! $isCoveredByFlatrate && ! $gallery->effective_is_free_download) {
            abort(403, 'Sie besitzen keine gültige Lizenz für diese Bildauflösung ('.$tier.') im ZIP-Download.');
        }

        return [$gallery, $user, $hasFullAccess];
    }

    public function downloadSingle(Request $request, $id)
    {
        $tier = $request->query('tier', 'original');
        $maxWidth = $this->resolveDownloadTier($tier);
        [$photo, $gallery, $user] = $this->authorizeSingleDownload((string) $id, $tier);
        $baseStoragePath = rtrim(Storage::disk('photos')->path(''), '/\\');
        $sourcePath = $baseStoragePath.'/'.$gallery->id.'/'.$photo->filename;

        if (! file_exists($sourcePath)) {
            Log::error('Download 404: Datei auf Disk nicht gefunden.', ['path' => $sourcePath, 'photo_id' => $photo->id]);
            abort(404, 'Datei nicht gefunden oder noch nicht verarbeitet.');
        }

        $tempDir = storage_path('app/private/temp');
        if (! is_dir($tempDir) && ! @mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
            abort(500, 'Bildverarbeitung fehlgeschlagen.');
        }

        $scaledBase = $tempDir.'/base_scale_'.$photo->id.'_'.$tier.'.jpg';
        $cleanupFiles = [$scaledBase];
        $downloadLogId = null;

        try {
            $lockKey = 'scale_'.$photo->id.'_'.$tier;
            $scaleSucceeded = false;

            Cache::lock($lockKey, 60)->block(30, function () use ($sourcePath, $scaledBase, $maxWidth, &$scaleSucceeded) {
                if ($this->imageProcessor->isValidImageFile($scaledBase)) {
                    $scaleSucceeded = true;

                    return;
                }

                if (file_exists($scaledBase)) {
                    @unlink($scaledBase);
                }
                $scaleSucceeded = $this->imageProcessor->scaleImage($sourcePath, $scaledBase, $maxWidth);
            });

            if (! $scaleSucceeded || ! $this->imageProcessor->isValidImageFile($scaledBase)) {
                abort(500, 'Bildverarbeitung fehlgeschlagen.');
            }

            $processedPath = $this->injectMetadata($scaledBase, $photo, $user?->name ?? 'Gast');
            if ($processedPath === $sourcePath) {
                // The metadata helper falls back to its input path on failure;
                // because that input is the scaled temp file, retain the temp
                // path and never mark the persistent source for deletion.
                $processedPath = $scaledBase;
            } else {
                $cleanupFiles[] = $processedPath;
            }

            // This is the final authorization boundary after scaling and
            // metadata injection. Every mutable eligibility input is reloaded.
            [$currentPhoto, $currentGallery, $currentUser] = $this->authorizeSingleDownload(
                (string) $photo->getKey(),
                $tier,
            );
            if ((string) $currentPhoto->gallery_id !== (string) $gallery->id
                || (string) $currentPhoto->filename !== (string) $photo->filename
                || (string) $currentGallery->id !== (string) $gallery->id) {
                abort(404, 'Foto nicht gefunden.');
            }

            $downloadLog = DownloadLog::create([
                'user_id' => ActorIdentity::registeredId($currentUser),
                'guest_id' => ActorIdentity::guestId($currentUser),
                'user_name_snapshot' => $currentUser?->name ?? 'Gast',
                'gallery_id' => $currentGallery->id,
                'gallery_name_snapshot' => $currentGallery->name,
                'item_type' => 'single_image',
                'resolution_tier' => $tier,
                'user_agent' => $request->userAgent(),
                'payload' => ['photo_id' => (string) $currentPhoto->getKey()],
            ]);
            $downloadLogId = $downloadLog->getKey();

            $this->registerTempFileCleanup($cleanupFiles);
            $downloadName = $currentPhoto->id.'_'.$tier.'.jpg';

            return response()->download($processedPath, $downloadName)->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            if ($downloadLogId !== null) {
                DownloadLog::query()->whereKey($downloadLogId)->delete();
            }
            $this->removeTempFiles($cleanupFiles);

            throw $e;
        }
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function removeTempFiles(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function registerTempFileCleanup(array $paths): void
    {
        register_shutdown_function(function () use ($paths): void {
            $this->removeTempFiles($paths);
        });
    }

    public function downloadZip(Request $request, $galleryId)
    {
        $tier = $request->query('tier', 'original');
        $maxWidth = $this->resolveDownloadTier($tier);
        [$gallery, $user, $hasFullAccess] = $this->authorizeGalleryZipDownload((string) $galleryId, $tier);
        $gallery->load('photos');
        $galleryId = (string) $gallery->getKey();
        $baseStoragePath = rtrim(Storage::disk('photos')->path(''), '/\\');
        $preparedFiles = [];
        $downloadLogId = null;

        try {
            // Prepare every derivative before returning a streaming response. A
            // late authorization failure during processing therefore cannot
            // produce a partial archive or a successful audit record.
            $preparedFiles = $this->prepareGalleryZipFiles(
                $gallery,
                $tier,
                $maxWidth,
                $hasFullAccess,
                $baseStoragePath,
                $user?->name ?? 'Gast',
            );

            $photoIds = collect($preparedFiles)->pluck('photo_id')->filter()->unique()->values();
            $galleryIds = collect($preparedFiles)->pluck('gallery_id')->filter()->unique()->values();
            $photoCount = $photoIds->count();

            [$currentGallery, $currentUser] = $this->authorizeGalleryZipDownload($galleryId, $tier);
            $this->assertPreparedGalleryFilesCurrent($preparedFiles, $currentGallery);

            $downloadLog = DownloadLog::create([
                'user_id' => ActorIdentity::registeredId($currentUser),
                'guest_id' => ActorIdentity::guestId($currentUser),
                'user_name_snapshot' => $currentUser?->name ?? 'Gast',
                'gallery_id' => $currentGallery->id,
                'gallery_name_snapshot' => $currentGallery->name,
                'item_type' => 'full_zip',
                'resolution_tier' => $tier,
                'user_agent' => $request->userAgent(),
                'payload' => $this->buildZipAuditPayload($photoIds, $galleryIds, $photoCount),
                'photo_count' => $photoCount,
            ]);
            $downloadLogId = $downloadLog->getKey();
            $this->registerTempFileCleanup($this->preparedFilePaths($preparedFiles));

            return response()->streamDownload(function () use ($preparedFiles, $galleryId, $tier, $downloadLogId) {
                try {
                    // This is the last authorization check before ZipStream is
                    // allowed to write its first byte.
                    [$currentGallery] = $this->authorizeGalleryZipDownload($galleryId, $tier);
                    $this->assertPreparedGalleryFilesCurrent($preparedFiles, $currentGallery);

                    $zip = new ZipStream(sendHttpHeaders: false);
                    foreach ($preparedFiles as $file) {
                        $zip->addFileFromPath($file['download_name'], $file['processed_path']);
                    }
                    $zip->finish();
                } catch (\Throwable $e) {
                    DownloadLog::query()->whereKey($downloadLogId)->delete();

                    throw $e;
                } finally {
                    $this->removeTempFiles($this->preparedFilePaths($preparedFiles));
                }
            }, $currentGallery->slug.'_'.$tier.'.zip');
        } catch (HttpException $e) {
            if ($downloadLogId !== null) {
                DownloadLog::query()->whereKey($downloadLogId)->delete();
            }
            $this->removeTempFiles($this->preparedFilePaths($preparedFiles));

            return response()->json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            if ($downloadLogId !== null) {
                DownloadLog::query()->whereKey($downloadLogId)->delete();
            }
            $this->removeTempFiles($this->preparedFilePaths($preparedFiles));

            throw $e;
        }
    }

    /**
     * @param  array<int, array{photo_id:string, gallery_id:string, photo_filename:string, source_path:string, scaled_path:string, processed_path:string, download_name:string}>  $preparedFiles
     */
    private function assertPreparedGalleryFilesCurrent(array $preparedFiles, Gallery $gallery): void
    {
        foreach ($preparedFiles as $file) {
            $photo = $this->mediaVisibility->requireVisiblePhoto($file['photo_id']);
            if ((string) $photo->gallery_id !== (string) $gallery->id
                || (string) $file['gallery_id'] !== (string) $gallery->id
                || (string) $photo->filename !== (string) $file['photo_filename']) {
                abort(404, 'Foto nicht gefunden.');
            }
        }
    }

    /**
     * @param  array<int, array{photo_id:string, gallery_id:string, photo_filename:string, source_path:string, scaled_path:string, processed_path:string, download_name:string}>  $preparedFiles
     * @return array<int, string>
     */
    private function preparedFilePaths(array $preparedFiles): array
    {
        $paths = [];
        foreach ($preparedFiles as $file) {
            if ($file['processed_path'] !== $file['source_path']) {
                $paths[] = $file['processed_path'];
            }
            $paths[] = $file['scaled_path'];
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<int, array{photo_id:string, gallery_id:string, photo_filename:string, source_path:string, scaled_path:string, processed_path:string, download_name:string}>
     */
    private function prepareGalleryZipFiles(
        Gallery $gallery,
        string $tier,
        ?int $maxWidth,
        bool $hasFullAccess,
        string $baseStoragePath,
        string $userName,
    ): array {
        $tempDir = storage_path('app/private/temp');
        if (! is_dir($tempDir) && ! @mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
            abort(500, 'ZIP-Vorbereitung fehlgeschlagen.');
        }

        $prepared = [];
        $cleanupFiles = [];
        try {
            foreach ($gallery->photos as $photo) {
                $photo = $this->mediaVisibility->requireVisiblePhoto($photo->getKey());
                $sourcePath = $baseStoragePath.'/'.$gallery->id.'/'.$photo->filename;
                if (! is_file($sourcePath)) {
                    continue;
                }
                if (! $this->imageProcessor->isValidImageFile($sourcePath)) {
                    abort(500, 'Bildverarbeitung fehlgeschlagen.');
                }

                if (! $hasFullAccess && ! $gallery->effective_is_free_download) {
                    $sourcePath = $this->ensureWatermarkedDownloadFile(
                        $sourcePath,
                        $gallery,
                    );
                }

                $scaledPath = $tempDir.'/base_scale_'.$photo->id.'_'.$tier.'.jpg';
                $cleanupFiles[] = $scaledPath;
                $scaleSucceeded = false;
                Cache::lock('scale_'.$photo->id.'_'.$tier, 60)->block(30, function () use ($sourcePath, $scaledPath, $maxWidth, &$scaleSucceeded) {
                    // Do not reuse a deterministic temp file here: an older
                    // request may have left an unwatermarked derivative at the
                    // same path.  Re-scale from the verified source every time.
                    if (is_file($scaledPath)) {
                        @unlink($scaledPath);
                    }
                    $scaleSucceeded = $this->imageProcessor->scaleImage($sourcePath, $scaledPath, $maxWidth);
                });

                if (! $scaleSucceeded || ! $this->imageProcessor->isValidImageFile($scaledPath)) {
                    abort(500, 'Bildverarbeitung fehlgeschlagen.');
                }

                $processedPath = $this->injectMetadata($scaledPath, $photo, $userName);
                if ($processedPath !== $sourcePath) {
                    $cleanupFiles[] = $processedPath;
                }
                if (! $this->imageProcessor->isValidImageFile($processedPath)) {
                    abort(500, 'Bildverarbeitung fehlgeschlagen.');
                }

                $prepared[] = [
                    'photo_id' => (string) $photo->getKey(),
                    'gallery_id' => (string) $gallery->getKey(),
                    'photo_filename' => (string) $photo->filename,
                    'source_path' => $sourcePath,
                    'scaled_path' => $scaledPath,
                    'processed_path' => $processedPath,
                    'download_name' => $photo->id.'_'.strtoupper($tier).'.jpg',
                ];
            }
        } catch (\Throwable $e) {
            $this->removeTempFiles($cleanupFiles);

            throw $e;
        }

        return $prepared;
    }

    /**
     * Build the bounded, server-generated portion of a ZIP audit record.
     * The download itself is never limited by this cap; only the diagnostic
     * identifier lists are bounded so a large legacy order cannot create an
     * unbounded JSON payload.
     *
     * @param  iterable<mixed>  $photoIds
     * @param  iterable<mixed>  $galleryIds
     * @return array{photo_count:int, photo_ids:array<int, string>, gallery_ids:array<int, string>}
     */
    private function buildZipAuditPayload(iterable $photoIds, iterable $galleryIds, int $photoCount): array
    {
        return [
            'photo_count' => max(0, $photoCount),
            'photo_ids' => $this->boundedAuditIds($photoIds),
            'gallery_ids' => $this->boundedAuditIds($galleryIds),
        ];
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return array<int, string>
     */
    private function boundedAuditIds(iterable $ids): array
    {
        $bounded = [];
        $seen = [];

        foreach ($ids as $id) {
            if (! is_scalar($id)) {
                continue;
            }

            $id = trim((string) $id);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $bounded[] = $id;
            if (count($bounded) >= self::MAX_AUDIT_IDS) {
                break;
            }
        }

        return $bounded;
    }

    private function ensureWatermarkedDownloadFile(string $sourcePath, Gallery $gallery): string
    {
        $wmPath = dirname($sourcePath).'/_watermarked/'.basename($sourcePath);
        if (! $this->imageProcessor->watermarkAssetAvailable($sourcePath, $gallery->type, 2000)) {
            if (is_file($wmPath)) {
                @unlink($wmPath);
            }
            abort(500, 'SECURITY: Watermark-Fail.');
        }

        if ($this->imageProcessor->isSafeWatermarkedOutput($sourcePath, $wmPath, $gallery->type, 2000)) {
            return $wmPath;
        }

        if (! is_dir(dirname($wmPath)) && ! @mkdir(dirname($wmPath), 0755, true) && ! is_dir(dirname($wmPath))) {
            abort(500, 'SECURITY: Watermark-Fail.');
        }

        $generated = $this->imageProcessor->applyCenteredWatermark(
            $sourcePath,
            $wmPath,
            2000,
            $gallery->type,
        );
        if (! $generated || ! $this->imageProcessor->isSafeWatermarkedOutput($sourcePath, $wmPath, $gallery->type, 2000)) {
            if (is_file($wmPath)) {
                @unlink($wmPath);
            }
            abort(500, 'SECURITY: Watermark-Fail.');
        }

        return $wmPath;
    }

    /**
     * Validate every persisted order item before creating a download log or a
     * streaming response.  The order, invoice snapshot, media tree, and
     * purchaser access are all checked against current state here.
     *
     * @param  array<int, mixed>  $items
     * @return Collection<string, Photo>
     */
    private function authorizeOrderItemsForDownload(
        array $items,
        Order $order,
        InvoiceSnapshot $snapshot,
        ?User $actor,
    ): Collection {
        if ($items === []) {
            abort(404, 'Keine Bilder in dieser Bestellung gefunden.');
        }

        $this->assertOrderDownloadContext($order, $snapshot, $actor);

        $photoIds = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                abort(422, 'Ungültiger Bestellartikel.');
            }

            $photoId = $item['photoId'] ?? null;
            if (! is_string($photoId) || trim($photoId) === '') {
                abort(422, 'Ungültiger Bestellartikel.');
            }
            $photoIds[] = trim($photoId);

            $tier = $item['tier'] ?? 'original';
            if (! is_string($tier) || ! array_key_exists($tier, self::DOWNLOAD_TIERS)) {
                abort(422, 'Unbekannter Auflösungs-Tier.');
            }
        }

        $uniquePhotoIds = array_values(array_unique($photoIds));
        $photos = Photo::with('gallery')
            ->whereIn('id', $uniquePhotoIds)
            ->get()
            ->keyBy(fn (Photo $photo): string => (string) $photo->getKey());

        if ($photos->count() !== count($uniquePhotoIds)) {
            abort(404, 'Bild nicht gefunden.');
        }

        foreach ($items as $item) {
            $photoId = trim((string) $item['photoId']);
            $photo = $photos->get($photoId);
            if (! $photo instanceof Photo) {
                abort(404, 'Bild nicht gefunden.');
            }

            $this->assertOrderPhotoDeliverable($photo, (string) ($item['tier'] ?? 'original'), $actor);
        }

        return $photos;
    }

    /**
     * Re-check the order identity and the current host/brand context.  A
     * settled download requires a complete, non-null brand on both the order
     * and its immutable invoice snapshot; legacy/null-branded rows fail closed
     * instead of relying on a host fallback.
     */
    private function assertOrderDownloadContext(
        Order $order,
        InvoiceSnapshot $snapshot,
        ?User $actor,
    ): void {
        if (! $actor instanceof User || ActorIdentity::ownerKey($actor) === null) {
            abort(401, 'Unauthorized access to this order.');
        }
        if (! ActorIdentity::ownsOrder($order, $actor)) {
            abort(404, 'Bestellung nicht gefunden.');
        }
        if ((string) $snapshot->order_id !== (string) $order->getKey()) {
            abort(404, 'Bestellung nicht gefunden.');
        }
        if ($order->is_quote_request && $order->status === 'pending') {
            abort(403, 'Angebot noch nicht abgerechnet.');
        }
        if (! app(PurchaseService::class)->isOrderDownloadEligible($order)) {
            abort(403, 'Zugriff aufgrund des Bestellstatus gesperrt.');
        }

        $currentBrand = BrandRegistry::currentIdOrNull();
        if ($currentBrand === null) {
            abort(404, 'Bestellung nicht gefunden.');
        }

        $orderBrand = BrandRegistry::normalizeId($order->brand);
        $snapshotBrand = BrandRegistry::normalizeId($snapshot->brand);
        $actorBrand = BrandRegistry::normalizeId($actor->brand);

        if ($actorBrand !== null && $actorBrand !== $currentBrand) {
            abort(404, 'Bestellung nicht gefunden.');
        }
        if ($orderBrand === null || $snapshotBrand === null || $orderBrand !== $snapshotBrand) {
            abort(404, 'Bestellung nicht gefunden.');
        }
        if ($orderBrand !== $currentBrand || $snapshotBrand !== $currentBrand) {
            abort(404, 'Bestellung nicht gefunden.');
        }
    }

    /**
     * Validate one freshly loaded order item against current gallery state.
     * This method is called once for the complete preflight and again from the
     * stream immediately before each file is added.
     */
    private function assertOrderPhotoDeliverable(Photo $photo, string $tier, ?User $actor): void
    {
        if (! array_key_exists($tier, self::DOWNLOAD_TIERS)) {
            abort(422, 'Unbekannter Auflösungs-Tier.');
        }

        $photo = $this->mediaVisibility->requireVisiblePhoto($photo->getKey());
        $gallery = $photo->gallery;
        if (! $gallery instanceof Gallery) {
            abort(404, 'Bild nicht gefunden.');
        }
        if (! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
            abort(404, 'Bild nicht gefunden.');
        }
        if ($gallery->type !== 'delivery') {
            abort(403, 'Nur Liefergalerien erlauben einen ZIP-Download.');
        }
        if ($gallery->expires_at !== null && $gallery->expires_at->lessThanOrEqualTo(now())) {
            abort(403, 'Galerie abgelaufen.');
        }

        if (! $gallery->effective_is_public) {
            if (! $actor instanceof User || ActorIdentity::ownerKey($actor) === null) {
                abort(401, 'Unauthorized access to this gallery.');
            }
            if (! app(AuthorizationService::class)->canAccessGallery($actor, (string) $gallery->getKey())) {
                abort(403, 'Zugriff verweigert');
            }
        }
    }

    /**
     * A small, stable fingerprint lets the stream detect a snapshot replacement
     * after the preflight without trusting a stale Eloquent object.
     *
     * @param  array<int, mixed>  $items
     */
    private function orderItemsFingerprint(array $items, mixed $customConditions = null): string
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $normalized[] = [null, null];

                continue;
            }

            $photoId = $item['photoId'] ?? null;
            $tier = $item['tier'] ?? 'original';
            $normalized[] = [
                is_string($photoId) ? trim($photoId) : null,
                is_string($tier) ? $tier : null,
            ];
        }

        $normalizedConditions = $customConditions === null || is_string($customConditions)
            ? $customConditions
            : ['invalid' => true];

        return hash('sha256', (string) json_encode([
            'items' => $normalized,
            'custom_conditions' => $normalizedConditions,
        ]));
    }

    /**
     * @param  array<int, mixed>  $expectedItems
     * @return array{order: Order, snapshot: InvoiceSnapshot, items: array<int, mixed>, custom_conditions: ?string, photos: Collection<string, Photo>, actor: User}
     */
    private function loadCurrentOrderDownloadContext(
        string $orderId,
        string $invoiceNumber,
        string $fingerprint,
        ?User $actor,
    ): array {
        $currentActor = $this->requireCurrentActorForAuthorization($actor);
        $order = Order::query()
            ->ownedBy($currentActor)
            ->with('invoiceSnapshot')
            ->find($orderId);
        if (! $order instanceof Order) {
            abort(404, 'Bestellung nicht gefunden.');
        }

        // Keep status ahead of snapshot/media validation so a refund/dispute is
        // always reported as an authorization failure rather than a 404 caused
        // by concurrently removed settlement data.
        if ($order->is_quote_request && $order->status === 'pending') {
            abort(403, 'Angebot noch nicht abgerechnet.');
        }
        if (! app(PurchaseService::class)->isOrderDownloadEligible($order)) {
            abort(403, 'Zugriff aufgrund des Bestellstatus gesperrt.');
        }

        $snapshot = $order->invoiceSnapshot;
        if (! $snapshot instanceof InvoiceSnapshot
            || (string) $snapshot->invoice_number !== $invoiceNumber) {
            abort(404, 'Bestellung nicht gefunden.');
        }

        $details = $snapshot->customer_details;
        $items = is_array($details) ? ($details['items'] ?? []) : [];
        $customConditions = is_array($details) ? ($details['custom_conditions'] ?? null) : null;
        if (! is_array($items)
            || $items === []
            || ($customConditions !== null && ! is_string($customConditions))
            || $this->orderItemsFingerprint($items, $customConditions) !== $fingerprint) {
            abort(404, 'Bestellung nicht gefunden.');
        }

        $this->assertOrderDownloadContext($order, $snapshot, $currentActor);
        $photos = $this->authorizeOrderItemsForDownload($items, $order, $snapshot, $currentActor);

        return [
            'order' => $order,
            'snapshot' => $snapshot,
            'items' => $items,
            'custom_conditions' => $customConditions,
            'photos' => $photos,
            'actor' => $currentActor,
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{photo_id:string, gallery_id:string, photo_filename:string, tier:string, source_path:string, scaled_path:string, processed_path:string, download_name:string}>
     */
    private function prepareOrderZipFiles(
        array $items,
        User $actor,
        string $baseStoragePath,
        string $userName,
        ?string $customConditions,
    ): array {
        $tempDir = storage_path('app/private/temp');
        if (! is_dir($tempDir) && ! @mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
            abort(500, 'ZIP-Vorbereitung fehlgeschlagen.');
        }

        $prepared = [];
        $cleanupFiles = [];
        try {
            foreach ($items as $item) {
                $photoId = is_array($item) ? ($item['photoId'] ?? null) : null;
                $tier = is_array($item) ? ($item['tier'] ?? 'original') : null;
                if (! is_string($photoId) || trim($photoId) === '' || ! is_string($tier)) {
                    abort(422, 'Ungültiger Bestellartikel.');
                }

                $photo = Photo::query()->find(trim($photoId));
                if (! $photo instanceof Photo) {
                    abort(404, 'Bild nicht gefunden.');
                }
                $this->assertOrderPhotoDeliverable($photo, $tier, $actor);

                $sourcePath = $baseStoragePath.'/'.$photo->gallery_id.'/'.$photo->filename;
                if (! is_file($sourcePath)) {
                    continue;
                }

                $scaledPath = $tempDir.'/base_scale_'.$photo->id.'_'.$tier.'.jpg';
                $cleanupFiles[] = $scaledPath;
                $maxWidth = self::DOWNLOAD_TIERS[$tier];
                $scaleSucceeded = false;
                Cache::lock('scale_'.$photo->id.'_'.$tier, 60)->block(30, function () use ($sourcePath, $scaledPath, $maxWidth, &$scaleSucceeded) {
                    if (is_file($scaledPath)) {
                        @unlink($scaledPath);
                    }
                    $scaleSucceeded = $this->imageProcessor->scaleImage($sourcePath, $scaledPath, $maxWidth);
                });

                if (! $scaleSucceeded || ! $this->imageProcessor->isValidImageFile($scaledPath)) {
                    abort(500, 'Bildverarbeitung fehlgeschlagen.');
                }

                $processedPath = $this->injectMetadata($scaledPath, $photo, $userName, $customConditions);
                if ($processedPath !== $sourcePath) {
                    $cleanupFiles[] = $processedPath;
                }
                if (! $this->imageProcessor->isValidImageFile($processedPath)) {
                    abort(500, 'Bildverarbeitung fehlgeschlagen.');
                }

                $prepared[] = [
                    'photo_id' => (string) $photo->getKey(),
                    'gallery_id' => (string) $photo->gallery_id,
                    'photo_filename' => (string) $photo->filename,
                    'tier' => $tier,
                    'source_path' => $sourcePath,
                    'scaled_path' => $scaledPath,
                    'processed_path' => $processedPath,
                    'download_name' => $photo->id.'_'.strtoupper($tier).'.jpg',
                ];
            }
        } catch (\Throwable $e) {
            $this->removeTempFiles($cleanupFiles);

            throw $e;
        }

        return $prepared;
    }

    /**
     * @param  array<int, array{photo_id:string, gallery_id:string, photo_filename:string, tier:string, source_path:string, scaled_path:string, processed_path:string, download_name:string}>  $preparedFiles
     * @param  array{order: Order, snapshot: InvoiceSnapshot, items: array<int, mixed>, custom_conditions: ?string, photos: Collection<string, Photo>, actor: User}  $context
     */
    private function assertPreparedOrderFilesCurrent(array $preparedFiles, array $context): void
    {
        foreach ($preparedFiles as $file) {
            $photo = $this->mediaVisibility->requireVisiblePhoto($file['photo_id']);
            if ((string) $photo->gallery_id !== (string) $file['gallery_id']
                || (string) $photo->filename !== (string) $file['photo_filename']) {
                abort(404, 'Bild nicht gefunden.');
            }
            $this->assertOrderPhotoDeliverable($photo, $file['tier'], $context['actor']);
        }
    }

    public function downloadOrderZip(Request $request, $orderId)
    {
        $actor = $this->requireCurrentActorForAuthorization();
        $order = Order::query()
            ->ownedBy($actor)
            ->whereKey($orderId)
            ->with('invoiceSnapshot')
            ->firstOrFail();

        // Keep the settled-status gate ahead of snapshot/media validation. A
        // disputed/refunded order must not be turned into a misleading 404
        // merely because its legacy snapshot is missing.
        if ($order->is_quote_request && $order->status === 'pending') {
            abort(403, 'Angebot noch nicht abgerechnet.');
        }
        if (! app(PurchaseService::class)->isOrderDownloadEligible($order)) {
            abort(403, 'Zugriff aufgrund des Bestellstatus gesperrt.');
        }

        $snapshot = $order->invoiceSnapshot;
        $customerDetails = $snapshot?->customer_details;
        $items = is_array($customerDetails) ? ($customerDetails['items'] ?? []) : [];
        $customConditions = is_array($customerDetails) ? ($customerDetails['custom_conditions'] ?? null) : null;
        if (! $snapshot instanceof InvoiceSnapshot
            || ! is_array($items)
            || $items === []
            || ($customConditions !== null && ! is_string($customConditions))) {
            abort(404, 'Keine Bilder in dieser Bestellung gefunden.');
        }

        $photos = $this->authorizeOrderItemsForDownload($items, $order, $snapshot, $actor);
        $photoIds = $this->boundedAuditIds($photos->keys());
        $galleryIds = $this->boundedAuditIds($photos->pluck('gallery_id'));
        $photoCount = $photos->count();
        $galleryId = count($galleryIds) === 1 ? $galleryIds[0] : null;
        $tiers = [];
        foreach ($items as $item) {
            $tiers[] = is_array($item) && is_string($item['tier'] ?? null)
                ? $item['tier']
                : 'original';
        }
        $tiers = array_values(array_unique($tiers));
        $resolutionTier = count($tiers) === 1 ? $tiers[0] : null;
        $orderId = (string) $order->getKey();
        $invoiceNumber = (string) $snapshot->invoice_number;
        $fingerprint = $this->orderItemsFingerprint($items, $customConditions);
        $baseStoragePath = rtrim(Storage::disk('photos')->path(''), '/\\');
        $preparedFiles = [];
        $downloadLogId = null;

        try {
            // All order items are prepared before a stream response exists. A
            // refund/dispute or access revocation during processing therefore
            // yields a normal 4xx response with no ZIP bytes and no audit row.
            $preparedFiles = $this->prepareOrderZipFiles(
                $items,
                $actor,
                $baseStoragePath,
                $actor->name,
                $customConditions,
            );

            $currentContext = $this->loadCurrentOrderDownloadContext(
                $orderId,
                $invoiceNumber,
                $fingerprint,
                $actor,
            );
            $this->assertPreparedOrderFilesCurrent($preparedFiles, $currentContext);

            $downloadLog = DownloadLog::create([
                'user_id' => ActorIdentity::registeredId($currentContext['actor']),
                'guest_id' => ActorIdentity::guestId($currentContext['actor']),
                'user_name_snapshot' => $currentContext['actor']->name,
                'order_id' => $currentContext['order']->id,
                'gallery_id' => $galleryId,
                'gallery_name_snapshot' => 'Order '.$currentContext['snapshot']->invoice_number,
                'item_type' => 'full_zip',
                'resolution_tier' => $resolutionTier,
                'user_agent' => $request->userAgent(),
                'payload' => $this->buildZipAuditPayload($photoIds, $galleryIds, $photoCount),
                'photo_count' => $photoCount,
            ]);
            $downloadLogId = $downloadLog->getKey();
            $this->registerTempFileCleanup($this->preparedFilePaths($preparedFiles));

            return response()->streamDownload(function () use (
                $actor,
                $orderId,
                $invoiceNumber,
                $fingerprint,
                $preparedFiles,
                $downloadLogId,
            ) {
                try {
                    // Processing is complete at this point. Re-read every
                    // mutable order, snapshot, access, expiry, tree, and photo
                    // boundary immediately before ZipStream can emit byte one.
                    $currentContext = $this->loadCurrentOrderDownloadContext(
                        $orderId,
                        $invoiceNumber,
                        $fingerprint,
                        $actor,
                    );
                    $this->assertPreparedOrderFilesCurrent($preparedFiles, $currentContext);

                    $zip = new ZipStream(sendHttpHeaders: false);
                    foreach ($preparedFiles as $file) {
                        $zip->addFileFromPath($file['download_name'], $file['processed_path']);
                    }
                    $zip->finish();
                } catch (\Throwable $e) {
                    DownloadLog::query()->whereKey($downloadLogId)->delete();

                    throw $e;
                } finally {
                    $this->removeTempFiles($this->preparedFilePaths($preparedFiles));
                }
            }, 'Order_'.$invoiceNumber.'.zip');
        } catch (\Throwable $e) {
            if ($downloadLogId !== null) {
                DownloadLog::query()->whereKey($downloadLogId)->delete();
            }
            $this->removeTempFiles($this->preparedFilePaths($preparedFiles));

            throw $e;
        }
    }
}
