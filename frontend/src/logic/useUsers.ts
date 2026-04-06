import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';
import {Gallery, GalleryGroup} from './useGalleries';

export interface Role { id: string; name: string; }
export interface UserDetailed {
    id: string; name: string; email: string; can_edit_metadata: boolean;
    roles: Role[]; gallery_groups: GalleryGroup[]; galleries: Gallery[];
}
export interface DomainMapping {
    id: string; domain: string; role?: Role; gallery_group?: GalleryGroup;
}

export function useUsers() {
    const {data: response, mutate: mutateUsers} = useSWR<{data: UserDetailed[]} | UserDetailed[]>('/api/management/users', fetcher);
    const {data: roles} = useSWR<Role[]>('/api/management/roles', fetcher);
    const {data: mappings, mutate: mutateMappings} = useSWR<DomainMapping[]>('/api/management/domain-mappings', fetcher);

    const users: UserDetailed[] | undefined = response ? (Array.isArray(response) ? response : response.data) : undefined;

    const createUser = async (name: string, email: string) => {
        await apiMutate('/api/management/users', 'POST', {name, email});
        await mutateUsers();
    };

    const updateUser = async (id: string, role_ids: string[], gallery_group_ids: string[], gallery_ids: string[], can_edit_metadata: boolean) => {
        await apiMutate(`/api/management/users/${id}`, 'PUT', { role_ids, gallery_group_ids, gallery_ids, can_edit_metadata });
        await mutateUsers();
    };

    const createMapping = async (domain: string, role_id: string | null, gallery_group_id: string | null) => {
        await apiMutate('/api/management/domain-mappings', 'POST', {domain, role_id, gallery_group_id});
        await mutateMappings();
    };

    const deleteMapping = async (id: string) => {
        await apiMutate(`/api/management/domain-mappings/${id}`, 'DELETE');
        await mutateMappings();
    };

    return {users, roles, mappings, createUser, updateUser, createMapping, deleteMapping};
}
