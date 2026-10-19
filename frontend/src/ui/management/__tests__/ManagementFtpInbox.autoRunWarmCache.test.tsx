import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, act } from '@testing-library/react';
import { SWRConfig, useSWRConfig } from 'swr';
import { useEffect, type ReactNode } from 'react';
import { I18nProvider } from '../../../logic/I18nProvider';
import ManagementFtpInbox from '../ManagementFtpInbox';
import type { FtpStatus, FtpTargetGallery } from '../../../logic/useFtp';

/**
 * B1: the catch-up run (D-36) must decide against a status this mount read for
 * itself, not against whatever the module-wide SWR cache happens to hold.
 *
 * SWR's own mount revalidation is not a reliable source for that: it is skipped
 * inside `dedupingInterval` (default 2000 ms — the production value, which these
 * tests exercise on purpose) and skipped again while a previous instance's
 * request for the same key is still in flight. `useFtp` therefore reads for
 * itself on mount with an explicit `mutate()` (which ignores the dedupe window)
 * and reports when that read has settled. A warm entry otherwise hands the first
 * render a complete status with `isLoading === false` — the exact state after
 * every import is `file_count: 0` — so the revalidation that carried the real
 * files used to arrive after the one shot was gone.
 *
 * These tests use the *real* `useFtp` with only `fetch` stubbed, because the bug
 * lives in the interaction between the component and SWR's cache: a mocked hook
 * cannot reproduce it. The cache is pre-seeded warm, deliberately with the
 * post-import state (`file_count: 0`), and the read answers with the files that
 * really sit in the inbox.
 */

vi.mock('../../../logic/useGalleries', () => ({
    useProtectedGalleries: () => ({ tree: undefined }),
}));

const { showToast } = vi.hoisted(() => ({ showToast: vi.fn() }));

vi.mock('../../components/UIContext', () => ({
    useUI: () => ({ showToast }),
}));

const STATUS_KEY = '/api/management/ftp/status';

const GALLERY: FtpTargetGallery = {
    id: 'gallery-1',
    name: 'Hochzeit Müller',
    slug: 'hochzeit-mueller',
    brand: 'rp',
};

const statusWith = (fileCount: number, gallery: FtpTargetGallery | null = GALLERY): FtpStatus => ({
    ftp_folder: 'upload',
    file_count: fileCount,
    quarantine_count: 0,
    current_target_gallery: gallery,
    ftp_account_status: 'active',
    ftp_provisioned_at: '2026-01-01T00:00:00Z',
    ftp_account_error: null,
    ftp_reset_limit_per_hour: 10,
    connection: {
        configured: true,
        host: 'ftp.example.org',
        username: 'kamera',
        path: '/upload',
        sftp_port: 2222,
        ftps_port: 990,
        pasv_port_start: 30000,
        pasv_port_end: 30009,
        ftps_tls_mode: 'explicit',
    },
});

const jsonResponse = (data: unknown, status = 200) => new Response(
    JSON.stringify(data),
    { status, headers: { 'Content-Type': 'application/json' } },
);

/**
 * A cache that already holds a completed request — `isValidating: false` and
 * `isLoading: false` is exactly what SWR writes when a request settles, so this
 * is the state the singleton has on the next mount, not a synthetic shortcut.
 */
function warmCache(warm: FtpStatus) {
    const cache = new Map();
    cache.set(STATUS_KEY, { data: warm, error: undefined, isValidating: false, isLoading: false });
    return cache;
}

/** The provider tree, reused so a test can mount twice against the same cache. */
function wrapperFor(cache: ReturnType<typeof warmCache>) {
    return ({ children }: { children: ReactNode }) => (
        <I18nProvider>
            <SWRConfig value={{
                provider: () => cache,
                // Mirrors `App.tsx`'s `GlobalSWRConfig`: a status read that fails
                // with 5xx (or offline, status 0) is not silent — the global
                // `onError` turns it into a toast. Reproduced here so the
                // component docblock's claim about the catch-up's failure path is
                // pinned rather than asserted.
                onError: (error: { status?: number; message?: string }) => {
                    if ((error.status ?? 0) >= 500 || error.status === 0) {
                        showToast('error', error.message);
                    }
                },
            }}>
                {children}
            </SWRConfig>
        </I18nProvider>
    );
}

function renderWithWarmCache(warm: FtpStatus) {
    return render(<ManagementFtpInbox />, { wrapper: wrapperFor(warmCache(warm)) });
}

