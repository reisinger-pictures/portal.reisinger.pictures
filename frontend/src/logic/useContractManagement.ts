import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';
import type {InvoiceDiscount, InvoiceItem} from '../api';
import {
    calculateContractTotal,
    fixedPointToMajorUnits,
    normalizeContractSnapshot,
    serializeContractSnapshot,
    type ContractWireDiscount,
    type ContractWireItem,
} from './contractPricing';

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
    type: 'contract' | 'template';
    status: 'draft' | 'active' | 'closed' | 'cancelled';
    billing_details: BillingDetails | null;
    items: ContractItem[];
    discounts: ContractDiscount[];
    /** Authoritative server-calculated cents. */
    total: number;
    terms_html: string;
    available_roles: string[];
    allow_multiple_roles_per_signer: boolean;
    join_token: string | null;
    closes_at: string | null;
    template_id: string | null;
    expires_at: string | null;
    created_at: string;
    updated_at: string;
    signers?: ContractSigner[];
}

export type ContractInstance = Contract;

export type ContractItem = ContractWireItem & {
    /** Legacy output only; authoritative totals never read this field. */
    row_total?: number;
};

export type ContractDiscount = ContractWireDiscount & {
    /** Legacy output only; authoritative totals never read this field. */
    row_total?: number;
};

/** Normalize a management response and calculate its server-equivalent total. */
export function normalizeManagementContract(contract: Omit<Contract, 'total'> & { total?: number }): Contract {
    const snapshot = normalizeContractSnapshot(contract.items, contract.discounts);
    const total = typeof contract.total === 'number' && Number.isSafeInteger(contract.total) && contract.total >= 0
        ? contract.total
        : calculateContractTotal(snapshot);
    return {
        ...contract,
        items: snapshot.items,
        discounts: snapshot.discounts,
        total,
    };
}

/** Convert API cents to the editor's major-unit display value. */
export function contractItemToEditor(item: ContractItem): InvoiceItem {
    return {
        type: 'item',
        description: item.description,
        notes: item.notes,
        qty: item.qty,
        price: fixedPointToMajorUnits(item.price),
    };
}

/** Convert fixed-discount cents or percentage basis points to editor units. */
export function contractDiscountToEditor(discount: ContractDiscount): InvoiceDiscount {
    return {
        type: discount.type,
        description: discount.description,
        notes: discount.notes,
        price: fixedPointToMajorUnits(discount.price),
    };
}

/** Serialize one editor item into the canonical contract items array. */
export function contractItemToSnapshot(item: InvoiceItem): ContractItem {
    return serializeContractSnapshot([item], []).items[0];
}

/** Serialize one editor discount into the canonical contract discounts array. */
export function contractDiscountToSnapshot(discount: InvoiceDiscount): ContractDiscount {
    return serializeContractSnapshot([], [discount]).discounts[0];
}

export interface ContractFormData {
    items: ContractItem[];
    discounts: ContractDiscount[];
    terms_html: string;
    available_roles: string[];
    allow_multiple_roles_per_signer: boolean;
    billing_details: BillingDetails;
    closes_at: string | null;
    type: 'contract' | 'template';
    expires_at: string | null;
}

export async function fetchInstances(id: string): Promise<Contract[]> {
    return fetcher(`/api/management/contracts/${id}/instances`);
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
