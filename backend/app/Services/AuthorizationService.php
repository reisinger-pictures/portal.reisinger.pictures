<?php

namespace App\Services;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Exceptions\GalleryGroupBudgetExceededException;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\GalleryInvite;
use App\Models\User;
use App\Support\BrandRegistry;
use App\Support\GalleryGroupSubtree;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthorizationService
{
    /**
     * A guest is a transient, database-less identity. It must not be treated
     * as a cross-brand registered user merely because its runtime User object
     * has the default null brand.
     */
    public function isTransientGuest(User $user): bool
    {
        return $user->guest_id !== null && $user->guest_id !== '';
    }

    /**
     * Whether the model represents a persisted registered account rather than
     * a transient guest identity.
     */
    public function isPersistedRegisteredUser(User $user): bool
    {
        return ! $this->isTransientGuest($user) && $user->getKey() !== null;
    }

    /**
     * Null is a reserved brand state for a persisted Super-Admin. A legacy
     * null-brand account with any other role is invalid and must fail closed.
     */
    public function isReservedNullBrandActor(User $user): bool
    {
        return ! $this->isTransientGuest($user)
            && $this->normalizeBrand($user->brand) === null
            && ! $this->isSuperAdmin($user);
    }

    /**
     * Only a persisted, explicitly Super-Admin identity with a null brand may
     * act across brands. Transient guests are intentionally excluded.
     */
    public function isTrustedCrossBrandActor(User $user): bool
    {
        return $this->isPersistedRegisteredUser($user)
            && $this->normalizeBrand($user->brand) === null
            && $this->isSuperAdmin($user);
    }

    /**
     * Get all gallery IDs a user is allowed to access.
     * Includes direct assignments, group assignments (recursive), org integration,
     * photographer-specific access, transient galleries, and brand scoping.
     *
     * @return array<string>
     */
    public function getAllowedGalleryIds(User $user): array
    {
        // A transient guest is not a registered cross-brand actor. Its grants
        // are reduced to active invites for the current host below.
        if ($this->isTransientGuest($user)) {
            return $this->getActiveTransientGalleryIds($user);
        }

        // Never interpret a legacy null-brand account as cross-brand. This is
        // deliberately checked before role/assignment lookups so no management
        // or gallery path can accidentally widen its access.
        if ($this->isReservedNullBrandActor($user)) {
            return [];
        }

        $actorBrand = $this->normalizeBrand($user->brand);
        if ($actorBrand === null && ! $this->isTrustedCrossBrandActor($user)) {
            return [];
        }

        // A registered user's transient claims must be evaluated dynamically on
        // every request. A JWT is self-contained, so an invite deleted after it
        // was issued must not remain an effective grant.
        $transientGalleryIds = $this->getActiveTransientGalleryIds($user);

        // 1. Direct assignments
        $galleryIds = $user->galleries()->pluck('galleries.id')->toArray();

        // 2. Group assignments (recursive)
        $groupIds = $user->galleryGroups()->pluck('gallery_groups.id')->toArray();
        $allGroupIds = $this->getSubGroupIds($groupIds);

        if (! empty($allGroupIds)) {
            $groupGalleryIds = Gallery::whereIn('gallery_group_id', $allGroupIds)->pluck('id')->toArray();
            $galleryIds = array_unique(array_merge($galleryIds, $groupGalleryIds));
        }

        // 3. Org Integration (pivot group assignments)
        if ($user->org_id) {
            $orgGalleryIds = Gallery::whereHas('orgs', fn ($q) => $q->where('orgs.id', $user->org_id))->where('type', 'delivery')->pluck('id')->toArray();
            $pivotGroupIds = DB::table('gallery_group_org')->where('org_id', $user->org_id)->pluck('gallery_group_id')->toArray();

            $combinedGroupIds = $pivotGroupIds;
            $allOrgGroupIds = $this->getSubGroupIds($combinedGroupIds);

            if (! empty($allOrgGroupIds)) {
                $groupGalleryIds = Gallery::whereIn('gallery_group_id', $allOrgGroupIds)->where('type', 'delivery')->pluck('id')->toArray();
                $orgGalleryIds = array_unique(array_merge($orgGalleryIds, $groupGalleryIds));
            }
            $galleryIds = array_unique(array_merge($galleryIds, $orgGalleryIds));
        }

        if (! empty($transientGalleryIds)) {
            $galleryIds = array_unique(array_merge($galleryIds, $transientGalleryIds));
        }

        if ($this->isPhotographer($user)) {
            $buildUnrestricted = function () {
                $allGalleries = Gallery::with('galleryGroup')->get();

                return $allGalleries->filter(fn ($g) => ! $g->effective_restricted_photographers)->pluck('id')->toArray();
            };
            $unrestrictedIds = Cache::remember('unrestricted_photographer_gallery_ids', now()->addMinutes(5), $buildUnrestricted);
            $galleryIds = array_merge($galleryIds, $unrestrictedIds);

            $photogGalleryIds = $user->photographerGalleries()->pluck('galleries.id')->toArray();
            $galleryIds = array_merge($galleryIds, $photogGalleryIds);

            $photogGroupIds = $user->photographerGalleryGroups()->pluck('gallery_groups.id')->toArray();
            $allPhotogGroupIds = $this->getSubGroupIds($photogGroupIds);

            $groupGalleryIds = Gallery::whereIn('gallery_group_id', $allPhotogGroupIds)->pluck('id')->toArray();
            $galleryIds = array_merge($galleryIds, $groupGalleryIds);
        }

        $galleryIds = array_values(array_unique($galleryIds));

        // Brand scoping: brand-bound users (brand != null) see only galleries of their own brand.
        // Only a trusted null-brand Super-Admin skips this filter. Guest/org
        // gallery assignments are also filtered so an SRP user can never reach
        // a B2B gallery via stale links.
        if ($actorBrand !== null) {
            // The gallery's own brand is not enough for a brand-bound actor:
            // legacy rows can be attached to a foreign or null-brand parent.
            // Resolve the complete tree in one shared BrandRegistry helper.
            $galleryIds = Gallery::whereIn('id', $galleryIds)
                ->where('brand', $actorBrand)
                ->get()
                ->filter(fn (Gallery $gallery): bool => BrandRegistry::galleryTreeMatchesBrand($gallery, $actorBrand))
                ->pluck('id')
                ->values()
                ->all();
        }

        return $galleryIds;
    }

    /**
     * Filter transient JWT grants against the current invite lifecycle.
     *
     * Registered users can redeem more than one invite into one session. The
     * canonical `transient_invites` claim therefore keeps the invite-to-gallery
     * relationship instead of using a single guest-style invite id. The
     * gallery-level claims are retained for the existing API contract, while
     * this map makes each grant independently revocable.
     *
     * @param  array<string, mixed>  $claims
     * @param  bool  $preserveHostScopedGrants  FINAL-6: when the sanitized
     *                                          claims are about to be written into a re-issued token, a grant that is
     *                                          merely invisible from the current host must be carried through instead
     *                                          of dropped, otherwise redeeming from the wrong host would destroy the
     *                                          grant for the whole token lineage. It is still excluded from the
     *                                          effective gallery lists, so it grants nothing in this context. Leave
     *                                          this at its default for authorization-time filtering.
     * @return array{
     *     transient_galleries: array<int, string>,
     *     transient_meta_galleries: array<int, string>,
     *     transient_invites: array<string, array{gallery_ids: array<int, string>, meta_gallery_ids: array<int, string>}>,
     *     transient_invite_ids: array<int, string>
     * }
     */
    public function sanitizeTransientClaims(array $claims, bool $preserveHostScopedGrants = false): array
    {
        $galleries = $this->normalizeGalleryIds($claims['transient_galleries'] ?? []);
        $metaGalleries = $this->normalizeGalleryIds($claims['transient_meta_galleries'] ?? []);

        $hasProvenance = array_key_exists('transient_invites', $claims)
            || array_key_exists('transient_invite_ids', $claims)
            || array_key_exists('guest_invite_id', $claims);

        if (! $hasProvenance) {
            // Tokens issued before invite provenance was added remain usable for
            // their ordinary session lifetime. New tokens always carry the map.
            return [
                'transient_galleries' => $galleries,
                'transient_meta_galleries' => $metaGalleries,
                'transient_invites' => [],
                'transient_invite_ids' => [],
            ];
        }

        $records = $this->normalizeInviteRecords($claims, $galleries, $metaGalleries);
        $activeGalleries = [];
        $activeMetaGalleries = [];
        $activeInvites = [];
        $keptRecords = [];
        $keptInvites = [];

        foreach ($records as $inviteId => $record) {
            $inviteKey = (string) $inviteId;
            $recordGalleries = $this->normalizeGalleryIds($record['gallery_ids'] ?? []);

            // AUTH-2: resolve the live invite exactly like the guest branch
            // instead of trusting the self-contained claim for any invite that
            // merely exists. This applies the current-host/tree check and clamps
            // every claimed gallery to the invite's real gallery, so a foreign
            // id in a server-signed (or replayed) token can never become a
            // grant.
            //
            // FINAL-6: a revocation and a host mismatch must not be treated
            // alike. A revoked invite is a real loss of access and is dropped
            // permanently. A host mismatch is only a *viewing-context* problem:
            // persisting the stripped claim would burn the grant for the whole
            // token lineage, so a user who merely redeems from the wrong host
            // would lose it for good. In that case the record is kept as issued
            // and stays filtered at authorization time instead.
            if (! $this->isInviteActive($inviteKey)) {
                continue;
            }

            $invite = $this->activeCurrentHostInvite($inviteKey);
            if (! $invite instanceof GalleryInvite) {
                if ($preserveHostScopedGrants) {
                    $keptRecords[$inviteKey] = $record;
                    $keptInvites[] = $inviteKey;
                }

                continue;
            }

            $inviteGalleryId = (string) $invite->gallery_id;
            $recordGalleries = array_values(array_intersect($recordGalleries, [$inviteGalleryId]));
            if ($recordGalleries === []) {
                // The claim never named the invite's own gallery: grant
                // nothing for this invite.
                continue;
            }

            // Metadata permission comes from the live invite, never the claim.
            $recordMetaGalleries = $invite->can_edit_metadata ? $recordGalleries : [];

            $activeGalleries = array_merge($activeGalleries, $recordGalleries);
            $activeMetaGalleries = array_merge($activeMetaGalleries, $recordMetaGalleries);
            $activeInvites[$inviteKey] = [
                'gallery_ids' => $recordGalleries,
                'meta_gallery_ids' => $recordMetaGalleries,
            ];
        }

        // Clamp the denormalized top-level arrays to the live, active invite
        // grants as well. They are a client-visible mirror of the invite map,
        // not an independent source of access: a foreign id that only appears
        // in `transient_galleries` (with no matching active invite record) is
        // dropped. When the provenance claim cannot be decoded at all,
        // $activeGalleries stays empty and this fails closed.
        $galleries = array_values(array_unique(array_merge(
            array_values(array_intersect($galleries, $activeGalleries)),
            $activeGalleries,
        )));
        $metaGalleries = array_values(array_unique(array_merge(
            array_values(array_intersect($metaGalleries, $activeMetaGalleries)),
            $activeMetaGalleries,
        )));

        // FINAL-6: a host-scoped grant is carried through untouched so that
        // re-issuing the token on another host does not destroy it. It is
        // deliberately NOT added to the effective gallery lists above, so it
        // still grants nothing in the current viewing context.
        foreach ($keptRecords as $inviteKey => $record) {
            $keptGalleries = $this->normalizeGalleryIds($record['gallery_ids'] ?? []);
            if ($keptGalleries === []) {
                continue;
            }

            $activeInvites[$inviteKey] = [
                'gallery_ids' => $keptGalleries,
                'meta_gallery_ids' => [],
            ];
        }

        return [
            'transient_galleries' => $galleries,
            'transient_meta_galleries' => $metaGalleries,
            'transient_invites' => $activeInvites,
            'transient_invite_ids' => array_values(array_unique(array_merge(
                array_keys($activeInvites),
                $keptInvites,
            ))),
        ];
    }

    /**
     * Whether an invite is still usable as a source of transient access.
     * The cache is the fast revocation marker; the database existence check
     * also covers deletes that bypass the controller and cache expiry.
     */
    public function isInviteActive(string $inviteId): bool
    {
        if ($inviteId === '') {
            return false;
        }

        if (Cache::has('blacklisted_invite_'.$inviteId)) {
            return false;
        }

        return GalleryInvite::query()->whereKey($inviteId)->exists();
    }

    /**
     * Return an active invite only when its complete gallery tree belongs to
     * the current request host. This is the host-bound trust check used for
     * transient guest identities; existence alone is not sufficient.
     */
    public function activeCurrentHostInvite(string $inviteId): ?GalleryInvite
    {
        if (! $this->isInviteActive($inviteId)) {
            return null;
        }

        $invite = GalleryInvite::query()->with('gallery')->find($inviteId);
        if (! $invite || ! $invite->gallery) {
            return null;
        }

        if (! BrandRegistry::galleryTreeMatchesCurrent($invite->gallery)) {
            return null;
        }

        return $invite;
    }

    public function isInviteCurrentHostActive(string $inviteId): bool
    {
        return $this->activeCurrentHostInvite($inviteId) !== null;
    }

    /**
     * Return the current, non-revoked transient gallery grants and synchronize
     * the runtime User properties used by policies/controllers.
     */
    public function getActiveTransientGalleryIds(User $user): array
    {
        $this->synchronizeTransientClaims($user);

        return $user->transient_galleries ?? [];
    }

    /**
     * Return the current, non-revoked transient metadata grants.
     */
    public function getActiveTransientMetaGalleryIds(User $user): array
    {
        $this->synchronizeTransientClaims($user);

        return $user->transient_meta_galleries ?? [];
    }

    private function synchronizeTransientClaims(User $user): void
    {
        $claims = [
            'transient_galleries' => $user->transient_galleries ?? [],
            'transient_meta_galleries' => $user->transient_meta_galleries ?? [],
        ];

        // Do not add empty runtime properties to the claim array: an empty
        // property means "legacy/untracked", while an empty persisted claim
        // means "all previously tracked grants were revoked".
        if (! empty($user->transient_invites)) {
            $claims['transient_invites'] = $user->transient_invites;
        }
        if (! empty($user->transient_invite_ids)) {
            $claims['transient_invite_ids'] = $user->transient_invite_ids;
        }

        $sanitized = $this->sanitizeTransientClaims($claims);
        $user->transient_galleries = $sanitized['transient_galleries'];
        $user->transient_meta_galleries = $sanitized['transient_meta_galleries'];
        $user->transient_invites = $sanitized['transient_invites'];
        $user->transient_invite_ids = $sanitized['transient_invite_ids'];

        if ($this->isTransientGuest($user)) {
            $this->filterGuestTransientClaims($user);
        }
    }

    /**
     * A guest JWT is a capability for one or more active invite records, not
     * a general null-brand session. Re-derive the grant set from those records
     * and require the invite gallery (and its complete tree) to match the
     * current host. This also drops legacy tokens that only contain a forged
     * `transient_galleries` array without invite provenance.
     */
    private function filterGuestTransientClaims(User $user): void
    {
        $records = is_array($user->transient_invites) ? $user->transient_invites : [];
        $activeRecords = [];
        $activeGalleryIds = [];
        $activeMetaGalleryIds = [];

        foreach ($records as $inviteId => $record) {
            if (! is_string($inviteId) && ! is_int($inviteId)) {
                continue;
            }

            $inviteId = (string) $inviteId;
            $invite = $this->activeCurrentHostInvite($inviteId);
            if (! $invite || ! is_array($record)) {
                continue;
            }

            $galleryId = (string) $invite->gallery_id;
            $claimedGalleryIds = $this->normalizeGalleryIds($record['gallery_ids'] ?? []);
            if ($claimedGalleryIds !== [$galleryId]) {
                continue;
            }

            $metaGalleryIds = $this->normalizeGalleryIds($record['meta_gallery_ids'] ?? []);
            if ($metaGalleryIds !== [] && $metaGalleryIds !== [$galleryId]) {
                continue;
            }

            // Metadata permission comes from the live invite, never from a
            // client-controlled JWT array. A guest can therefore not promote a
            // read-only invite into a metadata-editing capability.
            $metaGalleryIds = $invite->can_edit_metadata ? [$galleryId] : [];

            $activeGalleryIds[] = $galleryId;
            if ($metaGalleryIds !== []) {
                $activeMetaGalleryIds[] = $galleryId;
            }
            $activeRecords[$inviteId] = [
                'gallery_ids' => [$galleryId],
                'meta_gallery_ids' => $metaGalleryIds,
            ];
        }

        $user->transient_galleries = array_values(array_unique($activeGalleryIds));
        $user->transient_meta_galleries = array_values(array_unique($activeMetaGalleryIds));
        $user->transient_invites = $activeRecords;
        $user->transient_invite_ids = array_keys($activeRecords);
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<int, string>  $fallbackGalleries
     * @param  array<int, string>  $fallbackMetaGalleries
     * @return array<string, array{gallery_ids: array<int, string>, meta_gallery_ids: array<int, string>}>
     */
    private function normalizeInviteRecords(array $claims, array $fallbackGalleries, array $fallbackMetaGalleries): array
    {
        $records = [];
        $inviteClaims = $claims['transient_invites'] ?? null;

        if (is_array($inviteClaims)) {
            foreach ($inviteClaims as $key => $entry) {
                if (is_array($entry)) {
                    $inviteId = $entry['invite_id'] ?? (is_string($key) ? $key : null);
                    $galleryIds = $this->galleryIdsFromRecord($entry);
                    if (! is_string($inviteId) && ! is_int($inviteId)) {
                        continue;
                    }
                    $inviteId = (string) $inviteId;
                    if ($galleryIds === []) {
                        continue;
                    }
                    $records[$inviteId] = [
                        'gallery_ids' => $galleryIds,
                        'meta_gallery_ids' => $this->metaGalleryIdsFromRecord($entry, $galleryIds),
                    ];

                    continue;
                }

                // Also accept the compact keyed form {inviteId: galleryId}.
                if ((is_string($key) || is_int($key)) && (is_string($entry) || is_int($entry))) {
                    $records[(string) $key] = [
                        'gallery_ids' => [(string) $entry],
                        'meta_gallery_ids' => [],
                    ];
                }
            }
        }

        $inviteIds = $this->normalizeInviteIds($claims['transient_invite_ids'] ?? null);

        // Guest tokens use a scalar claim. Registered tokens normally use the
        // map above, but supporting the scalar/list forms keeps refreshes from
        // turning a valid claim into an unscoped one.
        foreach ($inviteIds as $inviteId) {
            if (! isset($records[$inviteId])) {
                $records[$inviteId] = $this->fallbackInviteRecord(
                    $inviteId,
                    $fallbackGalleries,
                    $fallbackMetaGalleries,
                );
            }
        }

        if (array_key_exists('guest_invite_id', $claims) && ! empty($claims['guest_invite_id'])) {
            $guestInviteId = (string) $claims['guest_invite_id'];
            if (! isset($records[$guestInviteId])) {
                $records[$guestInviteId] = $this->fallbackInviteRecord(
                    $guestInviteId,
                    $fallbackGalleries,
                    $fallbackMetaGalleries,
                );
            }
        }

        return $records;
    }

    /**
     * @param  array<int, string>  $fallbackGalleries
     * @param  array<int, string>  $fallbackMetaGalleries
     * @return array{gallery_ids: array<int, string>, meta_gallery_ids: array<int, string>}
     */
    private function fallbackInviteRecord(
        string $inviteId,
        array $fallbackGalleries,
        array $fallbackMetaGalleries,
    ): array {
        if ($fallbackGalleries === []) {
            $invite = $this->activeCurrentHostInvite($inviteId);
            if ($invite) {
                $fallbackGalleries = [(string) $invite->gallery_id];
                $fallbackMetaGalleries = $invite->can_edit_metadata
                    ? $fallbackGalleries
                    : [];
            }
        }

        return [
            'gallery_ids' => $fallbackGalleries,
            'meta_gallery_ids' => $fallbackMetaGalleries,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    private function galleryIdsFromRecord(array $record): array
    {
        $ids = [];

        foreach (['gallery_ids', 'galleries'] as $key) {
            if (array_key_exists($key, $record)) {
                $ids = array_merge($ids, $this->normalizeGalleryIds($record[$key]));
            }
        }

        foreach (['gallery_id', 'gallery'] as $key) {
            if (array_key_exists($key, $record)) {
                $value = $record[$key];
                $ids = array_merge($ids, is_array($value) ? $this->normalizeGalleryIds($value) : $this->normalizeGalleryIds([$value]));
            }
        }

        return $this->normalizeGalleryIds($ids);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<int, string>  $galleryIds
     * @return array<int, string>
     */
    private function metaGalleryIdsFromRecord(array $record, array $galleryIds): array
    {
        $ids = [];

        foreach (['meta_gallery_ids', 'meta_galleries'] as $key) {
            if (array_key_exists($key, $record)) {
                $ids = array_merge($ids, $this->normalizeGalleryIds($record[$key]));
            }
        }

        if (array_key_exists('can_edit_metadata', $record) && (bool) $record['can_edit_metadata']) {
            $ids = array_merge($ids, $galleryIds);
        }

        return $this->normalizeGalleryIds($ids);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeGalleryIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            if (is_string($id) || is_int($id)) {
                $id = (string) $id;
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeInviteIds(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $ids = is_array($value) ? $value : [$value];
        $normalized = [];
        foreach ($ids as $id) {
            if (is_string($id) || is_int($id)) {
                $id = (string) $id;
                if ($id !== '') {
                    $normalized[] = $id;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Recursively get all subgroup IDs (including the parents themselves).
     *
     * Uses the canonical hierarchy traversal
     * ({@see GalleryGroupSubtree::descendantIds()}): cycle safe, depth bounded
     * and chunked. The previous hand-written recursive CTE had no depth or node
     * budget at all, so a corrupt or over-sized hierarchy could pin the request.
     *
     * Fail closed: when the node budget cannot be met, only the directly
     * authorized (and verified) seeds are returned — the traversal never
     * *widens* an authorization beyond what was granted, it only refuses to
     * expand it. The cut-off is logged. A depth cut-off additionally hides the
     * deeper levels (truncation), which can only narrow the result.
     *
     * @param  array<string>  $parentIds
     * @return array<string>
     */
    public function getSubGroupIds(array $parentIds, ?int $maxDepth = null, ?int $maxNodes = null): array
    {
        $maxDepth ??= GalleryGroupSubtree::MAX_DEPTH;
        $maxNodes ??= GalleryGroupSubtree::MAX_NODES;

        if (GalleryGroupSubtree::normalizeIds($parentIds) === []) {
            return [];
        }

        $seeds = [];

        try {
            // Unknown ids are dropped first: only a real, directly granted
            // group may be echoed back into an authorization answer.
            $seeds = GalleryGroupSubtree::existingIds($parentIds, $maxNodes);

            if ($seeds === []) {
                return [];
            }

            $descendants = GalleryGroupSubtree::descendantIds($seeds, $maxDepth, $maxNodes);
        } catch (GalleryGroupBudgetExceededException $exception) {
            Log::error('authorization.sub_group_ids_budget_exceeded', [
                'requested_count' => count($parentIds),
                'verified_count' => count($seeds),
                'limit' => $exception->limit(),
                'requested' => $exception->requested(),
                'kind' => $exception->kind(),
            ]);

            return $seeds;
        }

        return array_values(array_unique(array_merge($seeds, $descendants)));
    }

    /**
     * Check whether a user holds any of the given role names.
     */
    public function hasRole(User $user, string ...$roles): bool
    {
        return $user->roles()->whereIn('name', $roles)->exists();
    }

    /**
     * Get the role names of a user.
     *
     * @return array<string>
     */
    public function roleNames(User $user): array
    {
        return $user->roles->pluck('name')->all();
    }

    public function isSuperAdmin(User $user): bool
    {
        if ($this->isTransientGuest($user)) {
            return false;
        }

        return $user->roles()->where('name', UserRole::SUPER_ADMIN->value)->exists();
    }

    public function isAdmin(User $user): bool
    {
        if ($this->isTransientGuest($user) || $this->isReservedNullBrandActor($user)) {
            return false;
        }

        return $user->roles()->whereIn('name', [UserRole::ADMIN->value, UserRole::SUPER_ADMIN->value])->exists();
    }

    public function isPhotographer(User $user): bool
    {
        if ($this->isTransientGuest($user) || $this->isReservedNullBrandActor($user)) {
            return false;
        }

        return $user->roles()->where('name', UserRole::PHOTOGRAPHER->value)->exists();
    }

    public function isPowerUser(User $user): bool
    {
        if ($this->isTransientGuest($user) || $this->isReservedNullBrandActor($user)) {
            return false;
        }

        return $user->roles()->where('name', UserRole::POWER_USER->value)->exists();
    }

    public function isOrgAdmin(User $user): bool
    {
        if ($this->isTransientGuest($user) || $this->isReservedNullBrandActor($user)) {
            return false;
        }

        return $user->roles()->where('name', UserRole::ORG_ADMIN->value)->exists() && $user->org_id !== null;
    }

    public function isClient(User $user): bool
    {
        if ($this->isTransientGuest($user) || $this->isReservedNullBrandActor($user)) {
            return false;
        }

        return $this->hasRole($user, UserRole::CLIENT->value);
    }

    public function isPrivileged(User $user): bool
    {
        if ($this->isTransientGuest($user) || $this->isReservedNullBrandActor($user)) {
            return false;
        }

        return $this->hasRole($user, UserRole::POWER_USER->value, UserRole::ADMIN->value, UserRole::SUPER_ADMIN->value, UserRole::PHOTOGRAPHER->value);
    }

    /**
     * Mirrors User::getIsPendingAttribute(): a user without guest context and
     * without any role, gallery-group, or gallery assignment is pending.
     */
    public function isPending(User $user): bool
    {
        if ($user->guest_id || $this->isReservedNullBrandActor($user)) {
            return false;
        }

        return $user->roles()->count() === 0
            && $user->galleryGroups()->count() === 0
            && $user->galleries()->count() === 0;
    }

    /**
     * Mirrors User::canPhotographerAccessGallery().
     *
     * Brand isolation: a brand-bound user (brand != null) may only access
     * galleries of their own brand. Only a persisted Super-Admin with a null
     * brand may act across all brands.
     */
    public function canPhotographerAccessGallery(User $user, string $galleryId): bool
    {
        if ($this->isReservedNullBrandActor($user) || $this->isTransientGuest($user)) {
            return false;
        }

        if ($this->isCrossBrandSuperAdmin($user)) {
            return true;
        }

        $gallery = Gallery::find($galleryId);
        if (! $gallery) {
            return false;
        }

        if (! $this->galleryMatchesActorBrand($user, $gallery)) {
            return false;
        }

        if (! $this->sharesBrand($user, $gallery->brand)) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true;
        }
        if (! $this->isPhotographer($user)) {
            return false;
        }

        if (! $gallery->effective_restricted_photographers) {
            return true;
        }

        if ($user->photographerGalleries()->where('galleries.id', $galleryId)->exists()) {
            return true;
        }

        $groupIds = $user->photographerGalleryGroups()->pluck('gallery_groups.id')->toArray();
        if (! empty($groupIds)) {
            $allGroupIds = $this->getSubGroupIds($groupIds);
            if (in_array($gallery->gallery_group_id, $allGroupIds)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors User::canAccessGallery().
     */
    public function canAccessGallery(User $user, string $galleryId): bool
    {
        if ($this->isReservedNullBrandActor($user)) {
            return false;
        }

        // Guest access is not a null-brand role capability. It is only the
        // active invite provenance retained on this transient identity.
        if ($this->isTransientGuest($user)) {
            return in_array((string) $galleryId, $this->getActiveTransientGalleryIds($user), true);
        }

        if ($this->isCrossBrandSuperAdmin($user)) {
            return true;
        }

        $gallery = Gallery::find($galleryId);
        if (! $gallery) {
            return false;
        }

        if (! $this->galleryMatchesActorBrand($user, $gallery)) {
            return false;
        }

        if (! $this->sharesBrand($user, $gallery->brand)) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if ($this->isPhotographer($user) && $this->canPhotographerAccessGallery($user, $galleryId)) {
            return true;
        }

        return in_array($galleryId, $this->getAllowedGalleryIds($user));
    }

    /**
     * Mirrors GalleryPolicy::manage().
     *
     * Brand isolation: a brand-bound user must not manage a gallery of another
     * brand. Only a persisted Super-Admin with a null brand may act across
     * brands.
     */
    public function canManageGallery(User $user, string $galleryId): bool
    {
        if ($this->isReservedNullBrandActor($user) || $this->isTransientGuest($user)) {
            return false;
        }

        // Policies may be evaluated on a User instance that was hydrated
        // before an invite was revoked. Synchronize here as well as in
        // getAllowedGalleryIds() so metadata/manage checks cannot consume a
        // stale transient_meta_galleries claim from that instance.
        $this->synchronizeTransientClaims($user);

        if ($this->isCrossBrandSuperAdmin($user)) {
            return true;
        }

        $gallery = Gallery::find($galleryId);
        if (! $gallery) {
            return false;
        }

        if (! $this->galleryMatchesActorBrand($user, $gallery)) {
            return false;
        }

        if (! $this->sharesBrand($user, $gallery->brand)) {
            return false;
        }

        return $this->isSuperAdmin($user)
            || $this->isAdmin($user)
            || ($this->isPhotographer($user) && $this->canPhotographerAccessGallery($user, $galleryId));
    }

    /**
     * Whether a user may manage a gallery group (rename/reparent/delete,
     * sync access). This is the group-level equivalent of GalleryPolicy::manage.
     *
     * Brand-isolated and role-gated. Photographers additionally need a personal
     * group assignment or at least one manageable gallery in the group's
     * subtree; empty groups are manageable for photographers within their own
     * brand (a group has no separate ownership marker).
     */
    public function canManageGalleryGroup(User $user, GalleryGroup $group): bool
    {
        if ($this->isReservedNullBrandActor($user) || $this->isTransientGuest($user)) {
            return false;
        }

        $actorBrand = $this->normalizeBrand($user->brand);
        if ($actorBrand !== null && ! BrandRegistry::galleryGroupTreeMatchesBrand($group, $actorBrand)) {
            return false;
        }

        if (! $this->sharesBrand($user, $group->brand)) {
            return false;
        }

        if ($this->isSuperAdmin($user) || $this->isAdmin($user)) {
            return true;
        }

        if (! $this->isPhotographer($user)) {
            return false;
        }

        if ($user->photographerGalleryGroups()->where('gallery_groups.id', $group->id)->exists()) {
            return true;
        }

        $groupIds = $this->getSubGroupIds([$group->id]);
        $galleryIds = Gallery::whereIn('gallery_group_id', $groupIds)->pluck('id')->all();

        if (empty($galleryIds)) {
            return true;
        }

        foreach ($galleryIds as $galleryId) {
            if ($this->canManageGallery($user, $galleryId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply the actor's brand to a gallery and its complete group tree.
     * Only a trusted null-brand Super-Admin retains all-brand behavior; a
     * brand-bound actor must have a fully matching, non-null tree.
     */
    private function galleryMatchesActorBrand(User $user, Gallery $gallery): bool
    {
        if ($this->isTransientGuest($user) || $this->isReservedNullBrandActor($user)) {
            return false;
        }

        return $this->isTrustedCrossBrandActor($user)
            || BrandRegistry::galleryTreeMatchesBrand($gallery, $this->normalizeBrand($user->brand));
    }

    /**
     * Whether a user may act on a resource of the given brand.
     *
     * Only a trusted null-brand Super-Admin may act across all brands; every
     * brand-bound user is restricted to their own brand. A null resource brand
     * never matches a brand-bound user.
     */
    public function sharesBrand(User $user, mixed $resourceBrand): bool
    {
        if ($this->isTransientGuest($user)) {
            // Guest access is invite-scoped, not brand-scoped. The invite
            // provenance is checked by getAllowedGalleryIds()/canAccessGallery.
            return false;
        }

        if ($this->isReservedNullBrandActor($user)) {
            return false;
        }

        if ($this->isTrustedCrossBrandActor($user)) {
            return true;
        }

        return $this->normalizeBrand($resourceBrand) === $this->normalizeBrand($user->brand);
    }

    private function normalizeBrand(mixed $brand): ?string
    {
        return BrandRegistry::normalizeId($brand);
    }

    /**
     * Cross-brand super admins (brand === null) are the only actors allowed to
     * operate across brands; they short-circuit the per-gallery brand lookup.
     * A (hypothetical) brand-bound super admin is still brand-isolated.
     */
    private function isCrossBrandSuperAdmin(User $user): bool
    {
        return $this->isTrustedCrossBrandActor($user);
    }
}
