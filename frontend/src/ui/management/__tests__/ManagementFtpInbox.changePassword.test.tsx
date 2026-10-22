import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../test-setup';
import ManagementFtpInbox from '../ManagementFtpInbox';
import { useFtp, type FtpStatus } from '../../../logic/useFtp';

/**
 * The self-service change of the camera password (D-51).
 *
 * The portal stores no camera password — it is generated, shown once and
 * discarded — so the confirmation for a change can only be the current password
 * itself, checked against the service. What the UI therefore has to get right
 * is the *shape* of the flow: an active account confirms before anything is
 * rotated, a pending account has no password to confirm and is provisioned
 * directly, a wrong password leaves the photographer where they were (the
 * dialog open, the input editable) and shows the server's own words, and the
 * reset stays reachable as the recovery path for a password that is lost.
 *
 * The failure cases matter as much as the success case here: a client that
 * shows a password after a failed attempt, or that answers a 429 with a retry,
 * spends the photographer's hourly quota on guesses (the backend counts both
 * paths against the same quota).
 *
 * `useFtp` is mocked, so the assertions are about what the component asks the
 * hook to do — the request itself is pinned in `logic/__tests__/useFtp.test.ts`
 * and end to end in `tests/e2e/photographer/ftp-password-change.spec.ts`.
 */

const { showToast } = vi.hoisted(() => ({ showToast: vi.fn() }));

vi.mock('../../../logic/useFtp', () => ({
    useFtp: vi.fn(),
}));

vi.mock('../../../logic/useGalleries', () => ({
    useProtectedGalleries: () => ({ tree: undefined }),
}));

vi.mock('../../components/UIContext', () => ({
    useUI: () => ({ showToast }),
}));

const status: FtpStatus = {
    ftp_folder: '/photographer',
    file_count: 0,
    quarantine_count: 0,
    current_target_gallery: null,
    ftp_account_status: 'active',
    ftp_provisioned_at: '2026-01-01T00:00:00Z',
    ftp_account_error: null,
    ftp_reset_limit_per_hour: 10,
    connection: {
        configured: true,
        host: 'ftp.example.org',
        username: 'kamera',
        path: '/',
        sftp_port: 2222,
        ftps_port: 990,
        pasv_port_start: 30000,
        pasv_port_end: 30009,
        ftps_tls_mode: 'explicit',
    },
};

let changeCredentials: Mock;
let resetCredentials: Mock;

function mockStatus(overrides: Partial<FtpStatus> = {}) {
    changeCredentials = vi.fn();
    resetCredentials = vi.fn();
    vi.mocked(useFtp).mockReturnValue({
        status: { ...status, ...overrides },
        isLoading: false,
        error: undefined,
        readSettled: true,
        setTargetGallery: vi.fn(),
        processInbox: vi.fn(),
        resetCredentials,
        changeCredentials,
        mutate: vi.fn(),
    } as unknown as ReturnType<typeof useFtp>);
}

/** The error shape `apiMutate` rejects with: the server's text plus the status. */
const apiError = (message: string, errorStatus: number) =>
    Object.assign(new Error(message), {status: errorStatus});

