import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../test-setup';
import ManagementFtpInbox from '../ManagementFtpInbox';
import { useFtp, type FtpStatus } from '../../../logic/useFtp';

vi.mock('../../../logic/useFtp', () => ({
    useFtp: vi.fn(),
}));

vi.mock('../../logic/useGalleries', () => ({
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

function renderInbox() {
    return renderWithProviders(<ManagementFtpInbox />);
}

async function openGuide() {
    const user = userEvent.setup();
    const { container } = renderInbox();
    await user.click(screen.getByText('Anleitung öffnen'));
    return container;
}

/**
 * The camera guide's bounded layout, which this component used to assemble
 * itself: `max-h-90vh flex flex-col` on the box plus a `flex-1 overflow-y-auto`
 * wrapper carrying the bottom fade — the second implementation of what
 * `ModalShell`'s `scrollableBody` mode does. The guide is six sections plus
 * tables, so it has to scroll rather than push its close button off screen.
 *
 * The one thing worth pinning is where the mask sits. `scroll-fade-bottom` fades
 * the last 2rem *of the element that scrolls*; on the box — which only bounds
 * the dialog — it masks nothing, and the scroll cut then slices the closing
 * alert with nothing softening it. So the assertion is not "the guide has a
 * fade" but "the fade is on the region with a scroll port under it, and the
 * padding that keeps content clear of the band travelled with it".
 */
describe('ManagementFtpInbox camera guide layout', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useFtp).mockReturnValue({
            status,
            isLoading: false,
            setTargetGallery: vi.fn(),
            processInbox: vi.fn(),
            resetCredentials: vi.fn(),
            mutate: vi.fn(),
        } as unknown as ReturnType<typeof useFtp>);
    });

    it('fades the region that scrolls, not the box that bounds it', async () => {
        const container = await openGuide();

        const box = container.querySelector<HTMLElement>('.modal-box');
        if (!box) throw new Error('the camera guide renders no .modal-box');
        const body = box.querySelector<HTMLElement>(':scope > .overflow-y-auto');
        if (!body) {
            throw new Error(
                'ModalShell built no bounded body — the camera guide is no longer opting into '
                + '`scrollableBody`. Box children: '
                + Array.from(box.children).map(child => child.className).join(' | '),
            );
        }

        // The box only bounds the dialog; the body has the height to spare and is
        // therefore the thing that scrolls.
        expect(box).toHaveClass('max-h-90vh', 'flex', 'flex-col');
        expect(body).toHaveClass('flex-1', 'min-h-0', 'overflow-y-auto');

        // The mask and the padding that keeps the closing alert clear of it, on
        // that body.
        expect(body).toHaveClass('scroll-fade-bottom', 'pb-8');

        // …and not on the box, where they would fade nothing. Left in both places
        // the test would still be green, so the negative is asserted too.
        expect(box).not.toHaveClass('overflow-y-auto', 'scroll-fade-bottom', 'pb-8');

        // The guide renders straight into the shell's region — the wrapper this
        // component owned is gone — and it brought no second scroll region with
        // it. Its first card is a direct child of the region, and the two tables
        // inside it scroll horizontally, so the count is exact.
        expect(screen.getByText('1. Das Konto anlegen — es gibt noch keins').closest('.card')?.parentElement).toBe(body);
        expect(container.querySelectorAll('.overflow-y-auto')).toHaveLength(1);
    });
});
