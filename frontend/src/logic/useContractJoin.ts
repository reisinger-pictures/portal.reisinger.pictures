import { fetcher, apiMutate } from '../api';
import {
    calculateContractTotal,
    normalizeContractSnapshot,
    type ContractWireDiscount,
    type ContractWireItem,
} from './contractPricing';

export interface JoinContractResponse {
    contract_id: string;
    status: string;
    available_roles: string[];
    allow_multiple_roles: boolean;
    terms_html: string;
}

export interface JoinResult {
    personal_token: string;
    name: string;
    roles: string[];
}

export interface SignContractItem extends ContractWireItem {
    /** Legacy output only; authoritative totals never read this field. */
    row_total?: number;
}

export type SignContractDiscount = ContractWireDiscount;

export interface SignContract {
    id: string;
    terms_html: string;
    items: SignContractItem[];
    discounts: SignContractDiscount[];
    /** Authoritative server-calculated cents. */
    total: number;
    billing_details: Record<string, string> | null;
    available_roles: string[];
    content_version: number;
}

export interface SignContractSigner {
    id: string;
    name: string;
    email: string;
    roles: string[];
    status: string;
}

export interface SignContractResponse {
    contract: SignContract;
    signer: SignContractSigner;
}

type SignContractApiContract = Omit<SignContract, 'total'> & { total?: number };
export interface SignContractApiResponse {
    contract: SignContractApiContract;
    signer: SignContractSigner;
}

const isAuthoritativeTotal = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;

/**
 * Normalize the one intentional legacy boundary: responses from before the
 * server `total` field existed. The public hook always returns the strict
 * authoritative response type after this boundary.
 */
export function normalizeSignContractResponse(response: SignContractApiResponse): SignContractResponse {
    const snapshot = normalizeContractSnapshot(response.contract.items, response.contract.discounts);
    const total = isAuthoritativeTotal(response.contract.total)
        ? response.contract.total
        : calculateContractTotal(snapshot);

    return {
        ...response,
        contract: {
            ...response.contract,
            items: snapshot.items,
            discounts: snapshot.discounts,
            total,
        },
    };
}

export function fetchJoinContract(token: string): Promise<JoinContractResponse> {
    return fetcher<JoinContractResponse>(`/api/contracts/join/${token}`);
}

export function submitJoin(token: string, name: string, email: string, roles: string[]): Promise<JoinResult> {
    return apiMutate<JoinResult>(`/api/contracts/join/${token}`, 'POST', { name, email, roles });
}

export async function fetchSignContract(personalToken: string): Promise<SignContractResponse> {
    const response = await fetcher<SignContractApiResponse>(`/api/contracts/sign/${personalToken}`);
    return normalizeSignContractResponse(response);
}

export function submitSign(personalToken: string, contentVersion: number): Promise<{ success: boolean; message: string }> {
    return apiMutate(`/api/contracts/sign/${personalToken}`, 'POST', { accept_contract: true, content_version: contentVersion });
}

export function sendPageExit(personalToken: string): void {
    navigator.sendBeacon(`/api/contracts/sign/${personalToken}/page-exit`, '');
}
