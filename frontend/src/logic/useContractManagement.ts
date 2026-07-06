import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';

export interface BillingDetails {
    name?: string;
    company?: string;
    street?: string;
    zip?: string;
    city?: string;
    country?: string;
    email?: string;
    uid?: string;
    birthdate?: string;
}

export interface ContractSigner {
    id: string;
    contract_id: string;
    name: string;
    email: string;
    roles: string[];
    personal_token: string;
    status: 'invited' | 'joined' | 'signed';
    signed_at: string | null;
    created_at: string;
}

export interface Contract {
    id: string;
    status: 'draft' | 'active' | 'closed' | 'cancelled';
    billing_details: BillingDetails | null;
    items: ContractItem[];
    discounts: ContractItem[];
    terms_html: string;
    available_roles: string[];
    allow_multiple_roles_per_signer: boolean;
    join_token: string | null;
    closes_at: string | null;
    created_at: string;
    updated_at: string;
    signers?: ContractSigner[];
}

export interface ContractItem {
    type: 'item' | 'discount_fixed' | 'discount_percent';
    description: string;
    notes: string;
    qty: number;
    price: number;
    row_total?: number;
}

export interface ContractFormData {
    items: ContractItem[];
    discounts: ContractItem[];
    terms_html: string;
    available_roles: string[];
    allow_multiple_roles_per_signer: boolean;
    billing_details: BillingDetails;
    closes_at: string;
}

export function useContracts() {
    const {data, error, isLoading, mutate} = useSWR<Contract[]>('/api/management/contracts', fetcher);
    return {contracts: data ?? [], error, isLoading, mutate};
}

export function useContract(id: string | null) {
    const {data, error, isLoading, mutate} = useSWR<Contract>(
        id ? `/api/management/contracts/${id}` : null,
        fetcher
    );
    return {contract: data, error, isLoading, mutate};
}

export async function createContract(formData: ContractFormData): Promise<Contract> {
    const response = await apiMutate<{ success: boolean; contract: Contract }>('/api/management/contracts', 'POST', formData);
    return response.contract;
}

export async function updateContract(id: string, formData: Partial<ContractFormData>): Promise<Contract> {
    const response = await apiMutate<{ success: boolean; contract: Contract }>(`/api/management/contracts/${id}`, 'PUT', formData);
    return response.contract;
}

export async function openContract(id: string): Promise<{ success: boolean; join_link: string; contract: Contract }> {
    return apiMutate(`/api/management/contracts/${id}/open`, 'POST');
}

export async function closeContract(id: string): Promise<{ success: boolean; contract: Contract }> {
    return apiMutate(`/api/management/contracts/${id}/close`, 'POST');
}
