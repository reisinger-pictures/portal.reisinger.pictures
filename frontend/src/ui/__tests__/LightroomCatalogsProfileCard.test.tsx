import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import userEvent from '@testing-library/user-event';
import LightroomCatalogsProfileCard from '../components/LightroomCatalogsProfileCard';

vi.mock('../../logic/useLightroomCatalogs', () => ({
    useLightroomCatalogs: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import { useLightroomCatalogs } from '../../logic/useLightroomCatalogs';
import { usePermissions, type Permissions } from '../../logic/usePermissions';
import { useUI } from '../components/UIContext';

const mockCatalogs = [
    { id: 'c1', name: '2026-08', position: 0 },
    { id: 'c2', name: '2026-07', position: 1 },
];

const defaultPermissions: Permissions = {
    isPhotographer: true,
    isSuperAdmin: false,
    isStaff: false,
    isAdmin: false,
    isOrgAdmin: false,
    canEditMetadata: false,
    isPowerUser: false,
    canAccessB2BFeatures: false,
    canAccessProjectsBoard: false,
    canAccessProductionBoard: false,
    showOrgsSection: false,
    showCRM: false,
    showInvoicing: false,
    showPayouts: false,
};

describe('LightroomCatalogsProfileCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useLightroomCatalogs).mockReturnValue({
            lightroomCatalogs: mockCatalogs,
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            remove: vi.fn(),
        });

        vi.mocked(usePermissions).mockReturnValue(defaultPermissions);

        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(() => Promise.resolve(true)),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('renders the list of catalogs for a photographer', () => {
        renderWithProviders(<LightroomCatalogsProfileCard />);
        expect(screen.getByText('Deine Lightroom-Kataloge')).toBeInTheDocument();
        expect(screen.getByText('2026-08')).toBeInTheDocument();
        expect(screen.getByText('2026-07')).toBeInTheDocument();
    });

    it('renders the list of catalogs for a super admin', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...defaultPermissions,
            isPhotographer: false,
            isSuperAdmin: true,
        });

        renderWithProviders(<LightroomCatalogsProfileCard />);
        expect(screen.getByText('Deine Lightroom-Kataloge')).toBeInTheDocument();
        expect(screen.getByText('2026-08')).toBeInTheDocument();
    });

    it('renders nothing for non-photographers', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...defaultPermissions,
            isPhotographer: false,
            isSuperAdmin: false,
        });

        const { container } = renderWithProviders(<LightroomCatalogsProfileCard />);
        expect(container.innerHTML).toBe('');
    });

    it('shows loading spinner while loading', () => {
        vi.mocked(useLightroomCatalogs).mockReturnValue({
            lightroomCatalogs: undefined,
            isLoading: true,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            remove: vi.fn(),
        });

        renderWithProviders(<LightroomCatalogsProfileCard />);
        expect(document.querySelector('.loading-spinner')).toBeInTheDocument();
    });

    it('shows empty state when no catalogs exist', () => {
        vi.mocked(useLightroomCatalogs).mockReturnValue({
            lightroomCatalogs: [],
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            remove: vi.fn(),
        });

        renderWithProviders(<LightroomCatalogsProfileCard />);
        expect(screen.getByText('Noch keine Lightroom-Kataloge angelegt.')).toBeInTheDocument();
    });

    it('adds a new catalog via input and button', async () => {
        const user = userEvent.setup();
        const create = vi.fn().mockResolvedValue(undefined);
        const showToast = vi.fn();

        vi.mocked(useLightroomCatalogs).mockReturnValue({
            lightroomCatalogs: [],
            isLoading: false,
            error: undefined,
            create,
            update: vi.fn(),
            remove: vi.fn(),
        });
        vi.mocked(useUI).mockReturnValue({
            showToast,
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });

        renderWithProviders(<LightroomCatalogsProfileCard />);

        await user.type(screen.getByPlaceholderText('z.B. 2026-08'), '2026-09');
        await user.click(screen.getByText('Hinzufügen'));

        expect(create).toHaveBeenCalledWith('2026-09');
        expect(showToast).toHaveBeenCalledWith('success', 'Katalog hinzugefügt');
    });

    it('edits a catalog inline and saves the new name', async () => {
        const user = userEvent.setup();
        const update = vi.fn().mockResolvedValue(undefined);
        const showToast = vi.fn();

        vi.mocked(useLightroomCatalogs).mockReturnValue({
            lightroomCatalogs: mockCatalogs,
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update,
            remove: vi.fn(),
        });
        vi.mocked(useUI).mockReturnValue({
            showToast,
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });

        renderWithProviders(<LightroomCatalogsProfileCard />);

        const firstRow = screen.getByText('2026-08').closest('tr') as HTMLElement;
        const rowButtons = within(firstRow).getAllByRole('button');
        await user.click(rowButtons[0]);

        const editInput = within(firstRow).getByRole('textbox');
        await user.clear(editInput);
        await user.type(editInput, '2026-08 neu');
        await user.click(within(firstRow).getByRole('button', { name: 'Speichern' }));

        expect(update).toHaveBeenCalledWith('c1', '2026-08 neu');
        expect(showToast).toHaveBeenCalledWith('success', 'Katalog aktualisiert');
    });

    it('deletes a catalog after confirmation', async () => {
        const user = userEvent.setup();
        const remove = vi.fn().mockResolvedValue(undefined);
        const showToast = vi.fn();
        const confirm = vi.fn(() => Promise.resolve(true));

        vi.mocked(useLightroomCatalogs).mockReturnValue({
            lightroomCatalogs: mockCatalogs,
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            remove,
        });
        vi.mocked(useUI).mockReturnValue({
            showToast,
            confirm,
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });

        renderWithProviders(<LightroomCatalogsProfileCard />);

        const firstRow = screen.getByText('2026-08').closest('tr') as HTMLElement;
        const rowButtons = within(firstRow).getAllByRole('button');
        await user.click(rowButtons[1]);

        expect(confirm).toHaveBeenCalled();
        expect(remove).toHaveBeenCalledWith('c1');
        expect(showToast).toHaveBeenCalledWith('success', 'Katalog gelöscht');
    });
});
