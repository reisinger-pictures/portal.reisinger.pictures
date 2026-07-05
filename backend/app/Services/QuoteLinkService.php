<?php

namespace App\Services;

use App\Support\BrandRegistry;

class QuoteLinkService
{
    /**
     * Default validity window (days) for quote links when none is requested.
     */
    public const DEFAULT_VALIDITY_DAYS = 14;

    /**
     * Generate a quote link carrying a signed JWT token (`?quote_token=`).
     */
    public function generateQuoteLink(array $photoIds, int $customPrice, int $validityDays = self::DEFAULT_VALIDITY_DAYS): string
    {
        $payload = [
            'photos' => $photoIds,
            'price' => $customPrice,
        ];

        $token = app(OfferTokenService::class)->issue($payload, now()->addDays($validityDays));

        return BrandRegistry::frontendUrl() . '/cart?quote_token=' . $token;
    }

    /**
     * Decode and validate a quote token via OfferTokenService.
     * Returns the payload array (photos, price) on success, null on failure.
     */
    public function decode(string $token): ?array
    {
        return app(OfferTokenService::class)->verify($token);
    }

    /**
     * Extract token from request query string.
     */
    public function extractTokenFromRequest(array $queryParams): ?string
    {
        return $queryParams['token'] ?? null;
    }
}
