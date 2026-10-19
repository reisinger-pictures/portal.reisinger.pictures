import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { SWRConfig } from 'swr';
import type { ReactNode } from 'react';
import { I18nProvider } from '../../../logic/I18nProvider';
import ManagementFtpInbox from '../ManagementFtpInbox';
import type { FtpStatus, FtpTargetGallery } from '../../../logic/useFtp';

/**
 * The catch-up run against the *real* `useFtp` (D-36), with only `fetch`
 * stubbed.
 *
 * `ManagementFtpInbox.autoRun.test.tsx` proves the component triggers exactly
 * one import and one reload; this file proves the reload is not a gesture but a
 * real `GET /status` whose new value reaches the card — the whole point of the
 * fallback path, because a lost SFTPGo event is only healed if the remaining
 * queue is the post-import one. The status changes across the import on purpose:
 * a fixture that stayed at 3 would pass with no reload at all.
 *
 * The second test covers the other half (N5): the reload also happens when the
 * import is *rejected*, because a 422 can abort a batch after files have already
 * moved and the counter must not stay on the pre-attempt number.
 */

vi.mock('../../../logic/useGalleries', () => ({
    useProtectedGalleries: () => ({ tree: undefined }),
}));

vi.mock('../../components/UIContext', () => ({
    useUI: () => ({ showToast: vi.fn() }),
}));

const GALLERY: FtpTargetGallery = {
    id: 'gallery-1',
    name: 'Hochzeit Müller',
    slug: 'hochzeit-mueller',
    brand: 'rp',
};

const statusWith = (fileCount: number): FtpStatus => ({
    ftp_folder: 'upload',
    file_count: fileCount,
    quarantine_count: 0,
    current_target_gallery: GALLERY,
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

// A fresh SWR cache per render: the default cache is a module-level singleton,
// and a value cached by another test would short-circuit the first load. No
// `dedupingInterval` override — the hook reads for itself on mount, so the
// production default is what these tests should exercise.
const wrapper = ({ children }: { children: ReactNode }) => (
    <I18nProvider>
        <SWRConfig value={{ provider: () => new Map() }}>
            {children}
        </SWRConfig>
    </I18nProvider>
);

describe('ManagementFtpInbox catch-up run reload', () => {
    let statusReads: number;

    beforeEach(() => {
        statusReads = 0;
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('imports once, reloads the status once, and shows the remaining queue', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            const target = String(url);
            if (target.endsWith('/api/management/ftp/process')) {
                return Promise.resolve(jsonResponse({ success: true, processed: 2 }));
            }
            if (target.endsWith('/api/management/ftp/status')) {
                statusReads += 1;
                // First load: 3 files waiting. After the import: 1 remains — a
                // batch the backend capped at `ftp.import.max_files` (D-35).
                return Promise.resolve(jsonResponse(statusWith(statusReads === 1 ? 3 : 1)));
            }
            // Anything else the tree pulls in is not under test here.
            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<ManagementFtpInbox />, { wrapper });

        // The card starts on the pre-import number…
        await waitFor(() => expect(screen.getByTestId('ftp-queue-count')).toHaveTextContent('3'));

        // …and ends on what actually remains, from the reloaded status.
        await waitFor(() => expect(screen.getByTestId('ftp-queue-count')).toHaveTextContent('1'));

        const processCalls = fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/api/management/ftp/process')).length;
        expect(processCalls).toBe(1);
        // Initial load plus exactly one reload — no second read per import.
        expect(statusReads).toBe(2);
    });

    it('reloads the status even when the import is rejected (N5)', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            const target = String(url);
            if (target.endsWith('/api/management/ftp/process')) {
                // The oversized-single-file rejection (413). It moves nothing,
                // but the reload must not be conditional on this resolving: a
                // 422 aborts mid-batch, and the same code path serves both.
                return Promise.resolve(jsonResponse({ error: 'Die Datei ist größer als das erlaubte Maximum.' }, 413));
            }
            if (target.endsWith('/api/management/ftp/status')) {
                statusReads += 1;
                return Promise.resolve(jsonResponse(statusWith(3)));
            }
            return Promise.resolve(jsonResponse({}));
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<ManagementFtpInbox />, { wrapper });

        await waitFor(() => expect(screen.getByTestId('ftp-queue-count')).toHaveTextContent('3'));

        // The reload really happened after the rejection…
        await waitFor(() => expect(statusReads).toBe(2));
        // …and the button is usable again, so the failure is not a dead end.
        await waitFor(() => expect(screen.getByRole('button', { name: 'Bilder Importieren' })).toBeEnabled());
    });
});
