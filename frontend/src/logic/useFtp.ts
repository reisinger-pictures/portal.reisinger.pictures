import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';
import {FtpConnection} from './ftpConnection';

export interface FtpTargetGallery {
    id: string;
    name: string;
    slug: string;
    brand?: string | null;
}

/**
 * Account provisioning state, mirrored from the V041 columns on `users`.
 *
 * `pending` / `active` / `error` is a closed set: the backend reports the stored
 * column verbatim, and the value is a cache that the portal maintains, not a live
 * query against SFTPGo. So a photographer can always be told what the portal
 * knows, even while the service is unreachable.
 */
export type FtpAccountStatus = 'pending' | 'active' | 'error';

export interface FtpStatus {
    ftp_folder: string;
    file_count: number;
    current_target_gallery: FtpTargetGallery | null;
    /**
     * `null` is not part of the contract — the column is NOT NULL with a default —
     * so the union is exhaustive and a UI switch can be checked at compile time.
     */
    ftp_account_status: FtpAccountStatus;
    /** ISO-8601, `null` while the account was never successfully provisioned. */
    ftp_provisioned_at: string | null;
    /** Provider text for `error`, `null` in every other state. */
    ftp_account_error: string | null;
    /**
     * What a camera has to be configured with. Absent values mean the server
     * has not declared them, which is reported rather than guessed — see
     * `FtpConnection`.
     */
    connection: FtpConnection;
}

export interface ProcessInboxResponse {
    success: boolean;
    processed: number;
}

export function useFtp() {
    const {data: status, isLoading, mutate} = useSWR<FtpStatus>('/api/management/ftp/status', fetcher);

    const setTargetGallery = async (gallery_id: string | null) => {
        await apiMutate('/api/management/ftp/target', 'POST', {gallery_id});
        await mutate();
    };

    const processInbox = async () => {
        const data = await apiMutate<ProcessInboxResponse>('/api/management/ftp/process', 'POST');
        await mutate();
        return data;
    };

    return {status, isLoading, setTargetGallery, processInbox};
}
