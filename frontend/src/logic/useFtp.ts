import {useEffect, useRef, useState} from 'react';
import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';
import {FtpConnection} from './ftpConnection';

/**
 * The single status key, shared by the hook and the mount read below. Extracted
 * because the read and the `useSWR` call must address the same cache entry;
 * two string literals could drift apart without any compiler noticing.
 */
const STATUS_KEY = '/api/management/ftp/status';

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
export type FtpAccountStatus = 'pending' | 'active' | 'revoked' | 'error';

export interface FtpStatus {
    ftp_folder: string;
    file_count: number;
    current_target_gallery: FtpTargetGallery | null;
    /**
     * `null` is not part of the contract — the column is NOT NULL with a default —
     * so the union is exhaustive and a UI switch can be checked at compile time.
     * `revoked` means the account existed and was taken away when the photographer
     * role was lost; the SFTPGo credential is gone, so the camera cannot log in
     * until somebody provisions again.
     */
    ftp_account_status: FtpAccountStatus;
    /** ISO-8601, `null` while the account was never successfully provisioned. */
    ftp_provisioned_at: string | null;
    /** Provider text for `error`, `null` in every other state. */
    ftp_account_error: string | null;
    /**
     * How many camera passwords the account may request per hour.
     *
     * Read from the backend, never written down in the frontend. The camera setup
     * guide quotes this number to the photographer, and the number it quoted was
     * three while the server enforced ten — the copy was its own copy of a security
     * rule and therefore went stale on the raise. A prop makes the guide a reader of
     * the rule; the guide must not get to define it.
     */
    ftp_reset_limit_per_hour: number;
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

/**
 * The show-once credential response.
 *
 * `password_notice` is the server's own wording, not decoration: it states that
 * the value is not stored and cannot be recovered, which is the only warning the
 * photographer gets before the value leaves this response and is gone.
 */
export interface ResetCredentialsResponse {
    success: boolean;
    password: string;
    password_notice: string;
}

export function useFtp() {
    /**
     * The status, read by this mount for itself.
     *
     * SWR's automatic mount revalidation cannot be relied on here: it is skipped
     * inside `dedupingInterval` (default 2000 ms) and skipped again while a
     * previous instance's request for the same key is still in flight. In both
     * cases the cache is served and `onSuccess` never fires for *this* mount —
     * which is exactly the state the FTP inbox's one-shot catch-up run (D-36)
     * must not decide on, because the value after every import is
     * `file_count: 0`, the same reading a stale entry shows.
     *
     * So the mount reads once for itself instead (below), and SWR's own mount
     * read is switched off to keep that to exactly one `GET /status`. An
     * explicit `mutate()` revalidation ignores the dedupe window, so the value
     * this mount decides on is always freshly read.
     */
    const {data: status, isLoading, error, mutate} = useSWR<FtpStatus>(
        STATUS_KEY,
        fetcher,
        {revalidateOnMount: false},
    );

    /**
     * `true` once the read this mount started has *settled* — succeeded or
     * failed; `error` is what separates the two.
     *
     * `readStarted` (a ref, so StrictMode's setup → cleanup → setup finds it
     * already set) makes "once per mount" literal. It is not the same signal as
     * `isLoading`/`isValidating`: with a warm cache the first render already
     * shows a complete value with both flags `false`, *before* this read has
     * even started, so neither flag separates a leftover cache value from a
     * freshly read one.
     */
    const [readSettled, setReadSettled] = useState(false);
    const readStarted = useRef(false);
    useEffect(() => {
        if (readStarted.current) return;
        readStarted.current = true;
        void (async () => {
            try {
                await mutate();
            } catch {
                // A failed read is reported through `error`; this catch only
                // keeps the rejection from escaping the fire-and-forget read.
            } finally {
                setReadSettled(true);
            }
        })();
    }, [mutate]);

    const setTargetGallery = async (gallery_id: string | null) => {
        await apiMutate('/api/management/ftp/target', 'POST', {gallery_id});
        await mutate();
    };

    /**
     * Import one batch and hand the result back.
     *
     * The status is deliberately **not** refetched here. Reloading the queue is
     * the caller's next step (`await processInbox(); await mutate();`), and
     * keeping it at the call site is what holds an import to exactly one
     * `GET /status`: the page triggers this from two places — the manual button
     * and the one-shot catch-up run on mount (D-36) — and both must show the
     * same remaining quantity after the same number of reads. A reload in here
     * as well would read the status twice for one visible change.
     */
    const processInbox = async () => {
        return apiMutate<ProcessInboxResponse>('/api/management/ftp/process', 'POST');
    };

    /**
     * Provisions the camera account (or rotates its password) and returns the new
     * password exactly once.
     *
     * The error is deliberately not caught: `apiMutate` rejects with an `ApiError`
     * carrying `status` and the server's `error` string, and the status is what
     * separates "you hit the quota" (429) from "SFTPGo is not configured or
     * unreachable" (503) from "the cached account does not exist" (404). Swallowing
     * that into one generic failure would leave the photographer guessing which of
     * those three they are looking at — and retrying blindly is exactly what the
     * hourly quota (`ftp_reset_limit_per_hour`) punishes. The caller shows the
     * message and does not retry.
     *
     * The status is refetched because a successful reset is what moves
     * `ftp_account_status` from `pending` to `active`, and that transition is the
     * signal that the camera can authenticate at all.
     */
    const resetCredentials = async () => {
        const data = await apiMutate<ResetCredentialsResponse>('/api/management/ftp/reset-password', 'POST');
        await mutate();
        return data;
    };

    return {status, isLoading, error, readSettled, setTargetGallery, processInbox, resetCredentials, mutate};
}
