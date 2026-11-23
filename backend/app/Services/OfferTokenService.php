<?php

namespace App\Services;

use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Issues and verifies detached, signed offer payloads.
 *
 * D-77 removed the JWT package entirely, including its role as a signature
 * library. The wire format is deliberately kept byte-compatible with the old
 * HS256 provider — `base64url(header).base64url(payload).base64url(sig)` with
 * the exact header `{"typ":"JWT","alg":"HS256"}` and an HMAC-SHA256 over the
 * first two segments — so links issued before the migration stay verifiable.
 * The key comes from `services.signed_links.secret`, which falls back to the
 * production `JWT_SECRET` and therefore needs no deployment change.
 *
 * No token here is an authentication credential: `verify()` returns only the
 * embedded `offer` array (D-48).
 */
class OfferTokenService
{
    /**
     * Subject claim for detached offer tokens. It is part of the payload the
     * strict verifier checks, so a stable value is required.
     */
    public const SUBJECT = 'offer';

    /**
     * Default validity window (days) when no expiry is supplied by the caller.
     */
    public const DEFAULT_VALIDITY_DAYS = 14;

    /**
     * Kept verbatim so already-issued tokens keep the same header segment.
     */
    private const HEADER = ['typ' => 'JWT', 'alg' => 'HS256'];

    /**
     * Issue a signed token carrying the given offer payload.
     *
     * @param  array  $offerPayload  Arbitrary offer data (items, customer_*, terms, ...).
     * @param  Carbon|null  $expiresAt  Expiry; defaults to now + 14 days.
     */
    public function issue(array $offerPayload, ?Carbon $expiresAt = null): string
    {
        $expiresAt ??= now()->addDays(self::DEFAULT_VALIDITY_DAYS);
        $now = now();

        $claims = [
            'iss' => config('app.url'),
            'iat' => $now->timestamp,
            'nbf' => $now->timestamp,
            'exp' => $expiresAt->timestamp,
            'sub' => self::SUBJECT,
            'jti' => (string) Str::uuid(),
            'offer' => $offerPayload,
        ];

        return $this->sign($claims);
    }

    /**
     * Issue a quote offer with the brand binding required by checkout.
     *
     * Quote links are deliberately stricter than the generic offer token used
     * for PDF/manual-invoice markers. In particular, a quote token cannot be
     * used as a container for arbitrary photo IDs without declaring the brand
     * those photos belong to.
     */
    public function issueQuote(
        array $photoIds,
        int $price,
        ?string $rightsText = null,
        ?string $brand = null,
        ?Carbon $expiresAt = null,
    ): string {
        $payload = [
            'photos' => array_values($photoIds),
            'price' => $price,
            'brand' => $brand ?? BrandRegistry::currentId(),
        ];

        if ($rightsText !== null) {
            $payload['rights_text'] = $rightsText;
        }

        return $this->issue($payload, $expiresAt);
    }

    /**
     * Verify a quote token and validate its complete, strict payload shape.
     *
     * The generic verify() method remains intentionally permissive because it
     * is also used for manual invoice and contract PDF markers. Checkout must
     * use this method so a signed but malformed/legacy quote payload cannot
     * turn into a deliverable order.
     */
    public function verifyQuote(
        string $token,
        ?string $expectedBrand = null,
        bool $requirePositivePrice = true,
    ): ?array {
        $payload = $this->verify($token);
        if ($payload === null) {
            return null;
        }

        $photoIds = $payload['photos'] ?? null;
        $price = $payload['price'] ?? null;
        $brand = $payload['brand'] ?? null;
        $rightsText = $payload['rights_text'] ?? null;

        if (! is_array($photoIds) || $photoIds === [] || count($photoIds) > 500) {
            return null;
        }
        if (! is_int($price) || ($requirePositivePrice && $price < 1)) {
            return null;
        }
        if (! is_string($brand) || trim($brand) === '') {
            return null;
        }
        if ($expectedBrand !== null && ! hash_equals(trim($expectedBrand), trim($brand))) {
            return null;
        }
        if ($rightsText !== null && (! is_string($rightsText) || strlen($rightsText) > 2000)) {
            return null;
        }

        $normalizedPhotoIds = [];
        foreach ($photoIds as $photoId) {
            if (! is_string($photoId) || trim($photoId) === '' || strlen($photoId) > 255) {
                return null;
            }

            $normalizedPhotoIds[] = $photoId;
        }

        if (count(array_unique($normalizedPhotoIds)) !== count($normalizedPhotoIds)) {
            return null;
        }

        return [
            'photos' => $normalizedPhotoIds,
            'price' => $price,
            'brand' => trim($brand),
            ...($rightsText === null ? [] : ['rights_text' => $rightsText]),
        ];
    }

