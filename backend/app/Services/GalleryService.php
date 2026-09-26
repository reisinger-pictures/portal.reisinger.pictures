<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\User;
use App\Models\VolumePreset;
use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GalleryService
{
    public function __construct(
        private SlugService $slugService,
    ) {}

    /**
     * Create a new gallery group.
     */
    public function storeGroup(array $data): GalleryGroup
    {
        $slug = $this->slugService->makeUnique(
            $data['slug'] ?? $data['name'],
            'gallery_groups'
        );

        $brand = BrandRegistry::currentOrDefault()->value;

        // A new group takes the actor's brand, so a parent from another brand
        // would immediately violate the one-brand-per-tree invariant. No cycle
        // check is needed yet: a group that does not exist cannot be an
        // ancestor of anything.
        if (($data['parent_id'] ?? null) !== null) {
            $parent = $this->findGroupOrFail($data['parent_id']);
            if (BrandRegistry::normalizeId($parent->brand) !== BrandRegistry::normalizeId($brand)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Die übergeordnete Galerie-Gruppe gehört nicht zur Brand dieser Galerie-Gruppe.',
                ]);
            }
        }

        $group = GalleryGroup::create([
            'name' => $data['name'],
            'slug' => $slug,
            'parent_id' => $data['parent_id'] ?? null,
            'is_public' => $data['is_public'] ?? null,
            'is_free_download' => $data['is_free_download'] ?? false,
            'is_editorial_only' => $data['is_editorial_only'] ?? false,
            'is_hidden' => $data['is_hidden'] ?? false,
            'restricted_photographers' => $data['restricted_photographers'] ?? null,
            'brand' => $brand,
        ]);

        if (! empty($data['org_id'])) {
            $group->orgs()->attach($data['org_id']);
        }

        return $group;
    }

    /**
     * Update an existing gallery group.
     */
    public function updateGroup(GalleryGroup $group, array $data): GalleryGroup
    {
        $slug = ! empty($data['slug']) ? Str::slug($data['slug']) : Str::slug($data['name']);
        if ($slug !== $group->slug) {
            $slug = $this->slugService->makeUnique($slug, 'gallery_groups');
        }

        $this->assertParentAssignmentIsSound($group, $data['parent_id'] ?? null);

        DB::transaction(function () use ($group, $data, $slug) {
            $group->update([
                'name' => $data['name'],
                'slug' => $slug,
                'parent_id' => $data['parent_id'] ?? null,
                'is_public' => $data['is_public'] ?? null,
                'is_free_download' => $data['is_free_download'] ?? false,
                'is_editorial_only' => $data['is_editorial_only'] ?? false,
                'is_hidden' => $data['is_hidden'] ?? false,
                'restricted_photographers' => $data['restricted_photographers'] ?? null,
            ]);

            // The group/org relationship lives in the pivot. `org_id` is an
            // optional field, so only synchronize when the caller sent it;
            // omitting it preserves the current assignment while an explicit
            // null clears the stale pivot row.
            if (array_key_exists('org_id', $data)) {
                $group->orgs()->sync(! empty($data['org_id']) ? [$data['org_id']] : []);
            }
        });

        return $group;
    }

    /**
     * Create a new gallery with all business logic applied.
     *
     * Brand invariant (P1-M15): a gallery and its group always share one brand.
     * A referenced group is authoritative for the new gallery's brand; a
     * client-supplied `brand` is never read (it is not part of the validated
     * payload either). See {@see self::resolveAuthoritativeGroupBrand()}.
     */
    public function storeGallery(array $data, ?User $user): Gallery
    {
        $slug = $this->slugService->makeUnique(
            $data['slug'] ?? $data['name'],
            'galleries'
        );

        $isSelection = ($data['type'] ?? null) === 'selection';
        $isPublic = $isSelection ? false : ($data['is_public'] ?? false);
        $isFreeDownload = $isSelection ? false : ($data['is_free_download'] ?? false);

        $brand = BrandRegistry::currentOrDefault()->value;

        if (! empty($data['gallery_group_id'])) {
            $group = $this->findGroupOrFail($data['gallery_group_id']);
            // The group dictates the brand — not the request host. Only the
            // create path may legitimately have no actor at all, and that
            // state is routed through an explicitly named, narrowed helper
            // instead of silently skipping the identity/brand gate.
            $brand = $user instanceof User
                ? $this->resolveAuthoritativeGroupBrand($group, $user)
                : $this->resolveGroupBrandForActorlessCreate($group);

            if (! is_null($group->is_public)) {
                $isPublic = $isSelection ? false : $group->is_public;
            }
        }

        if ($isSelection) {
            // A free-download group must not turn a selection gallery into an
            // unrestricted original-download surface.
            $isPublic = false;
            $isFreeDownload = false;
        }

        $expiresAt = $this->parseExpiresAt($data['expires_at'] ?? null);

        // Media/licensing references must belong to the brand the gallery is
        // actually persisted with — not to the request host.
        $this->assertPresetForBrand($data['volume_preset_id'] ?? null, $brand);

        return DB::transaction(function () use ($data, $slug, $isPublic, $isFreeDownload, $user, $expiresAt, $brand) {
            $gallery = Gallery::create([
                'name' => $data['name'],
                'slug' => $slug,
                'type' => $data['type'],
                'brand' => $brand,
                'is_live' => ($data['type'] ?? null) === 'selection' ? false : ($data['is_live'] ?? false),
                'is_public' => $isPublic,
                'is_free_download' => $isFreeDownload,
                'is_editorial_only' => $data['is_editorial_only'] ?? false,
                'is_hidden' => $data['is_hidden'] ?? false,
                'restricted_photographers' => $data['restricted_photographers'] ?? null,
                'gallery_group_id' => $data['gallery_group_id'] ?? null,
                'password_hash' => ! empty($data['password']) ? Hash::make($data['password']) : null,
                'expires_at' => $expiresAt,
                'allow_client_metadata_edit' => $data['allow_client_metadata_edit'] ?? false,
                'apply_metadata_to_photos' => $data['apply_metadata_to_photos'] ?? false,
                'default_title' => $data['default_title'] ?? null,
                'default_description' => $data['default_description'] ?? null,
                'default_keywords' => $data['default_keywords'] ?? null,
                'default_location' => $data['default_location'] ?? null,
                'default_city' => $data['default_city'] ?? null,
                'default_state' => $data['default_state'] ?? null,
                'default_country' => $data['default_country'] ?? null,
                'default_iso_country' => $data['default_iso_country'] ?? null,
                'licensing_mode' => $data['licensing_mode'] ?? null,
                'volume_preset_id' => $data['volume_preset_id'] ?? null,
            ]);

            if ($user && $user->is_photographer) {
                $user->photographerGalleries()->syncWithoutDetaching([$gallery->id]);
            }

            $gallery->orgs()->sync($data['org_ids'] ?? []);

            return $gallery;
        }, 3);
    }

    /**
     * Update an existing gallery with all business logic applied.
     *
     * Re-parenting is the second door into the P1-M15 incoherence, so it is
     * guarded exactly like the create path: the target group is authoritative
     * for the gallery's brand. A client-supplied `brand` is never read.
     *
     * `$user` is a **required, non-nullable** argument on purpose. The update
     * is only reachable through an authenticated management request, so an
     * "unknown actor" state does not exist here — and an earlier optional
     * `?User $user = null` default silently disabled every identity and brand
     * check for any caller that forgot the argument: re-parenting an `srp`
     * gallery into an `rp` group then *adopted* `rp` instead of being
     * rejected. Omitting the argument is now a `TypeError`/`ArgumentCountError`
     * instead of a silent fail-open.
     */
    public function updateGallery(Gallery $gallery, array $data, User $user): Gallery
    {
        $currentBrand = BrandRegistry::normalizeId($gallery->brand)
            ?? BrandRegistry::currentOrDefault()->value;

        $targetBrand = null;
        $targetGroup = null;

        if (array_key_exists('gallery_group_id', $data) && ! empty($data['gallery_group_id'])) {
            $targetGroup = $this->findGroupOrFail($data['gallery_group_id']);
            $targetBrand = $this->resolveAuthoritativeGroupBrand($targetGroup, $user);
        }

        $resultingBrand = $targetBrand ?? $currentBrand;

        // The preset guard is evaluated against the brand the gallery will carry
        // afterwards. A cross-brand move without an explicit preset is checked
        // too, so the update cannot strand a foreign preset on the new brand.
        if (array_key_exists('volume_preset_id', $data)
            || ($targetBrand !== null && $targetBrand !== $currentBrand)) {
            $effectivePresetId = array_key_exists('volume_preset_id', $data)
                ? $data['volume_preset_id']
                : $gallery->volume_preset_id;
            $this->assertPresetForBrand($effectivePresetId, $resultingBrand);
        }

        if ($targetBrand !== null) {
            $data['brand'] = $targetBrand;
        } else {
            // A client-supplied brand is never trusted — not even on a partial
            // update that does not re-parent. The authoritative value is always
            // derived from the group (or left untouched on the current row).
            unset($data['brand']);
        }

        if (array_key_exists('slug', $data)) {
            if ($data['slug'] === null || $data['slug'] === '') {
                // A null/empty slug means "no change" — never null out the column
                // or pass null into SlugService::makeUnique(string ...).
                unset($data['slug']);
            } elseif ($data['slug'] !== $gallery->slug) {
                $data['slug'] = $this->slugService->makeUnique($data['slug'], 'galleries');
            }
        }

        if (array_key_exists('expires_at', $data)) {
            $data['expires_at'] = $this->parseExpiresAt($data['expires_at']);
        }

        if (! empty($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
        }
        unset($data['password']);

        $effectiveType = $data['type'] ?? $gallery->type;
        $effectiveGroupId = array_key_exists('gallery_group_id', $data)
            ? $data['gallery_group_id']
            : $gallery->gallery_group_id;

        // Keep create/update visibility semantics identical: a meta-gallery
        // policy is copied onto the gallery and overrides the submitted flag.
        // A null policy remains non-enforcing, so the user's explicit value is
        // preserved. A re-parenting request already resolved (and validated)
        // the group above, so it is not looked up twice.
        if ($effectiveType !== 'selection' && ! empty($effectiveGroupId)) {
            $group = $targetGroup ?? GalleryGroup::find($effectiveGroupId);
            if ($group?->is_public !== null) {
                $data['is_public'] = (bool) $group->is_public;
            }
        }

        if ($effectiveType === 'selection') {
            $data['is_live'] = false;
            $data['is_public'] = false;
            $data['is_free_download'] = false;
        }

        foreach (['is_free_download', 'is_editorial_only', 'is_hidden'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = false;
            }
        }

        $gallery->update($data);

        // `org_ids` is optional ("sometimes"): only touch the pivot when the
        // caller actually sent the key, otherwise a partial update would detach
        // every org assignment.
        if (array_key_exists('org_ids', $data)) {
            $gallery->orgs()->sync($data['org_ids'] ?? []);
        }

        $this->applyMetadataToPhotos($gallery);

        return $gallery;
    }

    /**
     * Apply gallery-level default metadata to photos that still have empty fields.
     */
    public function applyMetadataToPhotos(Gallery $gallery): void
    {
        if (! $gallery->apply_metadata_to_photos) {
            return;
        }

        $gallery->photos()->chunkById(100, function ($photos) use ($gallery) {
            $hasUpdates = false;
            foreach ($photos as $photo) {
                $changed = false;

                $fields = [
                    'title' => 'default_title',
                    'description' => 'default_description',
                    'keywords' => 'default_keywords',
                    'location' => 'default_location',
                    'city' => 'default_city',
                    'state' => 'default_state',
                    'country' => 'default_country',
                    'iso_country' => 'default_iso_country',
                ];

                foreach ($fields as $photoField => $galleryField) {
                    if (empty($photo->{$photoField}) && $gallery->{$galleryField}) {
                        $photo->{$photoField} = $gallery->{$galleryField};
                        $changed = true;
                    }
                }

                if ($changed) {
                    $photo->save();
                    $hasUpdates = true;
                }
            }

            if ($hasUpdates) {
                $photos->searchable();
            }
        });
    }

    /**
     * Parse an expires_at value into a Carbon instance (end of day).
     * Returns null for empty values, throws ValidationException on invalid format.
     */
    private function parseExpiresAt(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->endOfDay();
        } catch (\Exception $e) {
            throw ValidationException::withMessages(['expires_at' => 'Ungültiges Datumsformat.']);
        }
    }

    /**
     * Load the referenced group, failing closed instead of silently persisting a
     * gallery with a dangling `gallery_group_id`.
     *
     * @throws ValidationException
     */
    private function findGroupOrFail(mixed $groupId): GalleryGroup
    {
        $group = GalleryGroup::find($groupId);

        if (! $group instanceof GalleryGroup) {
            throw ValidationException::withMessages([
                'gallery_group_id' => 'Die gewählte Galerie-Gruppe existiert nicht.',
            ]);
        }

        return $group;
    }

    /**
     * Resolve and validate the brand a gallery must carry when it is attached to
     * the given group (P1-M15: gallery and group always share one brand).
     *
     * The group is authoritative. The acting user may only *select* a group,
     * never a brand — a client-supplied `brand` field is never read anywhere in
     * this service.
     *
     * The actor is **required and non-nullable**: no caller can skip the
     * identity and brand checks, and the guest / reserved-null-brand rejections
     * below are evaluated unconditionally.
     *
     * Rejected with a 422 (never silently persisted):
     * - a group without a brand (see {@see self::requireGroupBrand()}),
     * - a transient guest or a reserved null-brand non-Super-Admin actor,
     * - a group of a foreign brand for a brand-bound actor — a brand-bound
     *   Super-Admin included, since it is not a trusted cross-brand actor,
     * - a group whose complete parent chain is not coherent in that brand, which
     *   would produce a gallery that is invisible in every brand-scoped listing
     *   (`BrandRegistry::galleryGroupTreeMatchesBrand`),
     * - a same-brand group the actor may not manage (AUTH-1): brand coherence
     *   alone must not let a photographer attach their own gallery to another
     *   user's subtree and inherit its `is_public`/visibility policy. The same
     *   `canManageGalleryGroup()` capability the group endpoints enforce is
     *   required; Super-Admins keep their reach because the check passes for
     *   them.
     *
     * A trusted cross-brand Super-Admin (`brand === null`) keeps its documented
     * all-brand access: the gallery adopts the group's brand instead of being
     * rejected, which is both the current UX and the stricter outcome.
     *
     * @throws ValidationException
     */
    private function resolveAuthoritativeGroupBrand(GalleryGroup $group, User $actor): string
    {
        $groupBrand = $this->requireGroupBrand($group);

        $authorization = app(AuthorizationService::class);

        if ($authorization->isTransientGuest($actor) || $authorization->isReservedNullBrandActor($actor)) {
            throw ValidationException::withMessages([
                'gallery_group_id' => 'Diese Identität darf keine Galerie-Gruppe referenzieren.',
            ]);
        }

        if (! $authorization->isTrustedCrossBrandActor($actor)
            && BrandRegistry::normalizeId($actor->brand) !== $groupBrand) {
            throw ValidationException::withMessages([
                'gallery_group_id' => 'Die gewählte Galerie-Gruppe gehört nicht zur Brand des angemeldeten Nutzers.',
            ]);
        }

        $this->assertGroupTreeMatchesBrand($group, $groupBrand);

        // AUTH-1: brand coherence is necessary but not sufficient. Without this
        // check any same-brand photographer could attach their own gallery to a
        // same-brand group they have no assignment to and inherit that group's
        // visibility policy, polluting another user's subtree. Reuse the same
        // capability the group mutation endpoints require. Admins and
        // Super-Admins pass inside canManageGalleryGroup(); the actorless create
        // path is deliberately routed through resolveGroupBrandForActorlessCreate().
        if (! $authorization->canManageGalleryGroup($actor, $group)) {
            throw ValidationException::withMessages([
                'gallery_group_id' => 'Keine Berechtigung für die gewählte Galerie-Gruppe.',
            ]);
        }

        return $groupBrand;
    }

    /**
     * The one remaining actorless path: {@see self::storeGallery()} may be
     * called without an actor (a system/root gallery), so only the
     * group-internal invariants are checked there. Deliberately narrow and
     * explicitly named — the identity/brand gate is not skipped implicitly, it
     * is bypassed knowingly and only on the create path.
     *
     * @throws ValidationException
     */
    private function resolveGroupBrandForActorlessCreate(GalleryGroup $group): string
    {
        $groupBrand = $this->requireGroupBrand($group);

        $this->assertGroupTreeMatchesBrand($group, $groupBrand);

        return $groupBrand;
    }

    /**
     * @throws ValidationException
     */
    /**
     * Refuse a parent assignment that would break the group hierarchy.
     *
     * The brand chain is already validated in GroupRequest via
     * Rule::exists(...)->where('brand', $brand), but that rule is skipped
     * whenever the group has no normalizable brand, and a request class is the
     * wrong place for a structural invariant: anything that reaches the service
     * can bypass it. The check lives here as well so the invariant holds for
     * every caller.
     *
     * The cycle guard matters even though the subtree traversal is already
     * cycle-safe (GalleryGroupSubtree tracks visited ids, so it terminates).
     * A cycle is not a hang, it is a corrupted hierarchy: the recursive group
     * tree in the frontend has no meaningful rendering for it, and the
     * structure stops being a tree at all.
     */
    private function assertParentAssignmentIsSound(GalleryGroup $group, mixed $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = $this->findGroupOrFail($parentId);
        $groupBrand = $this->requireGroupBrand($group);

        if (BrandRegistry::normalizeId($parent->brand) !== $groupBrand) {
            throw ValidationException::withMessages([
                'parent_id' => 'Die übergeordnete Galerie-Gruppe gehört nicht zur Brand dieser Galerie-Gruppe.',
            ]);
        }

        // Walk up from the candidate parent. Reaching the group being moved
        // means the new parent is one of its own descendants, so the move
        // would close a loop.
        $seen = [];
        $cursor = $parent;
        while ($cursor instanceof GalleryGroup) {
            $cursorId = (string) $cursor->id;

            if ($cursorId === (string) $group->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Eine Galerie-Gruppe kann nicht unter ihre eigene Untergruppe verschoben werden.',
                ]);
            }

            if (isset($seen[$cursorId])) {
                // A cycle already exists further up. Refuse rather than extend
                // it, and stop so this walk cannot spin either.
                break;
            }
            $seen[$cursorId] = true;

            $cursor = $cursor->parent_id !== null ? GalleryGroup::find($cursor->parent_id) : null;
        }
    }

    private function requireGroupBrand(GalleryGroup $group): string
    {
        $groupBrand = BrandRegistry::normalizeId($group->brand);

        if ($groupBrand === null) {
            throw ValidationException::withMessages([
                'gallery_group_id' => 'Die gewählte Galerie-Gruppe hat keine Brand und kann keine Galerie aufnehmen.',
            ]);
        }

        return $groupBrand;
    }

    /**
     * @throws ValidationException
     */
    private function assertGroupTreeMatchesBrand(GalleryGroup $group, string $groupBrand): void
    {
        if (! BrandRegistry::galleryGroupTreeMatchesBrand($group, $groupBrand)) {
            throw ValidationException::withMessages([
                'gallery_group_id' => 'Die Galerie-Gruppe und ihre übergeordneten Ordner gehören nicht zu derselben Brand.',
            ]);
        }
    }

    /**
     * Guard against cross-brand preset assignment: a gallery may only reference
     * a volume preset belonging to the brand the gallery is actually persisted
     * with. The brand is passed in explicitly because it is the gallery's
     * authoritative brand, which — for a group-attached gallery — is the
     * group's brand and not the request host brand.
     *
     * @throws ValidationException
     */
    private function assertPresetForBrand(mixed $presetId, string $galleryBrand): void
    {
        if ($presetId === null || $presetId === '') {
            return;
        }

        $preset = VolumePreset::find($presetId);
        $presetBrand = BrandRegistry::normalizeId($preset?->brand);

        if ($preset === null || $presetBrand !== $galleryBrand) {
            throw ValidationException::withMessages([
                'volume_preset_id' => 'Das gewählte Volume-Preset gehört nicht zur Brand der Galerie.',
            ]);
        }
    }
}
