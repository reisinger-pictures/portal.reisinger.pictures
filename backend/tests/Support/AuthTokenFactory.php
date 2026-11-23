<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Auth\AuthTokenService;
use App\Services\Auth\TransientClaims;
use App\Services\Auth\TransientClaimsCodec;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Test-only credential factory for the Sanctum `api` guard (Portal #9).
 *
 * It issues a real PersonalAccessToken through AuthTokenService so tests
 * exercise the production guard. The legacy claim names are kept as the
 * public input shape: they are mapped onto structured `tp1:` abilities, which
 * is where the authority lives now (D-73/D-78).
 */
final class AuthTokenFactory
{
    private function __construct(private readonly array $claims) {}

    public static function forUser(User $user, array $claims = []): string
    {
        $token = app(AuthTokenService::class)->issue($user, self::transientClaims($claims));
        // A Sanctum RequestGuard keeps the user of the PREVIOUS request in a
        // reused test app, so a second request would authenticate the wrong
        // actor. JWT's login() replaced the guard's cached user; forget it here.
        self::freshGuard();

        return $token;
    }

    /** @param array<string, mixed> $claims */
    public static function claims(array $claims): self
    {
        return new self($claims);
    }

    public function fromUser(User $user): string
    {
        return self::forUser($user, $this->claims);
    }

    /**
     * A token for a DB-less guest. The claims array must carry `guest_id` or
     * a `guest_<uuid>` `sub`, as the old JWT payload did.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function forGuest(array $claims): string
    {
        $guestId = self::guestId($claims);
        $user = new User;
        $user->id = null;
        $user->guest_id = $guestId;
        $user->name = is_string($claims['guest_name'] ?? null) ? $claims['guest_name'] : 'Gast';

        $token = app(AuthTokenService::class)->issue($user, self::transientClaims($claims));
        self::freshGuard();

        return $token;
    }

    public function asGuest(): string
    {
        return self::forGuest($this->claims);
    }

    public static function check(string $token): bool
    {
        return app(AuthTokenService::class)->verify($token) !== null;
    }

    private static function freshGuard(): void
    {
        Auth::forgetGuards();
    }

    /**
     * Read the claim set of a credential, normalised to the legacy payload keys
     * the characterization tests assert on.
     *
     * This is a read-only test aid: it does not grant authority and never
     * bypasses the guard. Sanctum claims come straight out of the stored
     * `tp1:` ability through the production codec (D-73).
     *
     * @return array<string, mixed>
     */
    public static function claimsOf(string $token): array
    {
        $record = PersonalAccessToken::findToken($token);
        if (! $record instanceof PersonalAccessToken) {
            return [];
        }

        try {
            $claims = app(TransientClaimsCodec::class)->decode($record->abilities);
        } catch (InvalidArgumentException) {
            return [];
        }

        $subject = $claims->guestId !== null
            ? 'guest_'.$claims->guestId
            : ($record->getAttribute('tokenable_id') === null
                ? null
                : (string) $record->getAttribute('tokenable_id'));

        return [
            'sub' => $subject,
            'guest_id' => $claims->guestId,
            'guest_name' => $claims->guestName,
            'transient_invite_ids' => $claims->transientInviteIds,
            'transient_galleries' => $claims->transientGalleries,
            'transient_meta_galleries' => $claims->transientMetaGalleries,
            'transient_invites' => $claims->transientInvites,
        ];
    }

    /**
     * Map the legacy JWT claim names onto the structured transient claims.
     * Only these fields ever carried portal authority; roles were never JWT
     * claims in the auth path (D-78).
     *
     * @param  array<string, mixed>  $claims
     */
    private static function transientClaims(array $claims): TransientClaims
    {
        $inviteIds = [];
        foreach (['guest_invite_id', 'transient_invite_ids'] as $key) {
            $value = $claims[$key] ?? null;
            foreach (is_array($value) ? $value : [$value] as $candidate) {
                if ((is_string($candidate) || is_int($candidate)) && trim((string) $candidate) !== '') {
                    $inviteIds[] = (string) $candidate;
                }
            }
        }

        return new TransientClaims(
            guestId: self::guestId($claims),
            guestName: is_string($claims['guest_name'] ?? null) ? $claims['guest_name'] : null,
            transientInviteIds: array_values(array_unique($inviteIds)),
            transientGalleries: self::stringList($claims['transient_galleries'] ?? []),
            transientMetaGalleries: self::stringList($claims['transient_meta_galleries'] ?? []),
            transientInvites: self::inviteRecords($claims['transient_invites'] ?? []),
        );
    }

    /** @param array<string, mixed> $claims */
    private static function guestId(array $claims): ?string
    {
        $claim = $claims['guest_id'] ?? null;
        if (is_string($claim) && trim($claim) !== '') {
            return trim($claim);
        }

        $subject = $claims['sub'] ?? null;
        if (is_string($subject) && str_starts_with($subject, 'guest_') && trim(substr($subject, 6)) !== '') {
            return trim(substr($subject, 6));
        }

        return null;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $v): string => (string) $v,
            array_filter(
                $value,
                static fn (mixed $v): bool => (is_string($v) || is_int($v)) && trim((string) $v) !== '',
            ),
        ));
    }

    /**
     * @return array<string, array{gallery_ids: list<string>, meta_gallery_ids: list<string>}>
     */
    private static function inviteRecords(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $records = [];
        foreach ($value as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)) {
                continue;
            }
            // Older JWT fixtures use the singular `gallery_id` form; keep those
            // tests meaningful when the same claims travel as structured
            // abilities in Sanctum mode.
            $galleryIds = $entry['gallery_ids'] ?? $entry['gallery_id'] ?? [];
            $metaGalleryIds = $entry['meta_gallery_ids'] ?? $entry['meta_gallery_id'] ?? [];
            $records[$key] = [
                'gallery_ids' => self::stringList($galleryIds),
                'meta_gallery_ids' => self::stringList($metaGalleryIds),
            ];
        }

        return $records;
    }
}
