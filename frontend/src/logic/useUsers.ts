import useSWR from 'swr';
import {apiMutate, fetcher, Gallery, User} from '../api';
import {GalleryGroup} from './useGalleries';

export enum UserRole {
    SUPER_ADMIN = 'super_admin',
    ADMIN = 'admin',
    PHOTOGRAPHER = 'photographer',
    CUSTOMER_MANAGER = 'customer_manager',
    POWER_USER = 'power_user',
    CLIENT = 'client'
}

export interface Role {
    id: string;
    name: UserRole;
}

// Management-endpoint user shape. Extends the canonical auth `User` but overrides
// `roles` (rich `Role[]` objects here vs. role-name strings on the auth endpoint).
export interface UserDetailed extends Omit<User, 'roles'> {
    is_photographer: boolean;
    is_super_admin: boolean;
    can_edit_metadata: boolean;
    flatrate_level: 'none' | 'web' | 'print' | 'original';
    // Brand assignment per Policy A (A-01): 'rp' | 'atr' for client-type accounts, null for
    // staff (super_admin/admin/photographer = cross-brand).
    brand?: 'rp' | 'atr' | null;
    roles: Role[];
    gallery_groups: GalleryGroup[];
    galleries: Gallery[];
    photographer_galleries?: Gallery[];
    photographer_gallery_groups?: GalleryGroup[];
}

export interface UserListResponse {
    data: UserDetailed[];
}

export function useUsers() {
    const {
        data: response,
        mutate: mutateUsers
    } = useSWR<UserListResponse | UserDetailed[]>('/api/management/users', fetcher);
    const {data: roles} = useSWR<Role[]>('/api/management/roles', fetcher);
    const users: UserDetailed[] | undefined = response ? (Array.isArray(response) ? response : response.data) : undefined;

    const createUser = async (name: string, email: string) => {
        await apiMutate('/api/management/users', 'POST', {name, email});
        await mutateUsers();
    };

    const updateUser = async (id: string, role_ids: string[], gallery_group_ids: string[], gallery_ids: string[], can_edit_metadata: boolean, flatrate_level: string, brand: 'rp' | 'atr' | null) => {
        await apiMutate(`/api/management/users/${id}`, 'PUT', {
            role_ids,
            gallery_group_ids,
            gallery_ids,
            can_edit_metadata,
            flatrate_level,
            brand
        });
        await mutateUsers();
    };

    const deleteUser = async (id: string) => {
        await apiMutate(`/api/management/users/${id}`, 'DELETE');
        await mutateUsers();
    };

    return {users, roles, createUser, updateUser, deleteUser};
}
