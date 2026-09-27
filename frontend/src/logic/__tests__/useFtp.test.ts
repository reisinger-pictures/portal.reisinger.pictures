import {describe, it, expect, vi, beforeEach, afterEach} from 'vitest';
import {renderHook, act, waitFor} from '@testing-library/react';
import {SWRConfig} from 'swr';
import {createElement, type ReactNode} from 'react';
import {useFtp, type FtpStatus, type ResetCredentialsResponse} from '../useFtp';

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
    // The quota the guide quotes. The value is the current production one, so a
    // fixture that drifts from the server does not read as a deliberate change;
    // nothing in the hook branches on it.
    ftp_reset_limit_per_hour: 10,
    // A fully configured connection, because the camera settings the management UI
    // renders are only as good as this payload: a fixture with `configured: false`
    // would let a dropped field pass unnoticed. `ftpConnection.test.ts` covers the
    // unconfigured case.
    connection: {
        configured: true,
        // Reserved per RFC 2606, so it can never resolve to a real server. Every
        // test here stubs `fetch` globally, but a fixture that points at the
        // production host would survive a forgotten stub.
        host: 'sftp.example.invalid',
        username: 'florian',
        path: '/',
        sftp_port: 2222,
        ftps_port: 989,
        pasv_port_start: 50000,
        pasv_port_end: 50100,
        ftps_tls_mode: 'explicit',
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
        expect(result.current.status?.connection.host).toBe('sftp.example.invalid');
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

/**
 * `resetCredentials` (P1-M33) is the only way a photographer can obtain a camera
 * password, and its failure modes are not interchangeable: 429 means the quota
 * (three per hour) is spent, 503 means SFTPGo is not configured or unreachable,
 * 404 means the cached account does not exist. A hook that folded those into one
 * rejected promise without a status would leave the UI unable to say anything
 * true, and the 429 in particular must not invite a retry.
 */
describe('useFtp.resetCredentials', () => {
    const CREDENTIALS = {
        success: true,
        password: 'Kamera-P4sswort-4711',
        password_notice: 'Dieses Passwort wird genau einmal angezeigt.',
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts to the credential endpoint and hands the password back', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/reset-password')) {
                return Promise.resolve(jsonResponse(CREDENTIALS));
            }

            return Promise.resolve(jsonResponse({...BASE_STATUS, ftp_account_status: 'active'}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        let response: ResetCredentialsResponse | null = null;
        await act(async () => {
            response = await result.current.resetCredentials();
        });

        expect(response).toEqual(CREDENTIALS);

        const postCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/reset-password'));
        expect(postCall).toBeDefined();
        expect(postCall?.[1]).toMatchObject({method: 'POST'});
    });

    it('refetches the status so the account state leaves pending after a reset', async () => {
        // The status has to *change* across the reset, otherwise the assertion
        // below would pass even without the refetch. The mock therefore starts at
        // `pending` and only reports `active` once the reset has been called —
        // which is the sequence the backend actually produces.
        let provisioned = false;
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/reset-password')) {
                provisioned = true;
                return Promise.resolve(jsonResponse(CREDENTIALS));
            }

            return Promise.resolve(jsonResponse({...BASE_STATUS, ftp_account_status: provisioned ? 'active' : 'pending'}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status?.ftp_account_status).toBe('pending'));

        await act(async () => {
            await result.current.resetCredentials();
        });

        // A reset that did not move the status would leave the page telling the
        // photographer the camera still cannot log in, after it can.
        await waitFor(() => expect(result.current.status?.ftp_account_status).toBe('active'));
    });

    it('surfaces a rate limit as a 429 instead of swallowing it', async () => {
        const message = 'Zu viele Passwort-Änderungen. Bitte später erneut versuchen.';
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/reset-password')) {
                return Promise.resolve(jsonResponse({error: message}, 429));
            }

            return Promise.resolve(jsonResponse(BASE_STATUS));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        // The quota is a fixed number per hour, so a caller that cannot see 429
        // will keep pressing a button that cannot succeed.
        await expect(result.current.resetCredentials()).rejects.toMatchObject({
            status: 429,
            message,
        });
    });

    it('surfaces an unreachable SFTPGo as a 503 instead of swallowing it', async () => {
        const message = 'Der FTP-Dienst ist nicht erreichbar.';
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/reset-password')) {
                return Promise.resolve(jsonResponse({error: message}, 503));
            }

            return Promise.resolve(jsonResponse(BASE_STATUS));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        // 503 is the retryable one, and it means something different from 429:
        // the photographer's password did not change either way, but only here is
        // waiting the right answer.
        await expect(result.current.resetCredentials()).rejects.toMatchObject({
            status: 503,
            message,
        });
    });

    it('does not swallow a failed reset, so the status is not refetched as if it had worked', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/reset-password')) {
                return Promise.resolve(jsonResponse({error: 'Konto existiert laut Cache nicht.'}, 404));
            }

            return Promise.resolve(jsonResponse(BASE_STATUS));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        // Count only the status reads: the POST under test is a fetch call too,
        // and counting it would hide the very thing being asserted.
        const statusReads = () => fetchMock.mock.calls.filter(([url]) => !String(url).endsWith('/reset-password')).length;
        const readsBefore = statusReads();

        await expect(result.current.resetCredentials()).rejects.toMatchObject({status: 404});

        // No refetch: the refetch is the "it worked" signal, and firing it after a
        // failure would show an account state that never happened.
        expect(statusReads()).toBe(readsBefore);
    });
});
