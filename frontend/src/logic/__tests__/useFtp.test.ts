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
    quarantine_count: 0,
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

const renderFtp = (cache = new Map()) => {
    // `createElement` rather than JSX: this file is a `.ts` module, and the test
    // only needs a context provider, not a component tree. No `dedupingInterval`
    // override: the hook reads for itself on mount, so the production default is
    // what the tests should exercise.
    const wrapper = ({children}: {children: ReactNode}) => createElement(
        SWRConfig,
        {value: {provider: () => cache}},
        children,
    );

    return renderHook(() => useFtp(), {wrapper});
};

const STATUS_KEY = '/api/management/ftp/status';

/**
 * The state SWR writes when a request settles: `isValidating: false` and
 * `isLoading: false` alongside the data. A test that seeds the cache with this
 * shape reproduces the warm-cache remount — the state the singleton holds on the
 * next mount, not a synthetic shortcut.
 */
const warmCacheWith = (status: FtpStatus) => {
    const cache = new Map();
    cache.set(STATUS_KEY, {data: status, error: undefined, isValidating: false, isLoading: false});
    return cache;
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

    it('hands the processed count back and leaves the reload to the caller', async () => {
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

        // The import itself reads the status exactly once (the initial load) and
        // no more: reloading the queue is the caller's next step, and doing it
        // here as well would make every import read `/status` twice for a single
        // visible change (see the hook's `processInbox` docblock).
        const statusReads = () => fetchMock.mock.calls.filter(([url]) => !String(url).endsWith('/process')).length;
        expect(statusReads()).toBe(1);

        // …and the exposed `mutate` is that reload: it has to actually refetch,
        // otherwise a caller that processes and reloads would show a stale queue.
        await act(async () => {
            await result.current.mutate();
        });
        await waitFor(() => expect(statusReads()).toBe(2));
    });
});

/**
 * The mount read (B1). `useFtp` reads once for itself on mount and reports when
 * that read has settled, together with its error — the freshness signal the FTP
 * inbox's one-shot catch-up run needs. The SWR cache is a module-wide singleton,
 * so a remount renders the cached status with `isLoading === false` and
 * `isValidating === false` *before* any read for that mount has even started;
 * neither flag separates a leftover cache value from a freshly read one.
 * `readSettled`/`error` do: the former turns true only once this mount's
 * explicit `mutate()` has settled, the latter is set when it failed.
 */
