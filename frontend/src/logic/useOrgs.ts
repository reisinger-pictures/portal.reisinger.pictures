import useSWR from 'swr';
import { apiMutate, fetcher } from '../api';

export interface Org {
    id: string;
    name: string;
    domain: string | null;
    invoice_frequency: 'immediate' | 'monthly' | 'quarterly';
    default_role_id?: string | null;
    default_flatrate_level?: 'none' | 'web' | 'print' | 'original';
    shared_flatrate_cents?: number | null;
    auto_join_policy?: 'immediate' | 'requires_invite' | 'disabled';
    users_count?: number;
    gallery_groups_count?: number;
    open_delivery_notes_count?: number;
    users?: OrgUser[];
    gallery_groups?: OrgGalleryGroup[];
}

export interface OrgUser {
    id: string;
    name: string;
    email: string;
}

export interface OrgGalleryGroup {
    id: string;
    name: string;
    parent_id: string | null;
}

export interface CollectiveInvoiceResponse {
    success: boolean;
    invoice_number: string;
    processed_orders: number;
}

export function useOrgs(id?: string) {
    const listKey = !id ? '/api/management/orgs' : null;
    const detailKey = id ? `/api/management/orgs/${id}` : null;

    const { data: orgs, mutate: mutateList, isLoading: listLoading } = useSWR<Org[]>(listKey, fetcher);
    const { data: org, mutate: mutateDetail, isLoading: detailLoading } = useSWR<Org>(detailKey, fetcher);

    const createOrg = async (data: Partial<Org>) => {
        await apiMutate('/api/management/orgs', 'POST', data);
        await mutateList();
    };

    const updateOrg = async (orgId: string, data: Partial<Org>) => {
        await apiMutate(`/api/management/orgs/${orgId}`, 'PUT', data);
        await mutateList();
        await mutateDetail();
    };

    const deleteOrg = async (orgId: string) => {
        await apiMutate(`/api/management/orgs/${orgId}`, 'DELETE');
        await mutateList();
    };

    const syncUsers = async (orgId: string, user_ids: string[]) => {
        await apiMutate(`/api/management/orgs/${orgId}/users`, 'PUT', { user_ids });
        await mutateDetail();
    };

    const syncGroups = async (orgId: string, group_ids: string[]) => {
        await apiMutate(`/api/management/orgs/${orgId}/groups`, 'PUT', { group_ids });
        await mutateDetail();
    };

    const generateCollectiveInvoice = async (orgId: string) => {
        const result = await apiMutate<CollectiveInvoiceResponse>(`/api/management/orgs/${orgId}/collective-invoice`, 'POST');
        await mutateDetail();
        return result;
    };

    return {
        orgs, org, isLoading: listLoading || detailLoading,
        createOrg, updateOrg, deleteOrg, syncUsers, syncGroups, generateCollectiveInvoice
    };
}