/**
 * Keeps the SWR provider mounted while the inbox itself comes and goes — which
 * is what a view switch does. `render(...).unmount()` would tear the provider
 * down as well, and that resets SWR's per-provider request markers (`FETCH`),
 * hiding the very dedupe window the remount tests exist to reproduce.
 */
function Harness({ show }: { show: boolean }) {
    return show ? <ManagementFtpInbox /> : null;
}

let fetchMock: ReturnType<typeof vi.fn>;
let revalidateStatus: (() => Promise<unknown>) | null = null;

function RevalidationBridge() {
    const { mutate } = useSWRConfig();

    useEffect(() => {
        revalidateStatus = () => mutate(STATUS_KEY);
        return () => {
            revalidateStatus = null;
        };
    }, [mutate]);

    return null;
}

/**
 * Serve the status the mount read must return, and count the imports.
 * `statusReads` lets a test prove the read actually happened.
 */
function installFetch(fresh: FtpStatus) {
    let statusReads = 0;
    fetchMock = vi.fn().mockImplementation((url: string) => {
        const target = String(url);
        if (target.endsWith('/api/management/ftp/process')) {
            return Promise.resolve(jsonResponse({ success: true, processed: 3 }));
        }
        if (target.endsWith(STATUS_KEY)) {
            statusReads += 1;
            return Promise.resolve(jsonResponse(fresh));
        }
        return Promise.resolve(jsonResponse({}));
    });
    vi.stubGlobal('fetch', fetchMock);
    return () => statusReads;
}

const processCalls = () => fetchMock.mock.calls
    .filter(([url]) => String(url).endsWith('/api/management/ftp/process')).length;

