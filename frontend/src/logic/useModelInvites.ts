import useSWR from 'swr';
import { apiMutate, fetcher } from '../api';

export type ModelInviteStatus = 'open' | 'redeemed' | 'expired';

export interface ModelInvite {
    id: string;
    email: string | null;
    label: string | null;
    /** Brand-aware magic link (copyable; email dispatch is optional). */
    link: string | null;
    brand: string | null;
    status: ModelInviteStatus;
    expires_at: string | null;
    used_at: string | null;
    act_id: string | null;
    customer_id: string | null;
    invited_by: string | null;
    created_at: string | null;
}

export interface CreateModelInviteResponse {
    success: boolean;
    /** Top-level copy of the magic link (primary flow). */
    link?: string | null;
    invite: ModelInvite;
}

export interface CreateModelInvitePayload {
    email?: string;
    label?: string;
}

/**
 * Builds the POST body for a new invite. Empty optional fields are omitted so
 * the backend treats them as "not provided" — the magic link is the primary
 * flow, the email dispatch is purely optional.
 */
export function buildCreateInviteBody(payload: CreateModelInvitePayload): Record<string, string> {
    const body: Record<string, string> = {};
    const email = payload.email?.trim();
    const label = payload.label?.trim();
    if (email) body.email = email;
    if (label) body.label = label;
    return body;
}

/**
 * Resolves the magic link defensively: the backend returns it both top-level
 * and inside `invite`. Prefer the top-level value, fall back to `invite.link`.
 */
export function inviteLinkFromCreate(
    response: { link?: string | null; invite: { link: string | null } },
): string | null {
    return response.link ?? response.invite.link ?? null;
}

export function useModelInvites() {
    const { data, error, isLoading, mutate } = useSWR<ModelInvite[]>(
        '/api/management/model-invites',
        fetcher,
    );

    const createInvite = async (payload: CreateModelInvitePayload): Promise<CreateModelInviteResponse> => {
        const result = await apiMutate<CreateModelInviteResponse>(
            '/api/management/model-invites',
            'POST',
            buildCreateInviteBody(payload),
        );
        await mutate();
        return result;
    };

    const revokeInvite = async (id: string): Promise<void> => {
        await apiMutate(`/api/management/model-invites/${id}`, 'DELETE');
        await mutate();
    };

    return {
        invites: data,
        error,
        isLoading,
        mutate,
        createInvite,
        revokeInvite,
    };
}
