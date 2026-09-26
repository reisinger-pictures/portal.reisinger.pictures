<?php

namespace App\Http\Controllers;

use App\Constants\TierRanks;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\ImageProcessor;
use App\Services\MediaVisibilityService;
use App\Support\ActorIdentity;
use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileDeliveryController extends Controller
{
    public function __construct(
        private readonly ImageProcessor $imageProcessor,
        private readonly MediaVisibilityService $mediaVisibility,
    ) {}

    public function serve(Request $request, $slug, $identifier)
    {
        $isWatermarkedRequest = str_starts_with($identifier, 'watermarked/');
        $mediaIdentifier = $isWatermarkedRequest ? substr($identifier, 12) : $identifier;
        $isThumbnail = (bool) preg_match('#^_thumbs/(\d+)/([a-f0-9\-]+)\.webp$#i', $mediaIdentifier, $thumbnailMatches);
        $isOriginal = (bool) preg_match('#^([a-f0-9\-]+)\.[a-z0-9]+$#i', $mediaIdentifier, $originalMatches);

        if (! $isThumbnail && ! $isOriginal) {
            // Preserve the established gate ordering: gallery access is checked
            // before malformed private-resource identifiers are rejected.
            $galleryAuthorization = $this->authorizeGalleryForDelivery($slug);
            if ($galleryAuthorization instanceof JsonResponse) {
                return $galleryAuthorization;
            }
            if (! (($galleryAuthorization[0] ?? null) instanceof Gallery)) {
                return response()->json(['error' => 'Galerie nicht gefunden'], 404);
            }

            return response()->json(['error' => 'Ungültiges URL-Format'], 400);
        }

        $size = $isThumbnail ? (int) $thumbnailMatches[1] : 0;
        $photoId = $isThumbnail ? $thumbnailMatches[2] : $originalMatches[1];
        $mediaAuthorization = $this->authorizeMediaDelivery($slug, $photoId, $isWatermarkedRequest);
        if ($mediaAuthorization instanceof JsonResponse) {
            return $mediaAuthorization;
        }
        [$gallery, $photo] = $mediaAuthorization;
        $baseStoragePath = rtrim(Storage::disk('photos')->path(''), '/\\');

        // AUTH-3: only the model's whitelisted derivative sizes may ever be
        // materialized. Without this, any digit sequence became a
        // `_thumbs/{size}` directory and full-resolution unwatermarked
        // intermediate, enabling unbounded disk/cache exhaustion for any
        // readable gallery. Reject unknown sizes before any filesystem or image
        // work (and after authorization, to preserve the established gate
        // ordering). `0` (original delivery) is never a thumbnail request.
        if ($isThumbnail && ! in_array($size, Photo::DERIVATIVE_SIZES, true)) {
            return response()->json(['error' => 'Ungültige Thumbnail-Größe'], 400);
        }

        $path = null;

        if ($isThumbnail) {
            $originalPath = $baseStoragePath.'/'.$gallery->id.'/'.$photo->filename;
            if (! file_exists($originalPath)) {
                return response()->json(['error' => 'Original fehlt auf der Festplatte'], 404);
            }

            $thumbPath = $baseStoragePath.'/'.$gallery->id.'/_thumbs/'.$size.'/'.$photo->id.'.webp';
            $thumbLockKey = 'thumb_generation_'.$photo->id.'_'.$size;
            Cache::lock($thumbLockKey, 30)->block(10, function () use ($photo, $thumbPath, $originalPath, $size) {
                if ($this->imageProcessor->isValidImageFile($thumbPath)) {
                    return;
                }
                if (is_file($thumbPath)) {
                    @unlink($thumbPath);
                }
                if (! is_dir(dirname($thumbPath))) {
                    @mkdir(dirname($thumbPath), 0755, true);
                }
                try {
                    $this->imageProcessor->generateThumbnail($originalPath, $thumbPath, $size);
                } catch (\Throwable $e) {
                    Log::error('Thumbnail generation failed', [
                        'photo_id' => $photo->id,
                        'size' => $size,
                        'original' => $originalPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            if (! $this->imageProcessor->isValidImageFile($thumbPath)) {
                return response()->json(['error' => 'Thumbnail fehlt'], 500);
            }
            $path = $thumbPath;

            if ($isWatermarkedRequest) {
                $wmPath = $baseStoragePath.'/'.$gallery->id.'/_thumbs/_watermarked/'.$size.'/'.$photo->id.'.webp';
                try {
                    if (! $this->ensureWatermarkedFile($path, $wmPath, $gallery->type)) {
                        return $this->watermarkFailureResponse();
                    }
                } catch (\Throwable $e) {
                    Log::error('Watermark generation failed', [
                        'photo_id' => $photo->id,
                        'gallery_id' => $gallery->id,
                        'error' => $e->getMessage(),
                    ]);

                    return $this->watermarkFailureResponse();
                }
                $path = $wmPath;
            }
        } else {
            $originalPath = $baseStoragePath.'/'.$gallery->id.'/'.$photo->filename;
            if (! file_exists($originalPath)) {
                return response()->json(['error' => 'Original fehlt auf der Festplatte'], 404);
            }
            $path = $originalPath;

            if ($isWatermarkedRequest) {
                $wmPath = $baseStoragePath.'/'.$gallery->id.'/_watermarked/'.$photo->filename;
                try {
                    if (! $this->ensureWatermarkedFile($path, $wmPath, $gallery->type, 2000)) {
                        return $this->watermarkFailureResponse();
                    }
                } catch (\Throwable $e) {
                    Log::error('Watermark generation failed', [
                        'photo_id' => $photo->id,
                        'gallery_id' => $gallery->id,
                        'error' => $e->getMessage(),
                    ]);

                    return $this->watermarkFailureResponse();
                }
                $path = $wmPath;
            }
        }

        if (! is_file($path)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }

        // Processing is complete. Re-run the complete delivery authorization
        // before updating access bookkeeping or exposing a file/proxy header.
        $currentMediaAuthorization = $this->authorizeMediaDelivery(
            $slug,
            $photoId,
            $isWatermarkedRequest,
        );
        if ($currentMediaAuthorization instanceof JsonResponse) {
            return $currentMediaAuthorization;
        }
        [$currentGallery, $currentPhoto] = $currentMediaAuthorization;
        if ((string) $currentGallery->id !== (string) $gallery->id
            || (string) $currentGallery->type !== (string) $gallery->type
            || (string) $currentPhoto->id !== (string) $photo->id
            || (string) $currentPhoto->filename !== (string) $photo->filename) {
            return response()->json(['error' => 'Foto nicht gefunden'], 404);
        }

        $cacheKey = 'photo_hit_'.$currentPhoto->id;
        if (! Cache::has($cacheKey)) {
            $currentPhoto->update(['last_accessed_at' => now()]);
            Cache::put($cacheKey, true, now()->addHours(24));
        }

        $headers = [
            'Content-Type' => $currentPhoto->mime_type ?? mime_content_type($path),
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ];

        if ($proxyHeader = config('services.proxy_delivery_header')) {
            $headers[$proxyHeader] = $path;

            return response()->make('', 200, $headers);
        }

        return response()->file($path, $headers);
    }

    /**
     * @return JsonResponse|array{0: Gallery, 1: ?User}|array{0: null, 1: null}
     */
    private function authorizeGalleryForDelivery(string $slug): JsonResponse|array
    {
        $gallery = Str::isUuid($slug)
            ? Gallery::query()->whereKey($slug)->first()
            : Gallery::query()->where('slug', $slug)->first();
        if (! $gallery instanceof Gallery) {
            return [null, null];
        }

        // Resolve the response outside this helper so callers can retain the
        // endpoint's established JSON status/message contract.
        if (! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
            return [null, null];
        }
        if (! $this->mediaVisibility->galleryIsVisible($gallery->id)) {
            return [null, null];
        }

        $user = $this->currentDeliveryUser();
        $svc = app(AuthorizationService::class);
        $canManage = (bool) ($user && $svc->canManageGallery($user, $gallery->id));
        $isExpired = $gallery->expires_at && Carbon::parse($gallery->expires_at)->isPast();
        if ($isExpired && ! $canManage) {
            return response()->json(['error' => 'Galerie abgelaufen'], 403);
        }
        if (! $gallery->effective_is_public) {
            if (! $user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            if (! $svc->canAccessGallery($user, $gallery->id)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
        }

        return [$gallery, $user];
    }

    /**
     * @return JsonResponse|array{0: Gallery, 1: Photo}
     */
    private function authorizeMediaDelivery(
        string $slug,
        string $photoId,
        bool $isWatermarkedRequest,
    ): JsonResponse|array {
        $authorization = $this->authorizeGalleryForDelivery($slug);
        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        [$gallery, $user] = $authorization;
        if (! $gallery instanceof Gallery) {
            return response()->json(['error' => 'Galerie nicht gefunden'], 404);
        }

        $svc = app(AuthorizationService::class);
        $canManage = (bool) ($user && $svc->canManageGallery($user, $gallery->id));
        $logicalNeedsWatermark = true;
        if ($gallery->effective_is_free_download) {
            $logicalNeedsWatermark = false;
        } elseif ($canManage) {
            $logicalNeedsWatermark = false;
        } elseif ($user && $svc->canAccessGallery($user, $gallery->id)) {
            $logicalNeedsWatermark = (TierRanks::RANKS[$user->flatrate_level ?? 'none'] ?? 0) < 1;
        }

        if (! $isWatermarkedRequest && $gallery->isSelection()) {
            return response()->json(['error' => 'Auswahl-Galerien erlauben keinen Original-Download.'], 403);
        }
        if (! $isWatermarkedRequest && $logicalNeedsWatermark) {
            return response()->json(['error' => 'Zugriff auf Original-Ressource verweigert. Wasserzeichen erforderlich.'], 403);
        }

        $photo = Photo::query()
            ->whereKey($photoId)
            ->where('gallery_id', $gallery->id)
            ->first();
        if (! $photo instanceof Photo || ! $this->mediaVisibility->photoIsVisible($photo->id)) {
            return response()->json(['error' => 'Foto nicht gefunden'], 404);
        }

        return [$gallery, $photo];
    }

    private function currentDeliveryUser(): ?User
    {
        $actor = auth('api')->user();
        if (! $actor instanceof User) {
            return null;
        }

        $registeredId = ActorIdentity::registeredId($actor);
        if ($registeredId === null) {
            return $actor;
        }

        return User::query()->find($registeredId);
    }

    private function ensureWatermarkedFile(
        string $sourcePath,
        string $destPath,
        string $galleryType = 'delivery',
        ?int $maxWidth = null,
    ): bool {
        if (! $this->imageProcessor->watermarkAssetAvailable($sourcePath, $galleryType, $maxWidth)) {
            if (is_file($destPath)) {
                @unlink($destPath);
            }

            return false;
        }

        if ($this->imageProcessor->isSafeWatermarkedOutput($sourcePath, $destPath, $galleryType, $maxWidth)) {
            return true;
        }

        $directory = dirname($destPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        $generated = $this->imageProcessor->applyCenteredWatermark(
            $sourcePath,
            $destPath,
            $maxWidth,
            $galleryType,
        );

        if (! $generated || ! $this->imageProcessor->isSafeWatermarkedOutput($sourcePath, $destPath, $galleryType, $maxWidth)) {
            if (is_file($destPath)) {
                @unlink($destPath);
            }

            return false;
        }

        return true;
    }

    private function watermarkFailureResponse()
    {
        return response()->json(['error' => 'SECURITY: Watermark-Fail.'], 500);
    }
}
