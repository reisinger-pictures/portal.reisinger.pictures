import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../../test-setup';
import { useFtp, type FtpStatus } from '../../../logic/useFtp';
import ManagementFtpInbox from '../ManagementFtpInbox';

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

function renderInbox(quarantineCount: number, uploadPath: string | null = '/') {
    vi.mocked(useFtp).mockReturnValue({
        status: {
            ...status,
            quarantine_count: quarantineCount,
            connection: { ...status.connection, path: uploadPath },
        },
        isLoading: false,
        error: undefined,
        readSettled: true,
        setTargetGallery: vi.fn(),
        processInbox: vi.fn(),
        resetCredentials: vi.fn(),
        mutate: vi.fn(),
    } as unknown as ReturnType<typeof useFtp>);

    return renderWithProviders(<ManagementFtpInbox />);
}

describe('ManagementFtpInbox quarantine visibility', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows a singular warning with the SFTP quarantine path and retention notice', () => {
        renderInbox(1, '/');

        const warning = screen.getByRole('alert');
        expect(warning).toHaveTextContent('1 Datei liegt im Quarantäne-Ordner');
        expect(screen.getByText('/photographer/_quarantine', { exact: true })).toBeInTheDocument();
        expect(warning).toHaveTextContent('Sie bleiben erhalten und werden nicht gelöscht.');
    });

    it('includes a configured upload subpath in the SFTP quarantine path', () => {
        renderInbox(1, '/incoming');

        expect(screen.getByText('/photographer/incoming/_quarantine', { exact: true })).toBeInTheDocument();
    });

    it('uses the account home path when the upload path is unavailable', () => {
        renderInbox(1, null);

        expect(screen.getByText('/photographer/_quarantine', { exact: true })).toBeInTheDocument();
    });

    it('uses the plural warning when multiple files are quarantined', () => {
        renderInbox(2);

        expect(screen.getByRole('alert')).toHaveTextContent('2 Dateien liegen im Quarantäne-Ordner');
    });

    it('does not render the quarantine warning when the count is zero', () => {
        renderInbox(0);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});
