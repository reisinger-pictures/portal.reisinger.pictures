<?php

namespace App\Services\Auth;

use App\Auth\SanctumTransientGuard;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * Issue / verify / revoke credentials for the `api` guard (Portal #9).
 *
 * D-77 removed the JWT package and its legacy driver entirely. The guard NAME
 * stays `api` (D-74), credentials stay two httpOnly cookies (D-75) with the
 * unchanged 240-minute access / 7-day refresh windows (D-76) read from
 * `auth.migration.access_ttl` and `auth.migration.refresh_ttl`.
 *
 * Roles are never transported in abilities (D-78): only `TransientClaims` are
 * encoded through `TransientClaimsCodec` into one `tp1:` ability, and every
 * claim is revalidated by the guard/authorization layer on resolution (D-39/D-44).
 *
 * Constructor: none. Resolving this service never touches Sanctum state or a
 * database connection.
 */
final class AuthTokenService
{
    public const DRIVER_SANCTUM = 'sanctum';

    /**
     * Kept as a stable, driver-independent read for callers and tests. The JWT
     * fallback was removed with the package (D-77), so this is always sanctum.
     */
    public function driver(): string
    {
        return self::DRIVER_SANCTUM;
    }

    public function isSanctum(): bool
    {
        return true;
    }

    public function accessTtlMinutes(): int
    {
        $ttl = config('auth.migration.access_ttl', 240);

        return is_numeric($ttl) ? max(1, (int) $ttl) : 240;
    }

    public function refreshTtlMinutes(): int
    {
        $ttl = config('auth.migration.refresh_ttl', 10080);

        return is_numeric($ttl) ? max(1, (int) $ttl) : 10080;
    }

    /**
     * Issue the 240-minute access token (D-76) named `api`.
     */
    public function issue(User $user, ?TransientClaims $claims = null): string
    {
        return $this->issueSanctum($user, $claims ?? new TransientClaims, now()->addMinutes($this->accessTtlMinutes()));
    }

    /**
     * Issue the separate 7-day refresh token (D-76).
     */
    public function issueRefresh(User $actor, ?TransientClaims $claims = null): string
    {
        return $this->issueSanctum($actor, $claims ?? new TransientClaims, now()->addMinutes($this->refreshTtlMinutes()), 'refresh');
    }

    public function verify(?string $credential = null): ?User
    {
        return $this->verifySanctum($credential);
    }

    /**
     * Rotate a credential. Controllers use `verify()` + `issueRefresh()` for
     * the `/api/auth/refresh` shim; this method remains for the Phase-2 contract.
     */
    public function refresh(?string $credential, ?DateTimeInterface $expiresAt = null): ?string
    {
        return $this->refreshSanctum($credential, $expiresAt);
    }

    public function revoke(string $token): void
    {
        PersonalAccessToken::findToken($token)?->delete();
    }

    /**
     * Read the structured transient claims carried by a credential.
     */
    public function claimsOf(?string $credential): TransientClaims
    {
        if ($credential === null || $credential === '') {
            return new TransientClaims;
        }

        $token = PersonalAccessToken::findToken($credential);
        if (! $token instanceof PersonalAccessToken) {
            return new TransientClaims;
        }

        try {
            return app(TransientClaimsCodec::class)->decode($token->abilities);
        } catch (InvalidArgumentException) {
            return new TransientClaims;
        }
    }

    /**
     * Revoke every credential the current request actually presented, using
     * the same bearer-first-then-cookie precedence as the guard. Cookie-only
     * revocation would leave a bearer credential replayable after logout or
     * invite redemption (D-75).
     */
    public function revokeFromRequest(?Request $request = null): void
    {
        $request ??= request();
        $credentials = [];
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            $credentials[] = $bearer;
        }

        foreach ([
            (string) config('auth.migration.cookie_access', 'rp_jwt'),
            (string) config('auth.migration.cookie_refresh', 'rp_jwt_refresh'),
        ] as $cookieName) {
            $value = $request->cookie($cookieName);
            if (is_string($value) && $value !== '') {
                $credentials[] = $value;
            }
        }

        foreach (array_unique($credentials) as $credential) {
            $this->revoke($credential);
        }
    }

    /**
     * Rotate a refresh credential into a fresh access/refresh pair, preserving
     * every transient claim carried by the consumed token. Dropping the claims
     * here would log a guest out on the next request and silently strip a
     * registered actor's invite grants (D-73/D-44).
     *
     * The consumed row is deleted with a conditional delete so two concurrent
     * refresh requests cannot both mint a live credential. The still-valid
     * access token is revoked too, matching the old blacklist semantics.
     *
     * @return array{access: string, refresh: string}|null
     */
    public function rotateRefresh(string $refreshToken, ?string $accessToken = null): ?array
    {
        // Full guard trust path: blacklist, live invites, reserved null-brand.
        $actor = $this->verifySanctum($refreshToken);
        if (! $actor instanceof User) {
            return null;
        }

        $refresh = PersonalAccessToken::findToken($refreshToken);
        if (! $refresh instanceof PersonalAccessToken) {
            return null;
        }

        try {
            $claims = app(TransientClaimsCodec::class)->decode($refresh->abilities);
        } catch (InvalidArgumentException) {
            return null;
        }

        // Atomic consume: exactly one concurrent refresh may proceed.
        if (PersonalAccessToken::whereKey($refresh->getKey())->delete() !== 1) {
            return null;
        }

        if (is_string($accessToken) && $accessToken !== '') {
            PersonalAccessToken::findToken($accessToken)?->delete();
        }

        return [
            'access' => $this->issueSanctum($actor, $claims, now()->addMinutes($this->accessTtlMinutes())),
            'refresh' => $this->issueSanctum($actor, $claims, now()->addMinutes($this->refreshTtlMinutes()), 'refresh'),
        ];
    }

    private function issueSanctum(
        User $user,
        TransientClaims $claims,
        DateTimeInterface $expiresAt,
        string $name = 'api',
    ): string {
        $abilities = app(TransientClaimsCodec::class)->encode($claims);

        // A persisted actor uses Sanctum's HasApiTokens path; a DB-less guest
        // has no users row, so its token is stored with the synthetic morph id
        // and never resolves through the user provider (D-73).
        if ($user->getKey() !== null && $user->exists) {
            return $user->createToken($name, $abilities, $expiresAt)->plainTextToken;
        }

        $guestId = $user->guest_id;
        if (! is_string($guestId) || $guestId === '') {
            throw new RuntimeException('A DB-less actor needs a guest_id to issue a Sanctum token.');
        }

        return $this->issueGuestToken($guestId, $name, $abilities, $expiresAt);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function issueGuestToken(string $guestId, string $name, array $abilities, DateTimeInterface $expiresAt): string
    {
        // Same shape as HasApiTokens::createToken(): sha256 hash in the table,
        // plain text `<id>|<entropy>` returned to the caller so findToken() can
        // split and verify it. Set the morph attributes explicitly because
        // PersonalAccessToken's $fillable does not include them.
        $plain = Str::random(40);
        $token = new PersonalAccessToken;
        $token->tokenable_type = User::class;
        $token->tokenable_id = 'guest_'.$guestId;
        $token->name = $name;
        $token->token = hash('sha256', $plain);
        $token->abilities = $abilities;
        $token->expires_at = $expiresAt;
        $token->save();

        return $token->getKey().'|'.$plain;
    }

    private function verifySanctum(?string $credential): ?User
    {
        $guard = app(SanctumTransientGuard::class);

        if ($credential !== null && $credential !== '') {
            return $guard->resolveCredential($credential);
        }

        return $guard->__invoke(request());
    }

    private function refreshSanctum(?string $credential, ?DateTimeInterface $expiresAt): ?string
    {
        if ($credential === null || $credential === '') {
            return null;
        }

        // Full trust check first: a reserved null-brand actor or a dead guest
        // invite must not be able to rotate a credential (D-39/D-44).
        $actor = $this->verifySanctum($credential);
        if (! $actor instanceof User) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($credential);
        if (! $accessToken instanceof PersonalAccessToken) {
            return null;
        }

        try {
            $claims = app(TransientClaimsCodec::class)->decode($accessToken->abilities);
        } catch (InvalidArgumentException) {
            return null;
        }

        // Rotate: the consumed credential must not be replayable.
        $accessToken->delete();

        return $this->issueSanctum(
            $actor,
            $claims,
            $expiresAt ?? now()->addMinutes($this->accessTtlMinutes()),
            $accessToken->name,
        );
    }
}