describe('ManagementFtpInbox catch-up run against a warm cache (B1)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('imports when the warm cache says 0 but the read finds files', async () => {
        const reads = installFetch(statusWith(3));

        // The stale cache is the post-import state: nothing waiting.
        renderWithWarmCache(statusWith(0));

        // The card ends on the revalidated number…
        await waitFor(() => expect(screen.getByTestId('ftp-queue-count')).toHaveTextContent('3'));
        // …and the run happened exactly once, on the *fresh* status.
        await waitFor(() => expect(processCalls()).toBe(1));

        expect(reads()).toBe(2);
    });

    it('stays silent when the warm cache says 0 and the read agrees', async () => {
        const reads = installFetch(statusWith(0));

        renderWithWarmCache(statusWith(0));

        // Wait for the mount read to settle before the negative assertion: a
        // sleep would only guess how long that takes.
        await waitFor(() => expect(reads()).toBe(1));
        await waitFor(() => expect(screen.getByTestId('ftp-queue-count')).toHaveTextContent('0'));

        expect(processCalls()).toBe(0);
    });

    it('imports when the warm cache has no target gallery but the read supplies one', async () => {
        installFetch(statusWith(3, GALLERY));

        renderWithWarmCache(statusWith(3, null));

        await waitFor(() => expect(processCalls()).toBe(1));
    });

    it('decides again on a second mount inside the dedupe window', async () => {
        // Alternating status: each mount's own read finds files, the reload after
        // the import reports the queue drained. So the cache after the first mount
        // reads `file_count: 0` — the state that reads the same as "nothing
        // waiting" — and a second mount that decided on the cache instead of
        // reading would stay silent.
        let statusReads = 0;
        fetchMock = vi.fn().mockImplementation((url: string) => {
            const target = String(url);
            if (target.endsWith('/api/management/ftp/process')) {
                return Promise.resolve(jsonResponse({ success: true, processed: 3 }));
            }
            if (target.endsWith(STATUS_KEY)) {
                statusReads += 1;
                return Promise.resolve(jsonResponse(statusWith(statusReads % 2 === 1 ? 3 : 0)));
            }
            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = wrapperFor(warmCache(statusWith(0)));
        const { rerender } = render(<Harness show />, { wrapper });
        await waitFor(() => expect(processCalls()).toBe(1));

        // The provider stays mounted across the switch (`Harness` is why): only
        // the inbox unmounts, and it comes back within `dedupingInterval`
        // (2000 ms) of the last status read, so SWR's own mount revalidation is
        // deduped away and would never fire for the second instance. The mount
        // reads for itself instead — which is exactly what this pins: without the
        // explicit read, `processCalls` would stay at 1 and the photographer who
        // left the page and came straight back would see the stale queue.
        rerender(<Harness show={false} />);
        rerender(<Harness show />);

        await waitFor(() => expect(processCalls()).toBe(2));
    });

    it('decides on a mount that starts while a previous mount\'s read is in flight', async () => {
        // The second dedupe path: while the first instance's request is still in
        // flight, `FETCH[key]` is set, so a new mount's own revalidation is
        // skipped entirely and its `onSuccess` would belong to the old instance.
        // The first status read never settles; a later one does.
        let resolveFirst!: (response: Response) => void;
        let statusReads = 0;
        fetchMock = vi.fn().mockImplementation((url: string) => {
            const target = String(url);
            if (target.endsWith('/api/management/ftp/process')) {
                return Promise.resolve(jsonResponse({ success: true, processed: 3 }));
            }
            if (target.endsWith(STATUS_KEY)) {
                statusReads += 1;
                if (statusReads === 1) {
                    return new Promise<Response>(resolve => { resolveFirst = resolve; });
                }
                return Promise.resolve(jsonResponse(statusWith(3)));
            }
            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = wrapperFor(warmCache(statusWith(0)));
        const { rerender } = render(<Harness show />, { wrapper });
        // The first mount's read is in flight and has decided nothing.
        await waitFor(() => expect(statusReads).toBe(1));
        expect(processCalls()).toBe(0);
        // The inbox goes away; the provider — and with it the in-flight
        // request's marker — stays mounted.
        rerender(<Harness show={false} />);

        // The second mount starts while that request is still pending.
        rerender(<Harness show />);
        await waitFor(() => expect(processCalls()).toBe(1));

        // Release the first instance's read so the test leaves no pending fetch.
        await act(async () => {
            resolveFirst(jsonResponse(statusWith(3)));
        });
    });

    it('stays silent when the mount read fails, instead of deciding on the cache', async () => {
        const cache = warmCache(statusWith(3));
        fetchMock = vi.fn().mockImplementation((url: string) => {
            const target = String(url);
            if (target.endsWith(STATUS_KEY)) {
                return Promise.resolve(jsonResponse({ error: 'Der FTP-Dienst ist nicht erreichbar.' }, 500));
            }
            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<ManagementFtpInbox />, { wrapper: wrapperFor(cache) });

        // Wait until the failed read has been written to the cache; the decision
        // is made in the render that follows, so the negative assertion below is
        // not a guess about timing. The stale value says `file_count: 3` with a
        // target gallery — deciding on it would import.
        await waitFor(() => expect(cache.get(STATUS_KEY)?.error).toBeDefined());
        expect(processCalls()).toBe(0);

        // …and the user is not left in the dark: the 5xx reaches the global
        // `onError` (mirrored in the wrapper), which is the half the component
        // docblock claims. Without this assertion that sentence would be
        // unbacked prose.
        expect(showToast).toHaveBeenCalledWith('error', 'Der FTP-Dienst ist nicht erreichbar.');
    });

    it('decides on the next successful read after a failed mount read', async () => {
        const cache = warmCache(statusWith(3));
        let statusReads = 0;
        fetchMock = vi.fn().mockImplementation((url: string) => {
            const target = String(url);
            if (target.endsWith('/api/management/ftp/process')) {
                return Promise.resolve(jsonResponse({ success: true, processed: 3 }));
            }
            if (target.endsWith(STATUS_KEY)) {
                statusReads += 1;
                // The mount read fails; a later cache-scoped revalidation succeeds.
                return Promise.resolve(statusReads === 1
                    ? jsonResponse({ error: 'Der FTP-Dienst ist nicht erreichbar.' }, 500)
                    : jsonResponse(statusWith(3)));
            }
            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<><ManagementFtpInbox /><RevalidationBridge /></>, { wrapper: wrapperFor(cache) });

        await waitFor(() => expect(cache.get(STATUS_KEY)?.error).toBeDefined());
        expect(processCalls()).toBe(0);

        // A failed read does not spend the mount's attempt: the next successful
        // revalidation decides. Trigger it through the provider's mutate instead
        // of dispatching DOM focus: SWR's module-level default provider binds its
        // focus callback directly to setTimeout, and jsdom passes the Event as the
        // timeout delay (Node warns about NaN).
        await act(async () => {
            await revalidateStatus?.();
        });
        await waitFor(() => expect(processCalls()).toBe(1));
    });
});