describe('useFtp mount read', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('reads for itself on mount, even when the cache already holds a value', async () => {
        const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(jsonResponse({...BASE_STATUS, file_count: 3})));
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp(warmCacheWith({...BASE_STATUS, file_count: 0}));

        // The cached value is on screen immediately…
        expect(result.current.status?.file_count).toBe(0);
        // …but this mount has not settled a read of its own yet.
        expect(result.current.readSettled).toBe(false);

        await waitFor(() => expect(result.current.readSettled).toBe(true));
        // The value the decision is based on is the freshly read one, not the cache.
        expect(result.current.status?.file_count).toBe(3);
        // Exactly one read: SWR's own mount revalidation is off, so this is the
        // explicit `mutate()` and nothing else.
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('settles with an error when the mount read fails, without inventing a value', async () => {
        vi.stubGlobal('fetch', vi.fn().mockImplementation(
            () => Promise.resolve(jsonResponse({error: 'Der FTP-Dienst ist nicht erreichbar.'}, 500)),
        ));

        const {result} = renderFtp(warmCacheWith({...BASE_STATUS, file_count: 0}));

        await waitFor(() => expect(result.current.readSettled).toBe(true));

        // The read settled, but it failed: `error` is set and the leftover cache
        // value must not read as confirmed. This is what keeps the catch-up from
        // spending its single attempt on a value the read could not vouch for.
        expect(result.current.error).toBeDefined();
        expect(result.current.status?.file_count).toBe(0);
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

/**
 * `changeCredentials` (D-51) is the photographer's own replacement of the
 * camera password, and the confirmation is the current password — the portal
 * keeps no copy of it, so it can only be checked by the service.
 *
 * The request therefore carries a credential, and where it travels is the
 * security property of this hook: in the body, never in the URL. A URL ends up
 * in a proxy log, in a browser history entry and in a `Referer` header, so a
 * password in it is a password in three places at once.
 *
 * The failure modes are as different from each other as the reset's are: a
 * 422 means the typed current password was wrong and a different one fixes it,
 * a 429 means the hourly quota is spent and only waiting helps. One opaque
 * failure would leave the photographer guessing — and a blind retry is exactly
 * what the quota punishes.
 */
describe('useFtp.changeCredentials', () => {
    const CREDENTIALS = {
        success: true,
        password: 'Kamera-P4sswort-4711',
        password_notice: 'Dieses Passwort wird genau einmal angezeigt.',
    };

    /**
     * The camera password the photographer types into the dialog. It is the
     * confirmation, and it is the value that must not leak into the URL.
     */
    const CURRENT_PASSWORD = 'kamerapasswort01';

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts the current password to the change endpoint and hands the new password back', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/change-password')) {
                return Promise.resolve(jsonResponse(CREDENTIALS));
            }

            return Promise.resolve(jsonResponse({...BASE_STATUS, ftp_account_status: 'active'}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        const responses: ResetCredentialsResponse[] = [];
        await act(async () => {
            responses.push(await result.current.changeCredentials(CURRENT_PASSWORD));
        });

        // The new password comes back exactly once, in the shape the reset uses
        // as well — the same act, the same show-once contract.
        expect(responses).toEqual([CREDENTIALS]);
        expect(responses[0]).toMatchObject({
            password: CREDENTIALS.password,
            password_notice: CREDENTIALS.password_notice,
        });

        const postCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/change-password'));
        expect(postCall).toBeDefined();
        expect(postCall?.[1]).toMatchObject({method: 'POST'});
    });

    it('refetches the status after a change, so the account state on screen is the current one', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/change-password')) {
                return Promise.resolve(jsonResponse(CREDENTIALS));
            }

            return Promise.resolve(jsonResponse(BASE_STATUS));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        // Count only the status reads: the POST under test is a fetch call too,
        // and counting it would hide the very thing being asserted.
        const statusReads = () => fetchMock.mock.calls.filter(([url]) => !String(url).endsWith('/change-password')).length;
        const readsBefore = statusReads();

        await act(async () => {
            await result.current.changeCredentials(CURRENT_PASSWORD);
        });

        // The refetch is the "it worked" signal — it is what tells the
        // photographer that the camera can authenticate with the new password.
        // Exactly one read: a change that read the status twice would show a
        // state nobody asked for.
        expect(statusReads()).toBe(readsBefore + 1);
    });

    it('sends the current password in the body and never in the URL', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/change-password')) {
                return Promise.resolve(jsonResponse(CREDENTIALS));
            }

            return Promise.resolve(jsonResponse(BASE_STATUS));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        await act(async () => {
            await result.current.changeCredentials(CURRENT_PASSWORD);
        });

        const postCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/change-password'));
        expect(postCall).toBeDefined();

        // A credential in the URL is a credential in the proxy log, in the
        // browser history and in a `Referer` header.
        expect(String(postCall?.[0])).not.toContain(CURRENT_PASSWORD);

        // The body is the only place it belongs, and it is the only thing in
        // it: the current password and nothing else.
        expect(postCall?.[1]).toMatchObject({
            method: 'POST',
            body: JSON.stringify({current_password: CURRENT_PASSWORD}),
        });
    });

    it('surfaces a wrong current password as a 422 instead of swallowing it', async () => {
        const message = 'Das aktuelle Kamera-Passwort ist nicht korrekt. Bitte prüfe es und versuche es erneut.';
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/change-password')) {
                return Promise.resolve(jsonResponse({error: message}, 422));
            }

            return Promise.resolve(jsonResponse(BASE_STATUS));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        const request = result.current.changeCredentials(CURRENT_PASSWORD);

        // A wrong current password is the one refusal a different input fixes,
        // so the client has to be able to tell it apart from the others.
        await expect(request).rejects.toMatchObject({
            status: 422,
            message,
        });

        // …and the refusal hands out no credential: the rejection carries the
        // status and the server's own words, never a password.
        await expect(request).rejects.not.toHaveProperty('password');
    });

    it('surfaces a rate limit as a 429 instead of swallowing it', async () => {
        const message = 'Zu viele FTP-Passwort-Zurücksetzungen für dieses Konto (maximal 10 pro Stunde). Bitte später erneut versuchen.';
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/change-password')) {
                return Promise.resolve(jsonResponse({error: message}, 429));
            }

            return Promise.resolve(jsonResponse(BASE_STATUS));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        const request = result.current.changeCredentials(CURRENT_PASSWORD);

        // The quota is a fixed number per hour, so a caller that cannot see 429
        // will keep pressing a button that cannot succeed.
        await expect(request).rejects.toMatchObject({
            status: 429,
            message,
        });

        await expect(request).rejects.not.toHaveProperty('password');
    });

    it('does not swallow a failed change, so the status is not refetched as if it had worked', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.endsWith('/change-password')) {
                return Promise.resolve(jsonResponse({error: 'Konto existiert laut Cache nicht.'}, 404));
            }

            return Promise.resolve(jsonResponse(BASE_STATUS));
        });
        vi.stubGlobal('fetch', fetchMock);

        const {result} = renderFtp();
        await waitFor(() => expect(result.current.status).toBeDefined());

        const statusReads = () => fetchMock.mock.calls.filter(([url]) => !String(url).endsWith('/change-password')).length;
        const readsBefore = statusReads();

        await expect(result.current.changeCredentials(CURRENT_PASSWORD)).rejects.toMatchObject({status: 404});

        // No refetch: the refetch is the "it worked" signal, and firing it after a
        // failure would show an account state that never happened.
        expect(statusReads()).toBe(readsBefore);
    });
});
