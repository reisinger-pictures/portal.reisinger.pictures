<?php

namespace App\Services;

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
     * @param array       $offerPayload Arbitrary offer data (items, customer_*, terms, ...).
     * @param Carbon|null $expiresAt    Expiry; defaults to now + 14 days.
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
     * Verify a signed JWT and return its embedded offer payload.
     *
     * Returns null on any failure (bad signature, malformed, expired).
     *
     * @return array|null
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
