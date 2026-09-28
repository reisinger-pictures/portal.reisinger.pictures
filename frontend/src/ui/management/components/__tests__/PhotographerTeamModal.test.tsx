import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen} from '@testing-library/react';
import {renderWithProviders} from '../../../../test-setup';
import PhotographerTeamModal from '../PhotographerTeamModal';
import type {GalleryGroup} from '../../../../logic/useGalleries';
import useSWR from 'swr';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: () => ({ showToast: vi.fn() }),
}));

// One assignable photographer and one super-admin (always filtered out).
const USERS = [
    {id: 'p1', name: 'Anna Fotografin', email: 'anna@example.com', is_photographer: true, is_super_admin: false, photographer_galleries: [], photographer_gallery_groups: []},
    {id: 'p2', name: 'Admin Root', email: 'root@example.com', is_photographer: true, is_super_admin: true, photographer_galleries: [], photographer_gallery_groups: []},
];

const item: GalleryGroup = {
    id: 'g1',
    name: 'Team Galerie',
    parent_id: null,
    restricted_photographers: true,
    effective_restricted_photographers: true,
};

function renderModal() {
    return renderWithProviders(
        <PhotographerTeamModal isOpen onClose={vi.fn()} item={item} isGroup={false} onUpdateState={vi.fn()} />,
    );
}

describe('PhotographerTeamModal bounded layout', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useSWR).mockImplementation((() => ({
            data: USERS,
            isLoading: false,
            mutate: vi.fn(),
        })) as never);
    });

    // The migration onto the shell's bounded layout. The gallery line and the
    // access-status select are the head, pinned above the photographer list.
    // This pins the wiring: dropping `bodyHead` would move the select into the
    // scroll region, where it could scroll out of reach — and the functional
    // E2E (`tests/e2e/photographer/team-access.spec.ts`) selects it without
    // scrolling, so it would stay green.
    it('keeps the status head out of the scroll region and bounds the box at 80vh', () => {
        renderModal();

        const box = screen.getByRole('dialog').firstElementChild as HTMLElement;
        expect(box).toHaveClass('max-h-80vh', 'flex', 'flex-col');
        expect(box).not.toHaveClass('max-h-90vh');

        const scroller = box.querySelector('.overflow-y-auto') as HTMLElement;
        expect(scroller).not.toBeNull();
        // A select that scrolls away is a select you cannot change.
        expect(scroller).not.toContainElement(screen.getByRole('combobox'));
        expect(scroller).not.toContainElement(screen.getByText('Team Galerie', {exact: true}));
        expect(scroller).toContainElement(screen.getByText('anna@example.com'));
    });
});
