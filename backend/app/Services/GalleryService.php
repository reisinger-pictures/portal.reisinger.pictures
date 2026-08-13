<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\User;
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

        $group = GalleryGroup::create([
            'name' => $data['name'],
            'slug' => $slug,
            'parent_id' => $data['parent_id'] ?? null,
            'is_public' => $data['is_public'] ?? null,
            'is_free_download' => $data['is_free_download'] ?? false,
            'is_editorial_only' => $data['is_editorial_only'] ?? false,
            'is_hidden' => $data['is_hidden'] ?? false,
            'restricted_photographers' => $data['restricted_photographers'] ?? null,
            'brand' => BrandRegistry::currentOrDefault()->value,
        ]);

        if (!empty($data['org_id'])) {
            $group->orgs()->attach($data['org_id']);
        }

        return $group;
    }

    /**
     * Update an existing gallery group.
     */
    public function updateGroup(GalleryGroup $group, array $data): GalleryGroup
    {
        $slug = !empty($data['slug']) ? Str::slug($data['slug']) : Str::slug($data['name']);
        if ($slug !== $group->slug) {
            $slug = $this->slugService->makeUnique($slug, 'gallery_groups');
        }

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

        return $group;
    }

    /**
     * Create a new gallery with all business logic applied.
     */
    public function storeGallery(array $data, ?User $user): Gallery
    {
        $slug = $this->slugService->makeUnique(
            $data['slug'] ?? $data['name'],
            'galleries'
        );

        $isPublic = $data['is_public'] ?? false;

        if (!empty($data['gallery_group_id'])) {
            $group = GalleryGroup::find($data['gallery_group_id']);
            if ($group && !is_null($group->is_public)) {
                $isPublic = $group->is_public;
            }
        }

        if (($data['type'] ?? null) === 'selection') {
            $isPublic = false;
        }

        $expiresAt = $this->parseExpiresAt($data['expires_at'] ?? null);

        $this->assertPresetForBrand($data['volume_preset_id'] ?? null);

        return DB::transaction(function () use ($data, $slug, $isPublic, $user, $expiresAt) {
            $gallery = Gallery::create([
                'name' => $data['name'],
                'slug' => $slug,
                'type' => $data['type'],
                'brand' => BrandRegistry::currentOrDefault()->value,
                'is_live' => ($data['type'] ?? null) === 'selection' ? false : ($data['is_live'] ?? false),
                'is_public' => $isPublic,
                'is_free_download' => $data['is_free_download'] ?? false,
                'is_editorial_only' => $data['is_editorial_only'] ?? false,
                'is_hidden' => $data['is_hidden'] ?? false,
                'restricted_photographers' => $data['restricted_photographers'] ?? null,
                'gallery_group_id' => $data['gallery_group_id'] ?? null,
                'password_hash' => !empty($data['password']) ? Hash::make($data['password']) : null,
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
     */
    public function updateGallery(Gallery $gallery, array $data): Gallery
    {
        if (array_key_exists('volume_preset_id', $data)) {
            $this->assertPresetForBrand($data['volume_preset_id']);
        }

        if (array_key_exists('slug', $data) && $data['slug'] !== $gallery->slug) {
            $data['slug'] = $this->slugService->makeUnique($data['slug'], 'galleries');
        }

        if (array_key_exists('expires_at', $data)) {
            $data['expires_at'] = $this->parseExpiresAt($data['expires_at']);
        }

        if (!empty($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
        }
        unset($data['password']);

        if (isset($data['type']) && $data['type'] === 'selection') {
            $data['is_live'] = false;
            $data['is_public'] = false;
        }

        foreach (['is_free_download', 'is_editorial_only', 'is_hidden'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = false;
            }
        }

        $gallery->update($data);

        $gallery->orgs()->sync($data['org_ids'] ?? []);

        $this->applyMetadataToPhotos($gallery);

        return $gallery;
    }

    /**
     * Apply gallery-level default metadata to photos that still have empty fields.
     */
    public function applyMetadataToPhotos(Gallery $gallery): void
    {
        if (!$gallery->apply_metadata_to_photos) {
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
     * Guard against cross-brand preset assignment: a gallery may only reference
     * a preset belonging to the same brand as the current host.
     */
    private function assertPresetForBrand(mixed $presetId): void
    {
        if ($presetId === null || $presetId === '') {
            return;
        }

        $preset = \App\Models\VolumePreset::find($presetId);
        $currentBrand = BrandRegistry::currentOrDefault()->value;
        $presetBrand = $preset?->brand instanceof \App\Enums\Brand ? $preset->brand->value : $preset?->brand;
        if ($preset === null || $presetBrand !== $currentBrand) {
            throw ValidationException::withMessages([
                'volume_preset_id' => 'Das gewählte Volume-Preset gehört nicht zur aktuellen Brand.',
            ]);
        }
    }
}
