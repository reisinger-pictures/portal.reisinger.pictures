import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen} from '@testing-library/react';
import {renderWithProviders} from '../../../../test-setup';
import userEvent from '@testing-library/user-event';
import GalleryAccessModal from '../GalleryAccessModal';
import useSWR from 'swr';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: () => ({ showToast: vi.fn() }),
}));

const USERS = [
    {id: 'u1', name: 'Mrs. Eloise Dickinson', email: 'prohaska.jalon@example.org', galleries: [{id: 'g1'}]},
    {id: 'u2', name: 'E2E photographer', email: 'e2e-photographer-0t4nlatx@example.com', galleries: []},
    {id: 'u3', name: 'E2E photographer', email: 'e2e-photographer-zxxo5jq6@example.com', galleries: [{id: 'other'}]},
    {id: 'u4', name: 'Admin Root', email: 'root@example.com', is_super_admin: true, galleries: []},
];

function setup() {
    vi.mocked(useSWR).mockImplementation((() => ({
        data: USERS,
        isLoading: false,
        mutate: vi.fn(),
    })) as never);
}

function renderModal() {
    return renderWithProviders(
        <GalleryAccessModal
            galleryId="g1"
            galleryName="UI Review Galerie"
            isOpen
            onClose={vi.fn()}
        />,
    );
}

describe('GalleryAccessModal user list affordances', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setup();
    });

    // The list is a scroll container, so its bottom edge cuts a row in half
    // whenever more users fit than there is room for. The count and the fade at
    // that edge are what make the cut read as "there is more below" instead of
    // as a broken render. This test pins the count, and with it the promise that
    // every user is still listed: a list that stops at the fold without saying
    // so would pass a visual check and still hide users.
    it('lists every user and states how many are listed', () => {
        renderModal();

        // Super-admins are filtered out by design, so three remain.
        expect(screen.getByText('3 Nutzer')).toBeInTheDocument();
        expect(screen.getByText('prohaska.jalon@example.org')).toBeInTheDocument();
        expect(screen.getByText('e2e-photographer-0t4nlatx@example.com')).toBeInTheDocument();
        expect(screen.getByText('e2e-photographer-zxxo5jq6@example.com')).toBeInTheDocument();
        expect(screen.queryByText('root@example.com')).not.toBeInTheDocument();
    });

    it('counts and lists only the users matching the search', async () => {
        const user = userEvent.setup();
        renderModal();

        await user.type(screen.getByPlaceholderText('Nutzer suchen...'), 'zxxo5jq6');

        expect(screen.getByText('1 Nutzer')).toBeInTheDocument();
        expect(screen.getByText('e2e-photographer-zxxo5jq6@example.com')).toBeInTheDocument();
        expect(screen.queryByText('prohaska.jalon@example.org')).not.toBeInTheDocument();
    });

    // The migration onto the shell's bounded layout. The search field used to be
    // pinned by a hand-rolled `max-h-80vh` box plus its own scroll list; now the
    // shell owns both. This pins that wiring: the head must stay outside the
    // region that scrolls, and the box must carry the named 80vh bound and not
    // the shell's 90vh default. Without it, dropping `bodyHead` would let the
    // search field scroll away with the list and the behaviour tests above would
    // still be green.
    it('keeps the search head out of the scroll region and bounds the box at 80vh', () => {
        renderModal();

        const box = screen.getByRole('dialog').firstElementChild as HTMLElement;
        expect(box).toHaveClass('max-h-80vh', 'flex', 'flex-col');
        expect(box).not.toHaveClass('max-h-90vh');

        const scroller = box.querySelector('.overflow-y-auto') as HTMLElement;
        expect(scroller).not.toBeNull();
        // The list scrolls; the head that scopes it does not live in that region.
        expect(scroller).not.toContainElement(screen.getByPlaceholderText('Nutzer suchen...'));
        expect(scroller).not.toContainElement(screen.getByText('3 Nutzer'));
        expect(scroller).toContainElement(screen.getByText('prohaska.jalon@example.org'));
    });
});
