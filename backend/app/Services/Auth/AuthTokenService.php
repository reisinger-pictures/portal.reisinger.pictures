<?php

namespace App\Services\Auth;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * Issue / verify / refresh credentials behind the dormant JWT -> Sanctum flag
 * (Portal #9, Phase 2).
 *
 * The default `AUTH_DRIVER=legacy` delegates to the existing JWT flow through
 * `Auth::guard('api')`. Nothing in this service is wired into a controller,
 * middleware or route in Phase 2: the legacy path remains the only reachable
 * behaviour. The guard NAME stays `api` (D-74); only a later phase swaps its
 * driver. Credentials remain two httpOnly cookies (D-75) and `/api/auth/refresh`
 * stays the compatibility shim with the unchanged 240-minute / 7-day windows
 * (D-76) — those cookie/controller details are deliberately out of this
 * phase's scope.
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

    /**
     * @param  list<string>  $abilities
     */
    public function issue(User $user, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): string
    {
        return $this->isSanctum()
            ? $this->issueSanctum($user, $abilities, $expiresAt)
            : $this->issueLegacy($user);
    }

    public function verify(?string $credential = null): ?User
    {
        return $this->isSanctum()
            ? $this->verifySanctum($credential)
            : $this->verifyLegacy($credential);
    }

    public function refresh(?string $credential, ?DateTimeInterface $expiresAt = null): ?string
    {
        return $this->isSanctum()
            ? $this->refreshSanctum($credential, $expiresAt)
            : $this->refreshLegacy($credential);
    }

    private function issueLegacy(User $user): string
    {
        $token = Auth::guard('api')->login($user);
        if (! is_string($token)) {
            throw new RuntimeException('The legacy api guard did not return a token string.');
        }

        return $token;
    }

    /**
     * @param  list<string>  $abilities
     */
    private function issueSanctum(User $user, array $abilities, ?DateTimeInterface $expiresAt): string
    {
        $newToken = $user->createToken('api', $abilities, $expiresAt);

        return $newToken->plainTextToken;
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
        $guard = auth()->guard('sanctum');

        if ($credential !== null && method_exists($guard, 'setRequest')) {
            // A cloned request keeps current cookies/query but gets a fresh
            // header bag; a supplied credential is presented as a bearer token
            // without mutating the live request (D-75 cookie transport stays a
            // controller/middleware concern of a later phase).
            $request = request()->duplicate();
            $request->headers->set('Authorization', 'Bearer '.$credential);
            $guard->setRequest($request);
        }

        $user = $guard->user();

        return $user instanceof User ? $user : null;
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
        if ($credential === null) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($credential);
        if (! $accessToken instanceof PersonalAccessToken) {
            return null;
        }

        $tokenable = $accessToken->tokenable;
        if (! $tokenable instanceof User) {
            return null;
        }

        $abilities = $accessToken->abilities;
        if (! is_array($abilities)) {
            throw new InvalidArgumentException('Stored Sanctum abilities must be an array.');
        }

        $stringAbilities = [];
        foreach ($abilities as $ability) {
            if (! is_string($ability)) {
                throw new InvalidArgumentException('Stored Sanctum abilities must be strings.');
            }
            $stringAbilities[] = $ability;
        }

        // Rotate: the consumed credential must not be replayable (the D-76
        // shim keeps the JWT blacklist semantics).
        $accessToken->delete();

        return $this->issueSanctum($tokenable, $stringAbilities, $expiresAt);
    }
}
