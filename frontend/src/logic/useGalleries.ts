import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';
import {useAuth} from './useAuth';

export interface Gallery {
    id: number;
    name: string;
    slug: string;
    full_path: string;
    type: 'selection' | 'delivery';
    is_live: boolean;
    is_public: boolean;
    allow_client_metadata_edit?: boolean;
    apply_metadata_to_photos?: boolean;
    default_headline?: string;
    default_title?: string;
    default_description?: string;
    default_keywords?: string;
    default_location?: string;
    default_city?: string;
    default_state?: string;
    default_country?: string;
    default_iso_country?: string;
    gallery_group_id?: number | null;
    expires_at?: string | null;
}

export interface GalleryGroup {
    id: number;
    name: string;
    parent_id: number | null;
    slug?: string;
    is_public?: boolean | null;
    children?: GalleryGroup[];
    galleries?: Gallery[];
}

export interface GalleryTreeResponse {
    groups: GalleryGroup[];
    root_galleries: Gallery[];
}

export interface FlatGroup {
    id: number;
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

export function useProtectedGalleries() {
    const {user} = useAuth();
    const canFetch = user?.is_admin || user?.is_photographer;

    const {data, error, isLoading, mutate} = useSWR<GalleryTreeResponse>(
        canFetch ? '/api/management/galleries' : null,
        fetcher
    );

    const createGroup = async (name: string, slug: string, isPublic: boolean | null, parentId?: number | null) => {
        try {
            await apiMutate('/api/management/gallery-groups', 'POST', {
                name,
                slug,
                is_public: isPublic,
                parent_id: parentId
            });
            await mutate();
        } catch (e) {
            throw new Error('Gruppe konnte nicht erstellt werden.', {cause: e});
        }
    };

    const createGallery = async (name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, groupId?: number | null, password?: string, expiresAt?: string, metadataOpts?: any) => {
        try {
            await apiMutate('/api/management/galleries', 'POST', {
                name,
                slug,
                type,
                is_live: isLive,
                is_public: isPublic,
                gallery_group_id: groupId,
                password,
                expires_at: expiresAt,
                ...metadataOpts
            });
            await mutate();
        } catch (e) {
            throw new Error('Galerie konnte nicht erstellt werden.', {cause: e});
        }
    };

    const deleteGallery = async (id: number) => {
        try {
            await apiMutate(`/api/management/galleries/${id}`, 'DELETE');
            await mutate();
        } catch (e) {
            throw new Error('Galerie konnte nicht gelöscht werden.', {cause: e});
        }
    };


    const updateGroup = async (id: number, name: string, slug: string, isPublic: boolean | null, parentId?: number | null) => {
        await apiMutate('/api/management/gallery-groups/' + id, 'PUT', { name, slug, is_public: isPublic, parent_id: parentId });
        await mutate();
    };

    const deleteGroup = async (id: number) => {
        await apiMutate('/api/management/gallery-groups/' + id, 'DELETE');
        await mutate();
    };

    const updateGallery = async (id: number, name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, groupId?: number | null, password?: string, expiresAt?: string, metadataOpts?: any) => {
        await apiMutate('/api/management/galleries/' + id, 'PUT', { name, slug, type, is_live: isLive, is_public: isPublic, gallery_group_id: groupId, password, expires_at: expiresAt, ...metadataOpts });
        await mutate();
    };

    return {
        tree: data,
        isLoading,
        isError: error,
        mutate,
        createGroup,
        createGallery,
        deleteGallery,
        updateGroup,
        deleteGroup,
        updateGallery
    };
}