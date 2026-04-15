import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';
import {Gallery, GalleryGroup} from './useGalleries';

export interface Role { id: string; name: string; }

export interface UserDetailed {
    id: string; 
    name: string; 
    email: string; 
    is_photographer?: boolean;
    is_super_admin?: boolean;
    can_edit_metadata: boolean;
    flatrate_level: 'none' | 'web' | 'print' | 'original';
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
    const {data: response, mutate: mutateUsers} = useSWR<UserListResponse | UserDetailed[]>('/api/management/users', fetcher);
    const {data: roles} = useSWR<Role[]>('/api/management/roles', fetcher);
        const users: UserDetailed[] | undefined = response ? (Array.isArray(response) ? response : response.data) : undefined;

    const createUser = async (name: string, email: string) => {
        await apiMutate('/api/management/users', 'POST', {name, email});
        await mutateUsers();
    };

    const updateUser = async (id: string, role_ids: string[], gallery_group_ids: string[], gallery_ids: string[], can_edit_metadata: boolean, flatrate_level: string) => {
        await apiMutate(`/api/management/users/${id}`, 'PUT', { role_ids, gallery_group_ids, gallery_ids, can_edit_metadata, flatrate_level });
        await mutateUsers();
    };

    const deleteUser = async (id: string) => {
        await apiMutate(`/api/management/users/${id}`, 'DELETE');
        await mutateUsers();
    };

    return {users, roles, createUser, updateUser, deleteUser};
}