    /**
     * Verify a signed token and return its embedded offer payload.
     *
     * Returns null on any failure (bad signature, malformed, expired, wrong
     * subject or issuer). There is deliberately no distinguishable error: the
     * token is a link credential and must not become an oracle (D-48).
     */
    public function verify(string $token): ?array
    {
        $claims = $this->decode($token);
        if ($claims === null) {
            return null;
        }

        /** @var mixed $offer */
        $offer = $claims['offer'] ?? null;

        return is_array($offer) ? $offer : null;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function sign(array $claims): string
    {
        $secret = $this->secret();
        if ($secret === null) {
            throw new RuntimeException('No signed-link secret is configured.');
        }

        $header = self::base64UrlEncode(self::jsonEncode(self::HEADER));
        $payload = self::base64UrlEncode(self::jsonEncode($claims));
        $signature = self::base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $token): ?array
    {
        $secret = $this->secret();
        if ($secret === null) {
            return null;
        }

        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }

        [$headerSegment, $payloadSegment, $signatureSegment] = $segments;
        $headerJson = self::base64UrlDecode($headerSegment);
        $payloadJson = self::base64UrlDecode($payloadSegment);
        $signature = self::base64UrlDecode($signatureSegment);
        if ($headerJson === null || $payloadJson === null || $signature === null) {
            return null;
        }

        try {
            /** @var mixed $header */
            $header = json_decode($headerJson, true, 512, JSON_THROW_ON_ERROR);
            /** @var mixed $claims */
            $claims = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($header)
            || ($header['typ'] ?? null) !== self::HEADER['typ']
            || ($header['alg'] ?? null) !== self::HEADER['alg']) {
            return null;
        }

        $expected = hash_hmac('sha256', $headerSegment.'.'.$payloadSegment, $secret, true);
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        if (! is_array($claims)) {
            return null;
        }

        // Strict claim validation. A signed payload is still not a credential
        // unless every time/identity claim has the expected type and value.
        if (! isset($claims['exp']) || ! is_int($claims['exp']) || $claims['exp'] <= time()) {
            return null;
        }
        if (! isset($claims['nbf']) || ! is_int($claims['nbf']) || $claims['nbf'] > time()) {
            return null;
        }
        if (! isset($claims['iat']) || ! is_int($claims['iat'])) {
            return null;
        }
        if (($claims['sub'] ?? null) !== self::SUBJECT) {
            return null;
        }

        $issuer = config('app.url');
        if (! is_string($issuer) || $issuer === ''
            || ! is_string($claims['iss'] ?? null)
            || ! hash_equals($issuer, $claims['iss'])) {
            return null;
        }

        if (! is_string($claims['jti'] ?? null) || $claims['jti'] === '') {
            return null;
        }

        return $claims;
    }

    private function secret(): ?string
    {
        $secret = config('services.signed_links.secret');

        // D-77: the removed Lcobucci/JWT HS256 signer refused keys shorter than
        // 256 bits. Keep that floor so a misconfigured short secret fails closed
        // instead of silently producing weak signatures. Already-issued links
        // can only have been signed with a >= 32-byte key, so this stays compatible.
        return is_string($secret) && strlen($secret) >= 32 ? $secret : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function jsonEncode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $segment): ?string
    {
        if (preg_match('/\A[A-Za-z0-9_-]*\z/D', $segment) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        if (! is_string($decoded) || self::base64UrlEncode($decoded) !== $segment) {
            return null;
        }

        return $decoded;
    }
}
