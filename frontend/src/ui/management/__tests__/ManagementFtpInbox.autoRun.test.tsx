import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest';
import { StrictMode } from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../test-setup';
import { I18nProvider } from '../../../logic/I18nProvider';
import ManagementFtpInbox from '../ManagementFtpInbox';
import { useFtp, type FtpStatus, type FtpTargetGallery } from '../../../logic/useFtp';

/**
 * The one-shot catch-up run on opening the FTP inbox (D-36), and the manual
 * import button it shares its code path with.
 *
 * SFTPGo's upload events are fire-and-forget, so a lost event leaves files in
 * the inbox until somebody looks. This page is that look, and it has to import
 * exactly once per mount: React 19 StrictMode double-invokes effects in
 * development, SWR revalidations hand the component a fresh status object, and a
 * plain re-render changes nothing about the situation. The tests below pin each
 * of those as "still one import", plus the two silent cases (empty inbox, no
 * target gallery), the failure path (which still reloads the queue — N5) and the
 * manual button (N6).
 *
 * `useFtp` is mocked here so the count of `processInbox` and `mutate` calls is
 * exact; the reload actually reaching the card is pinned separately, against the
 * real hook, in `ManagementFtpInbox.autoRunReload.test.tsx`.
 */

const { showToast } = vi.hoisted(() => ({ showToast: vi.fn() }));

vi.mock('../../../logic/useFtp', () => ({
    useFtp: vi.fn(),
}));

// `../../../` reaches `src/`, where the component imports the hook from. The
// shorter `../../logic/useGalleries` used elsewhere resolves to `src/ui/logic/…`,
// which does not exist — a mock that never applies.
vi.mock('../../../logic/useGalleries', () => ({
    useProtectedGalleries: () => ({ tree: undefined }),
}));

vi.mock('../../components/UIContext', () => ({
    useUI: () => ({ showToast }),
}));

const GALLERY: FtpTargetGallery = {
    id: 'gallery-1',
    name: 'Hochzeit Müller',
    slug: 'hochzeit-mueller',
    brand: 'rp',
};

const baseStatus: FtpStatus = {
    ftp_folder: 'upload',
    file_count: 3,
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
};

let currentStatus: FtpStatus;
let processInbox: Mock;
let mutate: Mock;

/**
 * Hand the component the status currently in `currentStatus`. A function
 * (not a fixed value) so a test can swap the object — with a new identity, the
 * way SWR replaces its value — and re-render without rebuilding the mock.
 *
 * `readSettled: true` is the hook's "the read this mount started has settled"
 * signal (`useFtp`). The mock has to report it because the catch-up run only
 * decides on a status the mount actually read — a mocked hook that stayed at
 * `false` would never import, and every test below would pass for the wrong
 * reason. `error: undefined` is the matching "and it did not fail" half.
 */
function installFtpMock() {
    processInbox = vi.fn().mockResolvedValue({ success: true, processed: 3 });
    mutate = vi.fn().mockResolvedValue(undefined);
    vi.mocked(useFtp).mockImplementation(() => ({
        status: currentStatus,
        isLoading: false,
        error: undefined,
        readSettled: true,
        setTargetGallery: vi.fn(),
        processInbox,
        resetCredentials: vi.fn(),
        mutate,
    } as unknown as ReturnType<typeof useFtp>));
}

const processCalls = () => processInbox.mock.calls.length;

