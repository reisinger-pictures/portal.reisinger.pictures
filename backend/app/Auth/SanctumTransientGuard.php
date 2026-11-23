<?php

namespace App\Auth;

use App\Models\User;
use App\Services\Auth\TransientClaims;
use App\Services\Auth\TransientClaimsCodec;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Driver for the `api` guard in `sanctum` mode (Portal #9, D-73/D-74).
 *
 * The guard NAME stays `api` (D-74); only its driver becomes `sanctum_transient`
 * when `AUTH_DRIVER=sanctum`. The credential is a Sanctum token carried in the
 * bearer header or the `rp_jwt` cookie (D-75), and both actor shapes are resolved:
 *
 * - Registered users: the token's morph resolves to a persisted `User`. Reserved
 *   null-brand actors are refused; transient claims from the token's `tp1:`
 *   ability are revalidated through `AuthorizationService::sanitizeTransientClaims()`.
 *   A revoked invite therefore removes only its grant, never logs the user out
 *   (the same registered-branch semantics as `TransientUserProvider::retrieveById`).
 * - DB-less guests: no `users` row exists (the token's `guest_<uuid>` morph
 *   resolves to null). The User is hydrated exactly as `TransientUserProvider`
 *   does today — blacklist, live-invite liveness check, sanitized claims — with
 *   no database lookup for the actor itself.
 *
 * Abilities are transport, not decision: roles stay live DB queries (D-78) and
 * every claim is revalidated on every request (D-39/D-44). `resolveCredential()`
 * is the single trust-check path shared with `AuthTokenService::verify()` so
 * verification cannot drift from the guard's own resolution.
 */
final class SanctumTransientGuard
{
    public function __construct(
        private readonly TransientClaimsCodec $codec,
        private readonly AuthorizationService $authorization,
    ) {}

    public function __invoke(Request $request): ?User
    {
        $credential = $this->credentialFromRequest($request);

        return $credential === null ? null : $this->resolveCredential($credential);
    }

    /**
     * Resolve a plain-text Sanctum token through the same trust checks the guard
     * applies to a request. Used by `AuthTokenService::verify()` for the refresh
     * shim without duplicating the guest/registered decision logic.
     */
    public function resolveCredential(string $credential): ?User
    {
        $accessToken = PersonalAccessToken::findToken($credential);
        if (! $accessToken instanceof PersonalAccessToken || ! $this->isValidAccessToken($accessToken)) {
            return null;
        }

        try {
            $claims = $this->codec->decode($accessToken->abilities);
        } catch (InvalidArgumentException) {
            // A malformed/unknown ability payload is not a credential.
            return null;
        }

        $guestId = $claims->guestId;
        if ($guestId !== null && $guestId !== '') {
            return $this->hydrateGuest($guestId, $claims);
        }

        // Guest tokens are stored with tokenable_id `guest_<uuid>` and the morph
        // resolves to null. Never query the uuid column for that synthetic id
        // (it would be an invalid uuid), and never accept a guest-shaped token
        // whose claims lost their guest identity. Mirror TransientUserProvider's
        // Str::isUuid() guard so a malformed/foreign PAT row cannot turn a
        // request into SQLSTATE 22P02.
        $tokenableId = $accessToken->getAttribute('tokenable_id');
        if (! is_string($tokenableId) || ! Str::isUuid($tokenableId)) {
            return null;
        }

        $tokenable = $accessToken->tokenable;
        if (! $tokenable instanceof User || ! $tokenable->exists) {
            return null;
        }

        return $this->hydrateRegistered($tokenable, $claims);
    }

    private function credentialFromRequest(Request $request): ?string
    {
        $cookieName = (string) config('jwt.cookie_key_name', 'rp_jwt');
        $fromHeader = $request->bearerToken();
        if (is_string($fromHeader) && $fromHeader !== '') {
            return $fromHeader;
        }

        $fromCookie = $request->cookie($cookieName);

        return is_string($fromCookie) && $fromCookie !== '' ? $fromCookie : null;
    }

    private function isValidAccessToken(PersonalAccessToken $accessToken): bool
    {
        $expiration = config('sanctum.expiration');

        if (is_numeric($expiration) && $accessToken->created_at->lt(now()->subMinutes((int) $expiration))) {
            return false;
        }

        return $accessToken->expires_at === null || ! $accessToken->expires_at->isPast();
    }

    private function hydrateGuest(string $guestId, TransientClaims $claims): ?User
    {
        $identifier = 'guest_'.$guestId;
        if (Cache::has('blacklisted_'.$identifier)) {
            return null;
        }

        // Mirror TransientUserProvider's guest branch: a guest token is a
        // capability for at least one invite that is still active on the
        // current host. Otherwise the identifier is blacklisted and refused.
        $activeInviteIds = array_values(array_filter(
            $this->inviteIdsFromClaims($claims),
            fn (string $inviteId): bool => $this->authorization->isInviteCurrentHostActive($inviteId),
        ));

        if ($activeInviteIds === []) {
            Cache::put(
                'blacklisted_'.$identifier,
                true,
                now()->addMinutes($this->inviteBlacklistTtlMinutes()),
            );

            return null;
        }

        $user = new User;
        $user->id = null; // Explicitly null for DB-less guest.
        $user->name = $claims->guestName ?? 'Gast';
        $user->guest_id = $guestId;
        $this->applyTransientClaims($user, $claims);

        return $user;
    }

    private function hydrateRegistered(User $user, TransientClaims $claims): ?User
    {
        // Mirror TransientUserProvider::retrieveById's registered branch. A
        // revoked invite removes only its grant via sanitizeTransientClaims();
        // it must not log the user out or discard account access (D-44).
        if ($this->authorization->isReservedNullBrandActor($user)) {
            return null;
        }

        $this->applyTransientClaims($user, $claims);

        return $user;
    }

    /**
     * @return array<int, string>
     */
    private function inviteIdsFromClaims(TransientClaims $claims): array
    {
        $ids = [...$claims->transientInviteIds, ...array_keys($claims->transientInvites)];

        return array_values(array_unique(array_filter(
            $ids,
            static fn (mixed $id): bool => (is_string($id) || is_int($id)) && trim((string) $id) !== '',
        )));
    }

    private function applyTransientClaims(User $user, TransientClaims $claims): void
    {
        // Revalidate against live invite state (D-39/D-44). This mirrors
        // TransientUserProvider::applyTransientClaims, which delegates to the
        // same AuthorizationService entry point. The stricter guest-only filter
        // is applied downstream by getActiveTransientGalleryIds() through
        // synchronizeTransientClaims(); the codec itself decides nothing.
        $sanitized = $this->authorization->sanitizeTransientClaims([
            'transient_galleries' => $claims->transientGalleries,
            'transient_meta_galleries' => $claims->transientMetaGalleries,
            'transient_invites' => $claims->transientInvites,
            'transient_invite_ids' => $claims->transientInviteIds,
        ]);

        $user->transient_galleries = $sanitized['transient_galleries'];
        $user->transient_meta_galleries = $sanitized['transient_meta_galleries'];
        $user->transient_invites = $sanitized['transient_invites'];
        $user->transient_invite_ids = $sanitized['transient_invite_ids'];
    }

    private function inviteBlacklistTtlMinutes(): int
    {
        $ttl = config('jwt.ttl', 240);

        return is_numeric($ttl) ? max(1, (int) $ttl) : 240;
    }
}
