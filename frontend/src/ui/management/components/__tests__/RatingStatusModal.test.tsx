import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithProviders } from '../../../../test-setup';
import RatingStatusModal from '../RatingStatusModal';
import { fetcher } from '../../../../api';

vi.mock('../../../../api', () => ({
    fetcher: vi.fn(),
}));

const exportRatings = [
    {
        lr_uuid: 'photo-1',
        filename: 'portrait.jpg',
        avg_rating: 4,
        all_comments: 'Great portrait',
        thumb_url: '/media/portrait.jpg',
    },
];

const ratingStatus = {
    users: [
        {
            user_id: 'user-1',
            name: 'Alex Example',
            email: 'alex@example.com',
            rated_count: 1,
        },
    ],
    total_photos: 1,
};

describe('RatingStatusModal', () => {
    beforeEach(() => {
        vi.mocked(fetcher).mockReset();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('loads both management responses through the shared refresh-aware fetcher', async () => {
        vi.mocked(fetcher)
            .mockResolvedValueOnce(exportRatings)
            .mockResolvedValueOnce(ratingStatus);

        renderWithProviders(
            <RatingStatusModal galleryId="gallery-1" isOpen={true} onClose={vi.fn()} />,
        );

        await waitFor(() => {
            expect(fetcher).toHaveBeenNthCalledWith(1, '/api/management/galleries/gallery-1/export');
            expect(fetcher).toHaveBeenNthCalledWith(2, '/api/management/galleries/gallery-1/rating-status');
        });

        expect(await screen.findByText('Alex Example')).toBeInTheDocument();
        expect(screen.getByText('portrait.jpg')).toBeInTheDocument();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('shows the error state when a shared request fails', async () => {
        vi.mocked(fetcher).mockRejectedValue(new Error('request failed'));

        renderWithProviders(
            <RatingStatusModal galleryId="gallery-1" isOpen={true} onClose={vi.fn()} />,
        );

        expect(await screen.findByText(/Fehler beim Laden der Bewertungen\./)).toBeInTheDocument();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('starts focus on the shell close button, as the dialog has no focusable content of its own', async () => {
        // D-12's fallback: this dialog declares no `autoFocus` and its two
        // tables hold no focusable element, so the labelled close button is the
        // only reachable start. The other such dialog is the camera guide in
        // ManagementFtpInbox; this is the one a unit test can render directly.
        vi.mocked(fetcher)
            .mockResolvedValueOnce(exportRatings)
            .mockResolvedValueOnce(ratingStatus);

        renderWithProviders(
            <RatingStatusModal galleryId="gallery-1" isOpen={true} onClose={vi.fn()} />,
        );
        await screen.findByText('Alex Example');

        expect(screen.getByRole('button', { name: 'Schließen' })).toHaveFocus();
    });
});

/**
 * The bounded layout this dialog used to build for itself.
 *
 * Both tables grow with the gallery — the export is one row per rated photo — so
 * the dialog has always needed a bounded box and a scroll region inside it. It
 * carried `max-h-90vh flex flex-col` on the box and a `flex-1 overflow-y-auto`
 * wrapper of its own, which is the second, parallel implementation of what
 * `ModalShell`'s `scrollableBody` mode now does for it. These two tests pin the
 * migration's result: the scroll boundary is the region the shell built, both
 * sections sit directly in it (so the 2rem gap between them is unchanged and no
 * second scroll port is nested inside), and the loading state still fills the
 * space it is handed.
 */
describe('RatingStatusModal layout', () => {
    beforeEach(() => {
        vi.mocked(fetcher).mockReset();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    /**
     * The box and the region that scrolls, located by structure rather than by a
     * guessed index — the approach `ModelDetailModal.test.tsx` already uses for
     * the same two elements. A named throw, because the only way to get here is
     * that the shell built no bounded body, i.e. the dialog stopped opting in.
     */
    function boundedRegions(container: HTMLElement): { box: HTMLElement; body: HTMLElement } {
        const box = container.querySelector<HTMLElement>('.modal-box');
        if (!box) throw new Error('RatingStatusModal renders no .modal-box');
        const body = box.querySelector<HTMLElement>(':scope > .overflow-y-auto');
        if (!body) {
            throw new Error(
                'ModalShell built no bounded body — RatingStatusModal is no longer opting into '
                + '`scrollableBody`. Box children: '
                + Array.from(box.children).map(child => child.className).join(' | '),
            );
        }
        return { box, body };
    }

    it('lets the shell own the scroll region and keeps the gap between both sections', async () => {
        vi.mocked(fetcher)
            .mockResolvedValueOnce(exportRatings)
            .mockResolvedValueOnce(ratingStatus);

        const { container } = renderWithProviders(
            <RatingStatusModal galleryId="gallery-1" isOpen={true} onClose={vi.fn()} />,
        );
        await screen.findByText('Alex Example');

        const { box, body } = boundedRegions(container);

        // The box bounds the dialog; the region below it does the scrolling, and
        // both sections sit directly in that region. The wrapper this component
        // used to own is gone, which is what keeps `space-y-8` a gap between two
        // siblings rather than a margin between a wrapper and a section.
        expect(box).toHaveClass('max-h-90vh', 'flex', 'flex-col');
        expect(body).toHaveClass('flex-1', 'min-h-0', 'overflow-y-auto', 'pr-2');
        expect(body).toHaveClass('space-y-8');
        // The section `<div>`s wrap the headings, and they are the body's direct
        // children — which is what `space-y-8` spaces.
        expect(screen.getByText('Beteiligte Personen').parentElement?.parentElement).toBe(body);
        expect(screen.getByText('Detaillierte Auswertungen (Bild-Bewertungen)').parentElement?.parentElement).toBe(body);

        // One scroll region for the whole dialog, not the shell's plus one of its
        // own: a second `flex-1 overflow-y-auto` nested inside the shell's region
        // is the defect this migration removes, so its return is pinned. The two
        // tables scroll horizontally, which is why this count is exact.
        expect(container.querySelectorAll('.overflow-y-auto')).toHaveLength(1);
    });

    it('fills the scrolling region while loading, so the spinner stays centred', async () => {
        // The spinner was a flex item of the box and filled the height the header
        // left over. Its ancestor is the shell's scroll region now — a block — so
        // `flex-1` would resolve to nothing and the spinner would sit at the top
        // of an otherwise empty dialog. A height is what keeps the centring.
        vi.mocked(fetcher).mockReturnValue(new Promise(() => {}));

        const { container } = renderWithProviders(
            <RatingStatusModal galleryId="gallery-1" isOpen={true} onClose={vi.fn()} />,
        );

        const { body } = boundedRegions(container);
        const spinner = body.querySelector('.loading-spinner');

        expect(spinner).not.toBeNull();
        expect(spinner?.parentElement).toHaveClass('h-full', 'items-center', 'justify-center');
    });
});
