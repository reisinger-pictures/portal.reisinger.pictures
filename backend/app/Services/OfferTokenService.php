<?php

namespace App\Services;

use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Contracts\Providers\JWT as JWTProvider;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Manager;
use PHPOpenSourceSaver\JWTAuth\Token;

/**
 * Issues and verifies signed JWTs carrying machine-readable offer payloads.
 *
 * Reuses the already-installed `php-open-source-saver/jwt-auth` infrastructure
 * (the same Manager / provider / HS256 secret that powers user login), so offer
 * tokens and login tokens share one signing key + verification path.
 * No `config('app.key')` and no separate HMAC/base64 logic is used here.
 */
class OfferTokenService
{
    /**
     * Subject claim for detached offer tokens (these tokens are not bound to a
     * User model). `sub` is part of `jwt.required_claims`, so a stable value is
     * required for the payload validator to accept the token on verify.
     */
    public const SUBJECT = 'offer';

    /**
     * Default validity window (days) when no expiry is supplied by the caller.
     */
    public const DEFAULT_VALIDITY_DAYS = 14;

    public function __construct(
        private Manager $manager,
        private JWTProvider $provider,
    ) {}

    /**
     * Issue a signed JWT carrying the given offer payload.
     *
     * @param  array  $offerPayload  Arbitrary offer data (items, customer_*, terms, ...).
     * @param  Carbon|null  $expiresAt  Expiry; defaults to now + 14 days.
     */
    public function issue(array $offerPayload, ?Carbon $expiresAt = null): string
    {
        $expiresAt ??= now()->addDays(self::DEFAULT_VALIDITY_DAYS);
        $now = now();

        // Author the claims directly (no encode-time payload validation: we trust
        // our own issuance; strict validation — incl. exp — runs on verify via
        // the manager). The provider signs with config('jwt.secret') / HS256,
        // identical to the login-token mechanism.
        $claims = [
            'iss' => config('app.url'),
            'iat' => $now->timestamp,
            'nbf' => $now->timestamp,
            'exp' => $expiresAt->timestamp,
            'sub' => self::SUBJECT,
            'jti' => (string) Str::uuid(),
            'offer' => $offerPayload,
        ];

        return $this->provider->encode($claims);
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
     * Verify a signed JWT and return its embedded offer payload.
     *
     * Returns null on any failure (bad signature, malformed, expired).
     */
    public function verify(string $token): ?array
    {
        try {
            // The manager re-validates the payload (signature, structure, exp)
            // using the same provider + secret + PayloadValidator as login.
            $payload = $this->manager->decode(new Token($token));

            /** @var mixed $offer */
            $offer = $payload->get('offer');

            return is_array($offer) ? $offer : null;
        } catch (JWTException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
