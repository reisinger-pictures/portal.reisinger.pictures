import type {GalleryPricingSource} from './CartContext';
import type {GalleryGroup} from './useGalleries';
import type {Photo} from './useGallery';

/**
 * Collapse paginated meta-gallery photos to one pricing source per child
 * gallery. The server still groups the resulting sources by effective
 * mode/preset; retaining the parent group ID keeps the UI breakdown auditable
 * without treating a meta-gallery UUID as a Gallery ID.
 */
export function galleryPricingSourcesFromPhotos(photos: Photo[]): GalleryPricingSource[] {
    const sourcesByGallery = new Map<string, GalleryPricingSource>();

    for (const photo of photos) {
        const galleryId = photo.gallery_id || photo.gallery?.id;
        if (!galleryId) continue;
        addGalleryPricingSource(
            sourcesByGallery,
            galleryId,
            photo.gallery?.gallery_group_id ?? undefined,
            photo.gallery?.name,
            1,
        );
    }

    return Array.from(sourcesByGallery.values());
}

const addGalleryPricingSource = (
    sourcesByGallery: Map<string, GalleryPricingSource>,
    galleryId: string,
    galleryGroupId: string | undefined,
    galleryName: string | undefined,
    photoCount: number,
): void => {
    if (!galleryId || !Number.isSafeInteger(photoCount) || photoCount < 0) return;
    const current = sourcesByGallery.get(galleryId);
    if (current) {
        const nextPhotoCount = current.photoCount + photoCount;
        if (!Number.isSafeInteger(nextPhotoCount)) return;
        current.photoCount = nextPhotoCount;
        if (!current.galleryGroupId && galleryGroupId) {
            current.galleryGroupId = galleryGroupId;
        }
        if (!current.galleryName && galleryName) {
            current.galleryName = galleryName;
        }
        return;
    }

    sourcesByGallery.set(galleryId, {
        galleryId,
        galleryGroupId,
        galleryName,
        photoCount,
    });
};

const isRecord = (value: unknown): value is Record<string, unknown> => (
    typeof value === 'object' && value !== null && !Array.isArray(value)
);

/**
 * Parse the complete server-side source summary. A valid empty array is
 * meaningful and must not be replaced by a partial legacy fallback. Missing or
 * malformed legacy payloads return `null`, allowing the caller to use the tree
 * and currently loaded photos instead.
 */
export function galleryPricingSourcesFromPayload(payload: unknown): GalleryPricingSource[] | null {
    if (!Array.isArray(payload)) return null;

    const sources: GalleryPricingSource[] = [];
    const galleryIds = new Set<string>();
    for (const entry of payload) {
        if (!isRecord(entry)) return null;
        const galleryId = entry.gallery_id;
        const galleryGroupId = entry.gallery_group_id;
        const galleryName = entry.gallery_name;
        const photoCount = entry.photo_count;
        if (
            typeof galleryId !== 'string'
            || galleryId.length === 0
            || galleryIds.has(galleryId)
            || (galleryGroupId !== null && (typeof galleryGroupId !== 'string' || galleryGroupId.length === 0))
            || (galleryName !== undefined && typeof galleryName !== 'string')
            || typeof photoCount !== 'number'
            || !Number.isSafeInteger(photoCount)
            || photoCount < 0
        ) {
            return null;
        }
        galleryIds.add(galleryId);
        sources.push({
            galleryId,
            galleryGroupId: galleryGroupId ?? undefined,
            galleryName: galleryName || undefined,
            photoCount,
        });
    }

    return sources;
}

/**
 * Build pricing sources from the complete management tree, then add photos
 * currently loaded by the paginated aggregate view. Tree entries with zero
 * photos remain in the result so an empty volume child is not mistaken for a
 * scope-only meta-gallery.
 */
export function galleryPricingSourcesFromGroup(
    group: GalleryGroup | null | undefined,
    photos: Photo[],
): GalleryPricingSource[] {
    const sourcesByGallery = new Map<string, GalleryPricingSource>();
    const visitedGroups = new Set<string>();

    const visit = (currentGroup: GalleryGroup): void => {
        if (visitedGroups.has(currentGroup.id)) return;
        visitedGroups.add(currentGroup.id);

        for (const gallery of currentGroup.galleries ?? []) {
            addGalleryPricingSource(
                sourcesByGallery,
                gallery.id,
                gallery.gallery_group_id ?? currentGroup.id,
                gallery.name,
                0,
            );
        }
        for (const child of currentGroup.children ?? []) {
            visit(child);
        }
    };

    if (group) visit(group);
    for (const photo of photos) {
        const galleryId = photo.gallery_id || photo.gallery?.id;
        if (!galleryId) continue;
        addGalleryPricingSource(
            sourcesByGallery,
            galleryId,
            photo.gallery?.gallery_group_id ?? group?.id,
            photo.gallery?.name,
            1,
        );
    }

    return Array.from(sourcesByGallery.values());
}

/**
 * Prefer the complete, authorization-filtered source summary returned with the
 * meta-gallery response. Older backends did not include that field, so the
 * loaded tree/photos remain a compatibility fallback.
 */
export function galleryPricingSourcesForMetaGallery(
    group: GalleryGroup | null | undefined,
    photos: Photo[],
    serverPayload: unknown,
): GalleryPricingSource[] {
    return galleryPricingSourcesFromPayload(serverPayload)
        ?? galleryPricingSourcesFromGroup(group, photos);
}

/** Find the requested group in the already loaded management tree. */
export function findGalleryGroup(
    groups: GalleryGroup[],
    targetId: string | undefined,
): GalleryGroup | undefined {
    if (!targetId) return undefined;
    const visitedGroups = new Set<string>();
    const visit = (currentGroup: GalleryGroup): GalleryGroup | undefined => {
        if (visitedGroups.has(currentGroup.id)) return undefined;
        visitedGroups.add(currentGroup.id);
        if (currentGroup.id === targetId) return currentGroup;
        for (const child of currentGroup.children ?? []) {
            const found = visit(child);
            if (found) return found;
        }
        return undefined;
    };

    for (const currentGroup of groups) {
        const found = visit(currentGroup);
        if (found) return found;
    }
    return undefined;
}
