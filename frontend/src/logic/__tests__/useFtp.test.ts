import {describe, it, expect, vi, beforeEach, afterEach} from 'vitest';
import {renderHook, act, waitFor} from '@testing-library/react';
import {SWRConfig} from 'swr';
import {createElement, type ReactNode} from 'react';
import {useFtp, type FtpStatus} from '../useFtp';

/**
 * The FTP status contract (P1-M26).
 *
 * The provisioning fields matter here because they change what an empty inbox
 * *means*: without them the UI can only say "0 Bilder", which reads the same for
 * "no photos yet" and "the camera account does not exist". The backend reports
 * them from the V041 columns without ever contacting SFTPGo, so the values are
 * stable whether the service is up or not — and a client that cannot tell those
 * two states apart is exactly the bug this file guards.
 *
 * The hook is rendered against a fresh SWR cache per test: SWR's default cache is
 * a module-level singleton, so without that the first fixture would be served to
 * every later test and each of them would assert against stale data.
 */

const BASE_STATUS: FtpStatus = {
    ftp_folder: '/florian',
    file_count: 0,
    current_target_gallery: null,
    ftp_account_status: 'pending',
    ftp_provisioned_at: null,
    ftp_account_error: null,
    // A fully configured connection, because the camera settings the management UI
    // renders are only as good as this payload: a fixture with `configured: false`
    // would let a dropped field pass unnoticed. `ftpConnection.test.ts` covers the
    // unconfigured case.
    connection: {
        configured: true,
        host: 'reisinger.pictures',
        username: 'florian',
        path: '/',
        sftp_port: 2222,
        ftps_port: 989,
        pasv_port_start: 50000,
        pasv_port_end: 50100,
    },
};

/**
 * A fresh `Response` per call. A `Response` body can only be read once, so a
 * shared instance would fail on the second call with a JSON parse error that has
 * nothing to do with the behaviour under test.
 */
const jsonResponse = (data: unknown, status = 200) => new Response(
    JSON.stringify(data),
    {status, headers: {'Content-Type': 'application/json'}},
);

const renderFtp = () => {
    // `createElement` rather than JSX: this file is a `.ts` module, and the test
    // only needs a context provider, not a component tree.
    const wrapper = ({children}: {children: ReactNode}) => createElement(
        SWRConfig,
        {value: {provider: () => new Map(), dedupingInterval: 0}},
        children,
    );

    return renderHook(() => useFtp(), {wrapper});
};

describe('useFtp', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('exposes the provisioning status alongside the inbox fields', async () => {
        vi.stubGlobal('fetch', vi.fn().mockImplementation(() => Promise.resolve(jsonResponse(BASE_STATUS))));

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        expect(result.current.status?.ftp_account_status).toBe('pending');
        expect(result.current.status?.ftp_provisioned_at).toBeNull();
        expect(result.current.status?.ftp_account_error).toBeNull();
        // The camera settings ride along with the inbox fields; a UI that only
        // renders them when the connection is present needs the hook to hand
        // them through unchanged.
        expect(result.current.status?.connection.host).toBe('reisinger.pictures');
        expect(result.current.status?.connection.sftp_port).toBe(2222);
    });

    it('distinguishes a failed provisioning from an unprovisioned one', async () => {
        vi.stubGlobal('fetch', vi.fn().mockImplementation(() => Promise.resolve(jsonResponse({
            ...BASE_STATUS,
            ftp_account_status: 'error',
            ftp_account_error: 'Home-Verzeichnis fehlt auf dem Host.',
        }))));

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        // The reason has to survive the wire: "0 Bilder" next to a silent failure
        // is the situation P1-M26 exists to end.
        expect(result.current.status?.ftp_account_status).toBe('error');
        expect(result.current.status?.ftp_account_error).toBe('Home-Verzeichnis fehlt auf dem Host.');
    });

    it('carries the provisioning timestamp of an active account', async () => {
        vi.stubGlobal('fetch', vi.fn().mockImplementation(() => Promise.resolve(jsonResponse({
            ...BASE_STATUS,
            ftp_account_status: 'active',
            ftp_provisioned_at: '2026-09-26T10:11:12.000000Z',
        }))));

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        expect(result.current.status?.ftp_account_status).toBe('active');
        expect(result.current.status?.ftp_provisioned_at).toBe('2026-09-26T10:11:12.000000Z');
    });

    it('refetches the status after an import so the queue count and the account state stay truthful', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/process')) {
                return Promise.resolve(jsonResponse({success: true, processed: 2}));
            }

            return Promise.resolve(jsonResponse({...BASE_STATUS, file_count: 0}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        let processed = 0;
        await act(async () => {
            processed = (await result.current.processInbox()).processed;
        });

        expect(processed).toBe(2);
        // The refetch is what keeps the provisioning fields in step too: a reset
        // or a provisioning done elsewhere has to appear without a page reload.
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
    });
});
