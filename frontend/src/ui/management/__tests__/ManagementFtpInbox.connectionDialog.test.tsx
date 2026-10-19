import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../test-setup';
import ManagementFtpInbox from '../ManagementFtpInbox';
import { useFtp, type FtpStatus } from '../../../logic/useFtp';

vi.mock('../../../logic/useFtp', () => ({
    useFtp: vi.fn(),
}));

vi.mock('../../../logic/useGalleries', () => ({
    useProtectedGalleries: () => ({ tree: undefined }),
}));

vi.mock('../../components/UIContext', () => ({
    useUI: () => ({ showToast: vi.fn() }),
}));

const status: FtpStatus = {
    ftp_folder: 'upload',
    file_count: 2,
    current_target_gallery: null,
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

function mockStatus(overrides: Partial<FtpStatus> = {}) {
    vi.mocked(useFtp).mockReturnValue({
        status: { ...status, ...overrides },
        isLoading: false,
        error: undefined,
        readSettled: true,
        setTargetGallery: vi.fn(),
        processInbox: vi.fn(),
        resetCredentials: vi.fn(),
        mutate: vi.fn(),
    } as unknown as ReturnType<typeof useFtp>);
}

async function openConnectionDialog() {
    const user = userEvent.setup();
    renderWithProviders(<ManagementFtpInbox />);
    await user.click(screen.getByRole('button', { name: 'Verbindungsinformationen anzeigen' }));
    return {
        user,
        // `role="dialog"` sits on the <dialog> the shell renders; `testId`
        // (D-18) sits on its box, which is what the E2E assertions scope to.
        dialog: screen.getByRole('dialog', { name: 'Kamera-Verbindung' }),
    };
}

/**
 * The connection values moved off the landing page into a dialog the shell
 * owns. What has to hold: the card offers the action but never the values
 * (a stale port on a page nobody re-reads is what the move prevents), the
 * dialog renders every row the server declared — labels *and* values, since a
 * row whose value is missing is what a photographer retypes into the camera by
 * hand — and both shell-provided exits (Escape and the labelled close button)
 * bring it back.
 *
 * `ModalShell` is exercised through this caller on purpose: the dialog's
 * `testId` (D-18), its two-state width (D-13) and the shell-owned focus default
 * (D-12) are only observable where a caller renders the shell.
 */
describe('ManagementFtpInbox connection dialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockStatus();
    });

    it('shows the action on the card, but no connection value before the click', () => {
        renderWithProviders(<ManagementFtpInbox />);

        expect(screen.getByRole('button', { name: 'Verbindungsinformationen anzeigen' })).toBeInTheDocument();
        // The values are not inline anymore — neither the table nor a single
        // cell of it. The unconfigured notice lives behind the same button.
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText('ftp.example.org')).not.toBeInTheDocument();
        expect(screen.queryByText('2222')).not.toBeInTheDocument();
        expect(screen.queryByText('SFTP-Port')).not.toBeInTheDocument();
    });

    it('opens every declared row with label and value in the dialog', async () => {
        const { dialog } = await openConnectionDialog();

        // The shell owns the box; `testId` is the hook the E2E assertions use,
        // and it lands inside the dialog rather than next to it.
        expect(dialog).toContainElement(screen.getByTestId('ftp-connection-dialog'));

        const rows: Array<[string, string]> = [
            ['Server', 'ftp.example.org'],
            ['Benutzername', 'kamera'],
            ['SFTP-Port', '2222'],
            ['FTPS-Port', '990'],
            ['FTPS-Verschlüsselung', 'explicit'],
            ['Passiver Bereich (Firewall)', '30000\u201330009'],
            ['Zielordner', '/upload'],
        ];
        for (const [label, value] of rows) {
            expect(within(dialog).getByText(label)).toBeInTheDocument();
            expect(within(dialog).getByText(value)).toBeInTheDocument();
        }

        // The FTPS hint travels with the values: the port alone does not tell
        // the photographer which Canon menu completes the handshake.
        expect(within(dialog).getByText(/FTPS verwendet explizites TLS \(AUTH TLS\)/)).toBeInTheDocument();
    });

    it('closes the dialog with Escape', async () => {
        const { user, dialog } = await openConnectionDialog();
        expect(dialog).toBeInTheDocument();

        await user.keyboard('{Escape}');

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('closes the dialog via the labelled close button', async () => {
        const { user } = await openConnectionDialog();

        await user.click(screen.getByRole('button', { name: 'Schließen' }));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('shows the unconfigured notice in the dialog instead of a half-filled table', async () => {
        mockStatus({
            connection: {
                ...status.connection,
                configured: false,
                host: null,
                username: null,
                sftp_port: null,
                ftps_port: null,
                ftps_tls_mode: null,
                path: null,
            },
        });

        const { dialog } = await openConnectionDialog();

        expect(within(dialog).getByText('Verbindungsdaten der Kamera nicht verfügbar')).toBeInTheDocument();
        // No half-filled table: an empty port cell is what a photographer
        // pastes into a camera.
        expect(dialog.querySelector('table')).toBeNull();
    });
});
