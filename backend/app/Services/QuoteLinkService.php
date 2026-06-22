<?php

namespace App\Services;

class QuoteLinkService
{
    /**
     * Generate a quote link with signed token
     */
    public function generateQuoteLink(array $photoIds, int $customPrice, int $validityDays = 14): string
    {
        $payload = $this->generatePayload($photoIds, $customPrice, $validityDays);
        $signature = $this->generateSignature($payload);
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        return $frontendUrl . '/cart?quote_token=' . $payload . '.' . $signature;
    }

    /**
     * Generate payload for quote token
     */
    public function generatePayload(array $photoIds, int $customPrice, int $validityDays = 14): string
    {
        $data = [
            'photos' => $photoIds,
            'price' => $customPrice,
            'exp' => now()->addDays($validityDays)->timestamp
        ];

        return base64_encode(json_encode($data));
    }

    /**
     * Generate HMAC signature for payload
     */
    public function generateSignature(string $payload): string
    {
        return hash_hmac('sha256', $payload, config('app.key'));
    }

    /**
     * Decode and validate quote token
     */
    public function decodeQuoteToken(string $token): ?array
    {
        if (strpos($token, '.') === false) {
            return null;
        }

        [$payload, $signature] = explode('.', $token, 2);

        if (!$this->verifySignature($payload, $signature)) {
            return null;
        }

        $data = json_decode(base64_decode($payload), true);

        // Check expiration
        if (isset($data['exp']) && $data['exp'] < time()) {
            return null;
        }

        return $data;
    }

    /**
     * Verify HMAC signature
     */
    public function verifySignature(string $payload, string $signature): bool
    {
        $expectedSignature = $this->generateSignature($payload);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Extract token from request query string
     */
    public function extractTokenFromRequest(array $queryParams): ?string
    {
        return $queryParams['token'] ?? null;
    }
}
