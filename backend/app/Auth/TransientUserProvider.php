<?php

namespace App\Auth;

use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Support\Facades\Cache;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class TransientUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
        } catch (\Exception) {
            return null;
        }

        if (is_string($identifier) && str_starts_with($identifier, 'guest_')) {
            if (Cache::has('blacklisted_'.$identifier)) {
                return null;
            }

            if ($payload) {
                $inviteIds = $this->guestInviteIdsFromPayload($payload);
                $authorization = app(AuthorizationService::class);
                $activeInviteIds = array_values(array_filter(
                    $inviteIds,
                    fn (string $inviteId): bool => $authorization->isInviteCurrentHostActive($inviteId)
                ));
                if ($activeInviteIds === []) {
                    Cache::put(
                        'blacklisted_'.$identifier,
                        true,
                        now()->addMinutes($this->inviteBlacklistTtlMinutes())
                    );

                    return null;
                }

                $user = new User;
                $user->id = null; // Explicitly null for DB-less guest
                $user->name = $payload->get('guest_name') ?? 'Gast';
                $user->guest_id = $this->guestIdFromPayload($payload, $identifier);
                $this->applyTransientClaims($user, $payload);

                return $user;
            }

            return null;
        }

        $user = parent::retrieveById($identifier);
        $authorization = app(AuthorizationService::class);

        if ($user && $authorization->isReservedNullBrandActor($user)) {
            return null;
        }

        if ($user && $payload) {
            // Registered users use the same transient claims as guests, but a
            // revoked invite only removes its grant; it must not log the user
            // out or discard unrelated account/direct-gallery access.
            $this->applyTransientClaims($user, $payload);
        }

        return $user;
    }

    /**
     * @return array<int, string>
     */
    private function guestInviteIdsFromPayload($payload): array
    {
        $ids = [];
        $add = static function (mixed $value) use (&$ids): void {
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $candidate) {
                if (is_string($candidate) || is_int($candidate)) {
                    $candidate = trim((string) $candidate);
                    if ($candidate !== '') {
                        $ids[] = $candidate;
                    }
                }
            }
        };

        $add($payload->get('guest_invite_id'));
        $add($payload->get('transient_invite_ids'));

        $records = $payload->get('transient_invites');
        if (is_array($records)) {
            foreach ($records as $key => $entry) {
                if (is_string($key)) {
                    $add($key);
                }
            }
            foreach ($records as $entry) {
                if (is_array($entry)) {
                    $add($entry['invite_id'] ?? null);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function guestIdFromPayload($payload, string $identifier): ?string
    {
        $claim = $payload->get('guest_id');
        if (is_string($claim) && trim($claim) !== '') {
            return trim($claim);
        }

        $subject = $payload->get('sub');
        if (! is_string($subject) || trim($subject) === '') {
            $subject = $identifier;
        }

        $subject = trim($subject);
        if (str_starts_with($subject, 'guest_') && trim(substr($subject, 6)) !== '') {
            return trim(substr($subject, 6));
        }

        return null;
    }

    private function applyTransientClaims(User $user, $payload): void
    {
        $claims = [
            'transient_galleries' => $payload->get('transient_galleries'),
            'transient_meta_galleries' => $payload->get('transient_meta_galleries'),
        ];

        foreach ([
            'transient_invites',
            'transient_invite_ids',
            'guest_invite_id',
        ] as $claim) {
            if ($payload->hasKey($claim)) {
                $claims[$claim] = $payload->get($claim);
            }
        }

        $sanitized = app(AuthorizationService::class)->sanitizeTransientClaims($claims);
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
