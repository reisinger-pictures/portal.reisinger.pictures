<?php

namespace App\Services\Auth;

/**
 * Structured transient claims transported inside a Sanctum token's abilities
 * (Portal #9, D-73).
 *
 * TRANSPORT, NOT DECISION. This object carries no authority. Every field must
 * be revalidated against live invite state on every request by
 * `AuthorizationService::sanitizeTransientClaims()` /
 * `filterGuestTransientClaims()` (D-39, D-44). Roles are never part of this
 * payload — they stay live DB queries (D-78).
 */
final readonly class TransientClaims
{
    /**
     * @param  list<string>  $transientInviteIds
     * @param  list<string>  $transientGalleries
     * @param  list<string>  $transientMetaGalleries
     * @param  array<string, array{gallery_ids: list<string>, meta_gallery_ids: list<string>}>  $transientInvites
     */
    public function __construct(
        public ?string $guestId = null,
        public ?string $guestName = null,
        public array $transientInviteIds = [],
        public array $transientGalleries = [],
        public array $transientMetaGalleries = [],
        public array $transientInvites = [],
    ) {}
}
