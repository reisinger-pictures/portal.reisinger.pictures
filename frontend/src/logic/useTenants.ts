import useSWR from 'swr';
import { apiMutate, fetcher } from '../api';

export interface Tenant {
    id: string;
    name: string;
    domain: string | null;
    invoice_frequency: 'immediate' | 'monthly' | 'quarterly';
    users_count?: number;
    gallery_groups_count?: number;
    open_delivery_notes_count?: number;
    users?: TenantUser[];
    gallery_groups?: TenantGalleryGroup[];
}

export interface TenantUser {
    id: string;
    name: string;
    email: string;
}

export interface TenantGalleryGroup {
    id: string;
    name: string;
    parent_id: string | null;
}

export interface CollectiveInvoiceResponse {
    success: boolean;
    invoice_number: string;
    processed_orders: number;
}

export function useTenants(id?: string) {
    const listKey = !id ? '/api/management/tenants' : null;
    const detailKey = id ? `/api/management/tenants/${id}` : null;

    const { data: tenants, mutate: mutateList, isLoading: listLoading } = useSWR<Tenant[]>(listKey, fetcher);
    const { data: tenant, mutate: mutateDetail, isLoading: detailLoading } = useSWR<Tenant>(detailKey, fetcher);

    const createTenant = async (data: Partial<Tenant>) => {
        await apiMutate('/api/management/tenants', 'POST', data);
        await mutateList();
    };

    const updateTenant = async (tenantId: string, data: Partial<Tenant>) => {
        await apiMutate(`/api/management/tenants/${tenantId}`, 'PUT', data);
        await mutateList();
        await mutateDetail();
    };

    const deleteTenant = async (tenantId: string) => {
        await apiMutate(`/api/management/tenants/${tenantId}`, 'DELETE');
        await mutateList();
    };

    const syncUsers = async (tenantId: string, user_ids: string[]) => {
        await apiMutate(`/api/management/tenants/${tenantId}/users`, 'PUT', { user_ids });
        await mutateDetail();
    };

    const syncGroups = async (tenantId: string, group_ids: string[]) => {
        await apiMutate(`/api/management/tenants/${tenantId}/groups`, 'PUT', { group_ids });
        await mutateDetail();
    };

    const generateCollectiveInvoice = async (tenantId: string) => {
        const result = await apiMutate<CollectiveInvoiceResponse>(`/api/management/tenants/${tenantId}/collective-invoice`, 'POST');
        await mutateDetail();
        return result;
    };

    return {
        tenants, tenant, isLoading: listLoading || detailLoading,
        createTenant, updateTenant, deleteTenant, syncUsers, syncGroups, generateCollectiveInvoice
    };
}
