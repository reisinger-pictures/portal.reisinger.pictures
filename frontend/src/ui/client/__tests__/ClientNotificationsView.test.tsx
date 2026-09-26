import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import useSWR from 'swr';
import { apiMutate } from '../../../api';
import { renderWithProviders } from '../../../test-setup';
import ClientNotificationsView from '../ClientNotificationsView';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('../../components/PageLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-testid="page-layout">{children}</div>,
}));

vi.mock('../../components/UIContext', () => ({
    useUI: () => ({ showToast: vi.fn() }),
}));

const preferences = {
    groups: [{ id: 'group-1', name: 'Hochzeiten', type: 'group' as const, wants_notifications: true }],
    galleries: [{ id: 'gallery-1', name: 'Portraits', type: 'gallery' as const, gallery_type: 'delivery' as const, wants_notifications: false }],
};

describe('ClientNotificationsView', () => {
    const mutateMock = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        mutateMock.mockReset();
        vi.mocked(useSWR).mockReturnValue({
            data: preferences,
            error: undefined,
            isLoading: false,
            mutate: mutateMock,
        } as never);
    });

    it('gives every notification toggle an entity-specific accessible name', () => {
        renderWithProviders(<ClientNotificationsView />);

        expect(screen.getByRole('checkbox', { name: 'Benachrichtigungen für Ordner Hochzeiten' })).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'Benachrichtigungen für Galerie Portraits' })).toBeInTheDocument();
    });

    it('allows a notification preference to be changed with the keyboard', async () => {
        const user = userEvent.setup();
        renderWithProviders(<ClientNotificationsView />);

        const groupToggle = screen.getByRole('checkbox', { name: 'Benachrichtigungen für Ordner Hochzeiten' });
        groupToggle.focus();
        await user.keyboard(' ');

        expect(apiMutate).toHaveBeenCalledWith(
            '/api/gallery-groups/group-1/opt-in',
            'POST',
            { wants_notifications: false },
        );
    });

    // FE-6 regression: the optimistic update must build fresh nested objects,
    // never mutate the object still referenced by the SWR cache.
    it('updates the cache immutably for the optimistic toggle', async () => {
        const user = userEvent.setup();
        renderWithProviders(<ClientNotificationsView />);

        const galleryToggle = screen.getByRole('checkbox', { name: 'Benachrichtigungen für Galerie Portraits' });
        await user.click(galleryToggle);

        expect(mutateMock).toHaveBeenCalled();
        const [optimistic] = mutateMock.mock.calls[0];
        expect(optimistic).not.toBe(preferences);
        expect(optimistic.galleries).not.toBe(preferences.galleries);
        expect(optimistic.galleries[0]).not.toBe(preferences.galleries[0]);
        expect(optimistic.galleries[0].wants_notifications).toBe(true);
        // The original cache value stays untouched.
        expect(preferences.galleries[0].wants_notifications).toBe(false);
    });
});