describe('ManagementFtpInbox catch-up run', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        currentStatus = { ...baseStatus };
        installFtpMock();
    });

    it('runs exactly one import when the inbox has files and a target gallery is set', async () => {
        const { rerender } = renderWithProviders(<ManagementFtpInbox />);

        await waitFor(() => expect(processCalls()).toBe(1));
        await waitFor(() => expect(mutate).toHaveBeenCalledTimes(1));

        // A revalidation replaces the status value: same numbers, new identity.
        // The effect re-runs on that new identity — the guard is what keeps the
        // run at one.
        currentStatus = { ...baseStatus };
        rerender(<ManagementFtpInbox />);
        await act(async () => {});

        expect(processCalls()).toBe(1);
        expect(mutate).toHaveBeenCalledTimes(1);
    });

    it('stays at one run under StrictMode\u2019s double effect invocation', async () => {
        // `StrictMode` wraps the whole tree on purpose, so the development
        // double-invoke (setup → cleanup → setup) reaches the effect under test.
        // It does so from the outside of `I18nProvider` simply because that is
        // the outer boundary here; StrictMode's double-invoke applies to its
        // entire subtree regardless of how providers are nested inside it. The
        // mutation that proves this test is discriminating is "delete
        // `autoRunStarted` and watch it go red" — without the guard the two
        // setup passes both call `processInbox`.
        render(
            <StrictMode>
                <I18nProvider>
                    <ManagementFtpInbox />
                </I18nProvider>
            </StrictMode>,
        );

        await waitFor(() => expect(processCalls()).toBe(1));
        // Give the second setup/cleanup pass time to (not) fire.
        await act(async () => {});

        expect(processCalls()).toBe(1);
        expect(mutate).toHaveBeenCalledTimes(1);
    });

    it('does not run when the inbox is empty', async () => {
        currentStatus = { ...baseStatus, file_count: 0 };
        installFtpMock();

        renderWithProviders(<ManagementFtpInbox />);
        await act(async () => {});

        expect(processCalls()).toBe(0);
        expect(mutate).not.toHaveBeenCalled();
    });

    it('does not run when no target gallery is set', async () => {
        currentStatus = { ...baseStatus, current_target_gallery: null };
        installFtpMock();

        renderWithProviders(<ManagementFtpInbox />);
        await act(async () => {});

        expect(processCalls()).toBe(0);
        expect(mutate).not.toHaveBeenCalled();
    });

    it('reports a failed catch-up run with the server text, re-enables the button, and still reloads the queue', async () => {
        const serverMessage = 'Die Datei ist größer als das erlaubte Maximum.';
        processInbox.mockRejectedValue(new Error(serverMessage));

        renderWithProviders(<ManagementFtpInbox />);

        await waitFor(() => expect(showToast).toHaveBeenCalledWith('error', serverMessage));

        // N5: the reload is not conditional on a resolved `processInbox`. A 422
        // aborts the batch midway, so files may already have moved; a counter
        // that stayed on the pre-attempt number is the stale reading this pins
        // against. Exactly one reload, same as the success path.
        await waitFor(() => expect(mutate).toHaveBeenCalledTimes(1));

        // The run's `finally` clears the spinner; a permanently disabled button
        // would leave the photographer without the manual path.
        await waitFor(() => expect(screen.getByRole('button', { name: 'Bilder Importieren' })).toBeEnabled());
    });

    /**
     * The manual button (N6). It shares `handleProcess` with the catch-up run,
     * but "shares the code path" is a claim about the source, not about the
     * behaviour: DoD §2 wants the UI action itself under test. The setup puts the
     * component in the state a photographer is actually in when they click — the
     * inbox is empty on mount (so the catch-up run spends its one attempt
     * silently), files then arrive, and the button is the only way to move them.
     */
    async function renderWithManualButtonReady() {
        currentStatus = { ...baseStatus, file_count: 0 };
        installFtpMock();

        const { rerender } = renderWithProviders(<ManagementFtpInbox />);
        await act(async () => {});
        expect(processCalls()).toBe(0);

        currentStatus = { ...baseStatus, file_count: 3 };
        rerender(<ManagementFtpInbox />);

        await waitFor(() => expect(screen.getByRole('button', { name: 'Bilder Importieren' })).toBeEnabled());
    }

    it('imports on the manual button, with its own toast and reload (N6)', async () => {
        await renderWithManualButtonReady();

        await userEvent.click(screen.getByRole('button', { name: 'Bilder Importieren' }));

        await waitFor(() => expect(processCalls()).toBe(1));
        await waitFor(() => expect(mutate).toHaveBeenCalledTimes(1));
        expect(showToast).toHaveBeenCalledWith('success', 'Import abgeschlossen. 3 Bilder wurden verschoben.');
    });

    it('reports a failed manual import with the server text, reloads, and re-enables the button (N5, N6)', async () => {
        await renderWithManualButtonReady();
        const serverMessage = 'Die Datei ist größer als das erlaubte Maximum.';
        processInbox.mockRejectedValue(new Error(serverMessage));

        await userEvent.click(screen.getByRole('button', { name: 'Bilder Importieren' }));

        await waitFor(() => expect(showToast).toHaveBeenCalledWith('error', serverMessage));
        await waitFor(() => expect(mutate).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Bilder Importieren' })).toBeEnabled());
    });
});
