import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';
import {Gallery, GalleryGroup} from './useGalleries';

export interface Role {
    id: number;
    name: string;
}

export interface UserDetailed {
    id: number;
    name: string;
    email: string;
    can_edit_metadata: boolean;
    roles: Role[];
    gallery_groups: GalleryGroup[];
    galleries: Gallery[];
}

export interface DomainMapping {
    id: number;
    domain: string;
    role?: Role;
    gallery_group?: GalleryGroup;
}

export function useUsers() {
    // any type because Laravel wraps collections in { data: [...] }
    const {data: response, mutate: mutateUsers} = useSWR<any>('/api/management/users', fetcher);
    const {data: roles} = useSWR<Role[]>('/api/management/roles', fetcher);
    const {
        data: mappings,
        mutate: mutateMappings
    } = useSWR<DomainMapping[]>('/api/management/domain-mappings', fetcher);

    // Safely unwrap the data array
    const users: UserDetailed[] | undefined = response?.data ? response.data : response;

    const createUser = async (name: string, email: string) => {
        await apiMutate('/api/management/users', 'POST', {name, email});
        mutateUsers();
    };

    const updateUser = async (id: number, role_ids: number[], gallery_group_ids: number[], gallery_ids: number[], can_edit_metadata: boolean) => {
        await apiMutate(`/api/management/users/${id}`, 'PUT', {
            role_ids,
            gallery_group_ids,
            gallery_ids,
            can_edit_metadata
        });
        mutateUsers();
    };

    const createMapping = async (domain: string, role_id: number | null, gallery_group_id: number | null) => {
        await apiMutate('/api/management/domain-mappings', 'POST', {domain, role_id, gallery_group_id});
        mutateMappings();
    };

    const deleteMapping = async (id: number) => {
        await apiMutate(`/api/management/domain-mappings/${id}`, 'DELETE');
        mutateMappings();
    };

    return {users, roles, mappings, createUser, updateUser, createMapping, deleteMapping};
}
