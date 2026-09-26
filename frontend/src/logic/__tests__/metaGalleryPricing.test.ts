import {describe, expect, it} from 'vitest';
import type {Gallery} from '../../api';
import {
    galleryPricingSourcesForMetaGallery,
    galleryPricingSourcesFromPayload,
    galleryPricingSourcesFromPhotos,
} from '../metaGalleryPricing';
import type {GalleryGroup} from '../useGalleries';
import type {Photo} from '../useGallery';

const gallery = (id: string, name: string, groupId: string): Gallery => ({
    id,
    name,
    slug: id,
    full_path: `parent/${id}`,
    type: 'delivery',
    is_live: false,
    is_public: true,
    gallery_group_id: groupId,
});

const photo = (id: string, galleryId: string, groupId: string): Photo => ({
    id,
    gallery_id: galleryId,
    filename: `${id}.jpg`,
    lr_uuid: `${id}-uuid`,
    width: 1200,
    height: 800,
    url: `/${id}.jpg`,
    thumb_url: `/${id}-thumb.jpg`,
    rating: 0,
    comment: '',
    gallery: gallery(galleryId, galleryId, groupId),
});

describe('meta-gallery pricing source compatibility', () => {
    it('parses a complete server source summary', () => {
        expect(galleryPricingSourcesFromPayload([
            {
                gallery_id: 'gallery-a',
                gallery_group_id: 'group-a',
                gallery_name: 'Gallery A',
                photo_count: 12,
            },
            {
                gallery_id: 'gallery-b',
                gallery_group_id: null,
                gallery_name: '',
                photo_count: 0,
            },
        ])).toEqual([
            {
                galleryId: 'gallery-a',
                galleryGroupId: 'group-a',
                galleryName: 'Gallery A',
                photoCount: 12,
            },
            {
                galleryId: 'gallery-b',
                galleryGroupId: undefined,
                galleryName: undefined,
                photoCount: 0,
            },
        ]);
    });

    it('uses the embedded gallery relation for a legacy photo without a top-level id', () => {
        const legacyPhoto = photo('legacy-photo', 'legacy-gallery', 'legacy-group');
        legacyPhoto.gallery_id = '';

        expect(galleryPricingSourcesFromPhotos([legacyPhoto])).toEqual([{
            galleryId: 'legacy-gallery',
            galleryGroupId: 'legacy-group',
            galleryName: 'legacy-gallery',
            photoCount: 1,
        }]);
    });

    it.each([
        undefined,
        null,
        {},
        [{gallery_id: 'gallery-a', gallery_group_id: 'group-a', gallery_name: 'A', photo_count: -1}],
        [{gallery_id: 'gallery-a', gallery_group_id: 'group-a', gallery_name: 'A', photo_count: 1.5}],
        [
            {gallery_id: 'gallery-a', gallery_group_id: 'group-a', gallery_name: 'A', photo_count: 1},
            {gallery_id: 'gallery-a', gallery_group_id: 'group-b', gallery_name: 'B', photo_count: 1},
        ],
    ])('rejects missing or malformed legacy summaries', payload => {
        expect(galleryPricingSourcesFromPayload(payload)).toBeNull();
    });

    it('keeps a valid empty server summary authoritative', () => {
        const group: GalleryGroup = {
            id: 'meta-group',
            name: 'Meta group',
            parent_id: null,
            galleries: [gallery('stale-gallery', 'Stale gallery', 'meta-group')],
        };

        expect(galleryPricingSourcesForMetaGallery(
            group,
            [photo('stale-photo', 'stale-gallery', 'meta-group')],
            [],
        )).toEqual([]);
    });

    it('falls back to nested tree entries and loaded photos when the summary is absent', () => {
        const group: GalleryGroup = {
            id: 'meta-group',
            name: 'Meta group',
            parent_id: null,
            galleries: [gallery('direct-gallery', 'Direct gallery', 'meta-group')],
            children: [{
                id: 'nested-group',
                name: 'Nested group',
                parent_id: 'meta-group',
                galleries: [gallery('nested-gallery', 'Nested gallery', 'nested-group')],
            }],
        };

        expect(galleryPricingSourcesForMetaGallery(group, [
            photo('direct-photo', 'direct-gallery', 'meta-group'),
            photo('nested-photo', 'nested-gallery', 'nested-group'),
            photo('legacy-photo', 'legacy-gallery', 'legacy-group'),
        ], undefined)).toEqual([
            {
                galleryId: 'direct-gallery',
                galleryGroupId: 'meta-group',
                galleryName: 'Direct gallery',
                photoCount: 1,
            },
            {
                galleryId: 'nested-gallery',
                galleryGroupId: 'nested-group',
                galleryName: 'Nested gallery',
                photoCount: 1,
            },
            {
                galleryId: 'legacy-gallery',
                galleryGroupId: 'legacy-group',
                galleryName: 'legacy-gallery',
                photoCount: 1,
            },
        ]);
    });

    it('uses the legacy fallback for a malformed negative source summary', () => {
        const group: GalleryGroup = {
            id: 'meta-group',
            name: 'Meta group',
            parent_id: null,
            galleries: [gallery('empty-gallery', 'Empty gallery', 'meta-group')],
        };

        expect(galleryPricingSourcesForMetaGallery(group, [], [
            {
                gallery_id: 'broken-gallery',
                gallery_group_id: 'meta-group',
                gallery_name: 'Broken gallery',
                photo_count: -1,
            },
        ])).toEqual([{
            galleryId: 'empty-gallery',
            galleryGroupId: 'meta-group',
            galleryName: 'Empty gallery',
            photoCount: 0,
        }]);
    });
});
