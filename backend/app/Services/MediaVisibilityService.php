<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use App\Support\GalleryGroupSubtree;

/**
 * Single current-state visibility boundary for downloadable media.
 *
 * Eloquent accessors are useful for rendering, but a previously primed model
 * or entitlement cache must never decide whether bytes may be delivered.  This
 * service deliberately reloads the photo/gallery and walks the complete group
 * chain from the database on every check.
 */
class MediaVisibilityService
{
    public function currentGallery(Gallery|string $gallery): ?Gallery
    {
        $id = $gallery instanceof Gallery ? $gallery->getKey() : trim($gallery);
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Gallery::query()
            ->with('galleryGroup')
            ->find($id);
    }

    public function currentPhoto(Photo|string $photo): ?Photo
    {
        $id = $photo instanceof Photo ? $photo->getKey() : trim($photo);
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Photo::query()
            ->with(['gallery' => fn ($query) => $query->with('galleryGroup')])
            ->find($id);
    }

    public function galleryIsVisible(Gallery|string $gallery): bool
    {
        $current = $this->currentGallery($gallery);
        if (! $current instanceof Gallery) {
            return false;
        }

        return $this->galleryObjectIsVisible($current);
    }

    public function photoIsVisible(Photo|string $photo): bool
    {
        $current = $this->currentPhoto($photo);
        if (! $current instanceof Photo || (bool) $current->is_hidden) {
            return false;
        }

        $gallery = $current->gallery;
        if (! $gallery instanceof Gallery) {
            return false;
        }

        return $this->galleryObjectIsVisible($gallery);
    }

    public function requireVisibleGallery(Gallery|string $gallery): Gallery
    {
        $current = $this->currentGallery($gallery);
        if (! $current instanceof Gallery) {
            abort(404, 'Galerie nicht gefunden.');
        }
        if (! $this->galleryObjectIsVisible($current)) {
            abort(403, 'Galerie ist derzeit nicht verfügbar.');
        }

        return $current;
    }

    public function requireVisiblePhoto(Photo|string $photo): Photo
    {
        $current = $this->currentPhoto($photo);
        if (! $current instanceof Photo) {
            abort(404, 'Foto nicht gefunden.');
        }
        if ((bool) $current->is_hidden || ! ($current->gallery instanceof Gallery)) {
            abort(403, 'Foto ist derzeit nicht verfügbar.');
        }
        if (! $this->galleryObjectIsVisible($current->gallery)) {
            abort(403, 'Galerie ist derzeit nicht verfügbar.');
        }

        return $current;
    }

    private function galleryObjectIsVisible(Gallery $gallery): bool
    {
        if ((bool) $gallery->is_hidden) {
            return false;
        }

        $group = $gallery->galleryGroup;
        if ($gallery->gallery_group_id !== null && ! $group instanceof GalleryGroup) {
            return false;
        }

        $visited = [];
        $depth = 0;
        while ($group instanceof GalleryGroup) {
            // AUTH-5: bound one lookup per ancestor level so an over-deep or
            // corrupt hierarchy cannot pin the request. Cycles and over-deep
            // chains fail closed (the media is treated as not visible).
            if ($depth++ > GalleryGroupSubtree::MAX_DEPTH) {
                return false;
            }

            $id = (string) $group->getKey();
            if ($id === '' || isset($visited[$id]) || (bool) $group->is_hidden) {
                return false;
            }
            $visited[$id] = true;

            $parentId = $group->parent_id;
            if ($parentId === null || $parentId === '') {
                break;
            }

            // Do not trust a previously loaded parent relation. Re-read each
            // ancestor so a group hidden after cache/model priming is visible.
            $group = GalleryGroup::query()->find($parentId);
        }

        return true;
    }
}
