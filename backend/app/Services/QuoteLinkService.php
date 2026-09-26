<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Auth\Access\AuthorizationException;

class QuoteLinkService
{
    /**
     * Default validity window (days) for quote links when none is requested.
     */
    public const DEFAULT_VALIDITY_DAYS = 14;

    /**
     * Maximum number of photos in one quote offer.
     */
    public const MAX_PHOTOS = 500;

    /**
     * Generate a quote link carrying a signed JWT token (`?quote_token=`).
     *
     * Issuance is deliberately an authorization boundary, not just a signing
     * operation. The issuer must be able to manage every referenced gallery,
     * and all referenced photos must still be deliverable in the active brand.
     */
    public function generateQuoteLink(
        array $photoIds,
        int $customPrice,
        int $validityDays = self::DEFAULT_VALIDITY_DAYS,
        ?string $rightsText = null,
        ?User $issuer = null,
    ): string {
        $issuer ??= auth('api')->user();
        if (! $issuer instanceof User) {
            throw new AuthorizationException('Keine Berechtigung');
        }

        $photoIds = $this->validatePhotoIds($issuer, $photoIds);
        if ($customPrice < 1) {
            throw new \InvalidArgumentException('Der Angebotspreis muss größer als 0 sein.');
        }
        if ($validityDays < 1) {
            throw new \InvalidArgumentException('Die Gültigkeitsdauer muss mindestens einen Tag betragen.');
        }

        $currentBrand = BrandRegistry::currentIdOrNull();
        if ($currentBrand === null) {
            throw new AuthorizationException('Keine Berechtigung');
        }

        $token = app(OfferTokenService::class)->issueQuote(
            $photoIds,
            $customPrice,
            $rightsText,
            $currentBrand,
            now()->addDays($validityDays),
        );

        return BrandRegistry::frontendUrl().'/cart?quote_token='.$token;
    }

    /**
     * Validate and canonicalize photo IDs before they are signed.
     *
     * @return array<int, string>
     */
    public function validatePhotoIds(User $issuer, array $photoIds): array
    {
        $authorization = app(AuthorizationService::class);
        if (! $authorization->isAdmin($issuer) && ! $authorization->isPhotographer($issuer)) {
            throw new AuthorizationException('Keine Berechtigung');
        }

        $photoIds = $this->normalizePhotoIds($photoIds);
        $photos = Photo::with('gallery')
            ->whereIn('id', $photoIds)
            ->get()
            ->keyBy(fn (Photo $photo): string => (string) $photo->getKey());

        if ($photos->count() !== count($photoIds)) {
            throw new \InvalidArgumentException('Mindestens ein ausgewähltes Foto existiert nicht.');
        }

        foreach ($photoIds as $photoId) {
            /** @var Photo $photo */
            $photo = $photos->get($photoId);
            $gallery = $photo->gallery;

            if ($gallery === null || ! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
                throw new AuthorizationException('Keine Berechtigung');
            }
            if ($gallery->isSelection()) {
                throw new \InvalidArgumentException('Auswahl-Galerien können nicht angeboten werden.');
            }
            if (! $authorization->canManageGallery($issuer, (string) $gallery->getKey())) {
                throw new AuthorizationException('Keine Berechtigung');
            }

            if ($photo->effective_is_hidden || $gallery->effective_is_hidden) {
                throw new \InvalidArgumentException('Ausgewählte Fotos sind nicht lieferbar.');
            }
            if ($gallery->expires_at !== null && $gallery->expires_at->isPast()) {
                throw new \InvalidArgumentException('Ausgewählte Fotos sind nicht mehr lieferbar.');
            }
        }

        return $photoIds;
    }

    /**
     * Decode and validate a quote token via OfferTokenService.
     * Returns the payload array (photos, price, brand) on success, null on failure.
     */
    public function decode(string $token): ?array
    {
        $currentBrand = BrandRegistry::currentIdOrNull();
        if ($currentBrand === null) {
            return null;
        }

        $payload = app(OfferTokenService::class)->verifyQuote($token, $currentBrand);
        if ($payload === null || ! $this->photosAreDeliverable($payload['photos'])) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array<int, string>  $photoIds
     */
    private function photosAreDeliverable(array $photoIds): bool
    {
        if ($photoIds === [] || count($photoIds) > self::MAX_PHOTOS) {
            return false;
        }

        $photos = Photo::with('gallery')
            ->whereIn('id', $photoIds)
            ->get();
        if ($photos->count() !== count($photoIds)) {
            return false;
        }

        foreach ($photos as $photo) {
            $gallery = $photo->gallery;
            if ($gallery === null || ! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
                return false;
            }
            if ($gallery->isSelection()) {
                return false;
            }
            if ($photo->effective_is_hidden || $gallery->effective_is_hidden) {
                return false;
            }
            if ($gallery->expires_at !== null && $gallery->expires_at->isPast()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function normalizePhotoIds(array $photoIds): array
    {
        if ($photoIds === [] || count($photoIds) > self::MAX_PHOTOS) {
            throw new \InvalidArgumentException('Die Fotoauswahl ist ungültig.');
        }

        $normalized = [];
        foreach ($photoIds as $photoId) {
            if (! is_string($photoId) || trim($photoId) === '' || strlen($photoId) > 255) {
                throw new \InvalidArgumentException('Die Fotoauswahl ist ungültig.');
            }

            $normalized[] = $photoId;
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new \InvalidArgumentException('Die Fotoauswahl enthält doppelte Fotos.');
        }

        return array_values($normalized);
    }
}