describe('ManagementFtpInbox camera password change', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockStatus();
    });

    it('asks for the current password before an active account is rotated', async () => {
        const user = userEvent.setup();
        renderWithProviders(<ManagementFtpInbox />);

        await user.click(screen.getByTestId('ftp-credentials-button'));

        const dialog = screen.getByRole('dialog', {name: 'Kamera-Passwort ändern'});
        const input = within(dialog).getByLabelText('Aktuelles Kamera-Passwort');
        expect(input).toHaveAttribute('type', 'password');
        // Mandatory, and marked as such in the markup (the star is CSS).
        expect(input).toBeRequired();

        // The click opened the dialog — it did not rotate anything.
        expect(resetCredentials).not.toHaveBeenCalled();
        expect(changeCredentials).not.toHaveBeenCalled();
    });

    it('provisions a pending account directly, without a dialog', async () => {
        mockStatus({ftp_account_status: 'pending'});
        const user = userEvent.setup();
        renderWithProviders(<ManagementFtpInbox />);

        await user.click(screen.getByTestId('ftp-credentials-button'));

        // A pending account has no password that could be confirmed, so there
        // is nothing to ask for.
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(resetCredentials).toHaveBeenCalledTimes(1);
        expect(changeCredentials).not.toHaveBeenCalled();
    });

    it('sends the current password and shows the new one exactly once', async () => {
        const newPassword = 'kamera-neu-4711';
        const notice = 'Dieses Passwort wird genau einmal angezeigt. Es ist nicht gespeichert.';
        changeCredentials.mockResolvedValue({success: true, password: newPassword, password_notice: notice});

        const user = userEvent.setup();
        renderWithProviders(<ManagementFtpInbox />);

        await user.click(screen.getByTestId('ftp-credentials-button'));
        await user.type(screen.getByLabelText('Aktuelles Kamera-Passwort'), 'kamera-alt-1234');
        await user.click(screen.getByTestId('ftp-change-submit'));

        await waitFor(() => expect(changeCredentials).toHaveBeenCalledWith('kamera-alt-1234'));

        // The one-time box on the card, with the server's own notice.
        expect(screen.getByRole('heading', {name: 'Neues Kamera-Passwort'})).toBeInTheDocument();
        expect(screen.getByText(newPassword)).toBeInTheDocument();
        expect(screen.getByText(notice)).toBeInTheDocument();
        // The dialog is gone: the password belongs on the card, not behind it.
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('reports a wrong current password with the server text, keeps the dialog open and shows no password', async () => {
        const serverMessage = 'Das aktuelle Kamera-Passwort ist nicht korrekt. Bitte prüfen Sie es und versuchen Sie es erneut.';
        changeCredentials.mockRejectedValue(apiError(serverMessage, 422));

        const user = userEvent.setup();
        renderWithProviders(<ManagementFtpInbox />);

        await user.click(screen.getByTestId('ftp-credentials-button'));
        await user.type(screen.getByLabelText('Aktuelles Kamera-Passwort'), 'falsches-passwort');
        await user.click(screen.getByTestId('ftp-change-submit'));

        await waitFor(() => expect(showToast).toHaveBeenCalledWith('error', serverMessage));

        // A wrong current password is corrected by typing a different one, so
        // the dialog has to stay open.
        expect(screen.getByRole('dialog', {name: 'Kamera-Passwort ändern'})).toBeInTheDocument();
        // Nothing was provisioned, so nothing may be offered.
        expect(screen.queryByRole('heading', {name: 'Neues Kamera-Passwort'})).not.toBeInTheDocument();
        expect(screen.queryByRole('button', {name: 'Verstanden, ausblenden'})).not.toBeInTheDocument();
    });

    it('offers the reset as the recovery path for a lost password', async () => {
        resetCredentials.mockResolvedValue({
            success: true,
            password: 'kamera-reset-4711',
            password_notice: 'Dieses Passwort wird genau einmal angezeigt.',
        });

        const user = userEvent.setup();
        renderWithProviders(<ManagementFtpInbox />);

        await user.click(screen.getByTestId('ftp-credentials-button'));
        await user.click(screen.getByTestId('ftp-change-recovery'));

        await waitFor(() => expect(resetCredentials).toHaveBeenCalledTimes(1));
        // The recovery path is the reset, not the change: it asks for no
        // password and reaches no confirmation.
        expect(changeCredentials).not.toHaveBeenCalled();
    });

    it('reports a spent quota with the server text and does not retry', async () => {
        const serverMessage = 'Zu viele Passwort-Änderungen. Bitte später erneut versuchen.';
        changeCredentials.mockRejectedValue(apiError(serverMessage, 429));

        const user = userEvent.setup();
        renderWithProviders(<ManagementFtpInbox />);

        await user.click(screen.getByTestId('ftp-credentials-button'));
        await user.type(screen.getByLabelText('Aktuelles Kamera-Passwort'), 'kamera-alt-1234');
        await user.click(screen.getByTestId('ftp-change-submit'));

        await waitFor(() => expect(showToast).toHaveBeenCalledWith('error', serverMessage));

        // The hourly quota covers the change and the reset alike, so exactly
        // one request may reach the endpoint.
        expect(changeCredentials).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('heading', {name: 'Neues Kamera-Passwort'})).not.toBeInTheDocument();
    });
});
