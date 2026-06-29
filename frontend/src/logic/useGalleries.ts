import useSWR, { mutate as globalMutate } from 'swr';
// import removed
import {apiMutate, fetcher, Gallery} from '../api';
import {usePermissions} from './usePermissions';

// Re-export the canonical `Gallery` type so existing imports from this module keep working.
export type {Gallery};

export interface GalleryGroup {
    id: string;
    name: string;
    parent_id: string | null;
    slug?: string;
    is_public?: boolean | null;
    is_free_download?: boolean | null;
    is_editorial_only?: boolean | null;
    is_hidden?: boolean | null;
    restricted_photographers?: boolean | null;
    effective_restricted_photographers?: boolean;
    effective_is_free_download?: boolean;
    children?: GalleryGroup[];
    galleries?: Gallery[];
    tenant_id?: string | null;
}

export interface GalleryTreeResponse {
    groups: GalleryGroup[];
    root_galleries: Gallery[];
}

export interface FlatGroup {
    id: string;
    name: string;
    depth: number;
    is_public: boolean | null;
}

export const flattenGroups = (groups: GalleryGroup[], depth = 0): FlatGroup[] => {
    let flat: FlatGroup[] = [];
    for (const g of groups) {
        flat.push({id: g.id, name: g.name, depth, is_public: g.is_public ?? null});
        if (g.children) flat = flat.concat(flattenGroups(g.children, depth + 1));
    }
    return flat;
};

export interface GalleryGroupExtraOpts {
    is_free_download?: boolean;
    is_editorial_only?: boolean;
    is_hidden?: boolean;
}

export interface GalleryMetadataOpts {
    is_free_download?: boolean;
    is_editorial_only?: boolean;
    is_hidden?: boolean;
    restricted_photographers?: boolean;
    allow_client_metadata_edit?: boolean;
    apply_metadata_to_photos?: boolean;
    default_title?: string;
    default_description?: string;
    default_keywords?: string;
    default_location?: string;
    default_city?: string;
    default_state?: string;
    default_country?: string;
    default_iso_country?: string;
}

export function useProtectedGalleries() {
    const {isAdmin, isPhotographer} = usePermissions();
    const canFetch = isAdmin || isPhotographer;

    const {data, error, isLoading, mutate} = useSWR<GalleryTreeResponse>(
        canFetch ? '/api/management/galleries' : null,
        fetcher
    );

    const invalidateAll = async () => {
        await mutate();
        await globalMutate('/api/auth/me');
        await globalMutate((key) => typeof key === 'string' && key.startsWith('/api/search'), undefined, { revalidate: true });
    };

    const createGroup = async (name: string, slug: string, isPublic: boolean | null, parentId?: string | null, extraOpts?: GalleryGroupExtraOpts) => {
        await apiMutate('/api/management/gallery-groups', 'POST', { name, slug, is_public: isPublic, parent_id: parentId, ...extraOpts });
        await invalidateAll();
    };

    const updateGroup = async (id: string, name: string, slug: string, isPublic: boolean | null, parentId?: string | null, extraOpts?: GalleryGroupExtraOpts) => {
        await apiMutate('/api/management/gallery-groups/' + id, 'PUT', { name, slug, is_public: isPublic, parent_id: parentId, ...extraOpts });
        await invalidateAll();
    };

    const deleteGroup = async (id: string) => {
        await apiMutate('/api/management/gallery-groups/' + id, 'DELETE');
        await invalidateAll();
    };

    const createGallery = async (name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, groupId?: string | null, password?: string, expiresAt?: string, metadataOpts?: GalleryMetadataOpts) => {
        await apiMutate('/api/management/galleries', 'POST', { name, slug, type, is_live: isLive, is_public: isPublic, gallery_group_id: groupId, password, expires_at: expiresAt, ...metadataOpts });
        await invalidateAll();
    };

    const updateGallery = async (id: string, name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, groupId?: string | null, password?: string, expiresAt?: string, metadataOpts?: GalleryMetadataOpts) => {
        await apiMutate('/api/management/galleries/' + id, 'PUT', { name, slug, type, is_live: isLive, is_public: isPublic, gallery_group_id: groupId, password, expires_at: expiresAt, ...metadataOpts });
        await invalidateAll();
    };

    const deleteGallery = async (id: string) => {
        await apiMutate(`/api/management/galleries/${id}`, 'DELETE');
        await invalidateAll();
    };

    return {
        tree: data, isLoading, isError: error, mutate,
        createGroup, createGallery, deleteGallery, updateGroup, deleteGroup, updateGallery
    };
}
