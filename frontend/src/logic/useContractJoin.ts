import { fetcher, apiMutate } from '../api';

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

export interface SignContractResponse {
    contract: {
        id: string;
        terms_html: string;
        items: Array<{
            type: string;
            description: string;
            notes: string;
            qty: number;
            price: number;
            row_total?: number;
        }>;
        discounts: Array<{
            type: string;
            description: string;
            notes: string;
            price: number;
        }>;
        billing_details: Record<string, string> | null;
        available_roles: string[];
        content_version: number;
    };
    signer: {
        id: string;
        name: string;
        email: string;
        roles: string[];
        status: string;
    };
}

export function fetchJoinContract(token: string): Promise<JoinContractResponse> {
    return fetcher<JoinContractResponse>(`/api/contracts/join/${token}`);
}

export function submitJoin(token: string, name: string, email: string, roles: string[]): Promise<JoinResult> {
    return apiMutate<JoinResult>(`/api/contracts/join/${token}`, 'POST', { name, email, roles });
}

export function fetchSignContract(personalToken: string): Promise<SignContractResponse> {
    return fetcher<SignContractResponse>(`/api/contracts/sign/${personalToken}`);
}

export function submitSign(personalToken: string, contentVersion: number): Promise<{ success: boolean; message: string }> {
    return apiMutate(`/api/contracts/sign/${personalToken}`, 'POST', { accept_contract: true, content_version: contentVersion });
}

export function sendPageExit(personalToken: string): void {
    navigator.sendBeacon(`/api/contracts/sign/${personalToken}/page-exit`, '');
}
