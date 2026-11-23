<?php

namespace App\Auth;

use App\Models\User;
use App\Services\Auth\TransientClaims;
use App\Services\Auth\TransientClaimsCodec;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Dormant `sanctum_transient` guard (Portal #9, D-73).
 *
 * Hydrates exactly the DB-less `User` that `TransientUserProvider::retrieveById`
 * builds for a guest today — no dummy `users` row, no persistent GuestSession —
 * but reads the transient claims from the Sanctum token's abilities through
 * `TransientClaimsCodec` instead of a JWT payload. Abilities are transport, not
 * decision: the invite liveness check and `sanitizeTransientClaims()` below
 * revalidate every claim on every request (D-39/D-44). Roles stay live DB
 * queries (D-78).
 *
 * This guard is registered under `sanctum_transient` only. The `api` guard
 * keeps its `jwt` driver and no route references this guard in Phase 2, so it
 * is unreachable unless explicitly configured.
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
        if ($credential === null) {
            return null;
        }

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
        if ($guestId === null || $guestId === '') {
            // Only transient guest identities live here. Registered actors use
            // the normal provider through the eventual `api` driver swap.
            return null;
        }

        $identifier = 'guest_'.$guestId;
        if (Cache::has('blacklisted_'.$identifier)) {
            return null;
        }

        // Mirror TransientUserProvider's guest branch: a guest JWT is a
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
