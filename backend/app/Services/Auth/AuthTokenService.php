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
 * Issue / verify / revoke credentials behind the JWT -> Sanctum flag (Portal #9).
 *
 * The default `AUTH_DRIVER=legacy` delegates to the existing JWT flow through
 * `Auth::guard('api')`; nothing there creates a Sanctum token or touches the
 * database. Only `sanctum` uses the token store and the `api` guard's custom
 * `sanctum_transient` driver. The guard NAME stays `api` (D-74) and credentials
 * stay two httpOnly cookies (D-75), with the unchanged 240-minute access /
 * 7-day refresh windows (D-76) read from `auth.migration.access_ttl` and
 * `auth.migration.refresh_ttl`.
 *
 * Roles are never transported in abilities (D-78): only `TransientClaims` are
 * encoded through `TransientClaimsCodec` into one `tp1:` ability, and every
 * claim is revalidated by the guard/authorization layer on resolution (D-39/D-44).
 *
 * Constructor: none. Resolving this service never touches Sanctum state or a
 * database connection; the driver is read per call so the legacy path is
 * trivially unchanged and the flag can be flipped without rebuilding the
 * container.
 */
final class AuthTokenService
{
    public const DRIVER_LEGACY = 'legacy';

    public const DRIVER_SANCTUM = 'sanctum';

    /**
     * Only the documented value 'sanctum' leaves the legacy path. Every other
     * value (including an unset env and a typo) means legacy.
     */
    public function driver(): string
    {
        $driver = config('auth.migration.driver', self::DRIVER_LEGACY);

        return $driver === self::DRIVER_SANCTUM ? self::DRIVER_SANCTUM : self::DRIVER_LEGACY;
    }

    public function isSanctum(): bool
    {
        return $this->driver() === self::DRIVER_SANCTUM;
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
     * Issue the access credential. Legacy returns the JWT from the guard;
     * Sanctum issues a 240-minute token (D-76) named `api`.
     */
    public function issue(User $user, ?TransientClaims $claims = null): string
    {
        return $this->isSanctum()
            ? $this->issueSanctum($user, $claims ?? new TransientClaims, now()->addMinutes($this->accessTtlMinutes()))
            : $this->issueLegacy($user);
    }

    /**
     * Issue the longer-lived refresh credential. Legacy keeps the single JWT
     * carried by both cookies; Sanctum issues a separate 7-day token (D-76).
     */
    public function issueRefresh(User $actor, ?TransientClaims $claims = null): string
    {
        return $this->isSanctum()
            ? $this->issueSanctum($actor, $claims ?? new TransientClaims, now()->addMinutes($this->refreshTtlMinutes()), 'refresh')
            : $this->issueLegacy($actor);
    }

    public function verify(?string $credential = null): ?User
    {
        return $this->isSanctum()
            ? $this->verifySanctum($credential)
            : $this->verifyLegacy($credential);
    }

    /**
     * Rotate a credential of either driver. Kept for the Phase-2 contract;
     * controllers use `verify()` + `issueRefresh()` for the shim in Sanctum mode.
     */
    public function refresh(?string $credential, ?DateTimeInterface $expiresAt = null): ?string
    {
        return $this->isSanctum()
            ? $this->refreshSanctum($credential, $expiresAt)
            : $this->refreshLegacy($credential);
    }

    /**
     * Invalidate a credential. In legacy mode the JWT blacklist is owned by
     * `AuthController::logout()` through the guard, so this is a no-op there.
     */
    public function revoke(string $token): void
    {
        if (! $this->isSanctum()) {
            return;
        }

        PersonalAccessToken::findToken($token)?->delete();
    }

    /**
     * Revoke every credential the current request actually presented, using
     * the same bearer-first-then-cookie precedence as the guard. Cookie-only
     * revocation would leave a bearer credential replayable after logout or
     * invite redemption (D-75).
     */
    public function revokeFromRequest(?Request $request = null): void
    {
        if (! $this->isSanctum()) {
            return;
        }

        $request ??= request();
        $credentials = [];
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            $credentials[] = $bearer;
        }

        foreach ([
            (string) config('jwt.cookie_key_name', 'rp_jwt'),
            (string) config('jwt.refresh_cookie_key_name', 'rp_jwt_refresh'),
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
     * Rotate a Sanctum refresh credential into a fresh access/refresh pair,
     * preserving every transient claim carried by the consumed token. Dropping
     * the claims here would log a guest out on the next request and silently
     * strip a registered actor's invite grants (D-73/D-44).
     *
     * The consumed row is deleted with a conditional delete so two concurrent
     * refresh requests cannot both mint a live credential. The still-valid
     * access token is revoked too, matching the legacy blacklist semantics.
     *
     * @return array{access: string, refresh: string}|null
     */
    public function rotateRefresh(string $refreshToken, ?string $accessToken = null): ?array
    {
        if (! $this->isSanctum()) {
            return null;
        }

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

    private function issueLegacy(User $user): string
    {
        $token = Auth::guard('api')->login($user);
        if (! is_string($token)) {
            throw new RuntimeException('The legacy api guard did not return a token string.');
        }

        return $token;
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

    private function verifyLegacy(?string $credential): ?User
    {
        $guard = Auth::guard('api');

        if ($credential !== null && method_exists($guard, 'setToken')) {
            $guard->setToken($credential);
        }

        $user = $guard->user();

        return $user instanceof User ? $user : null;
    }

    private function verifySanctum(?string $credential): ?User
    {
        $guard = app(SanctumTransientGuard::class);

        if ($credential !== null && $credential !== '') {
            return $guard->resolveCredential($credential);
        }

        return $guard->__invoke(request());
    }

    private function refreshLegacy(?string $credential): ?string
    {
        if ($credential === null) {
            return null;
        }

        $guard = Auth::guard('api');
        if (! method_exists($guard, 'setToken') || ! method_exists($guard, 'refresh')) {
            throw new RuntimeException('The legacy api guard cannot refresh a credential.');
        }

        $token = $guard->setToken($credential)->refresh();

        return is_string($token) ? $token : null;
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
