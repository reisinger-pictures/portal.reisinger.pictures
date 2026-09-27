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
});
