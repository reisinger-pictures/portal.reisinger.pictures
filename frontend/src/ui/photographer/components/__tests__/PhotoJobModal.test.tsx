import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '../../../../test-setup';
import userEvent from '@testing-library/user-event';
import PhotoJobModal from '../PhotoJobModal';

vi.mock('../../../../logic/useUsers', () => ({
    useUsers: vi.fn(),
}));

vi.mock('../../../../logic/useGalleries', () => ({
    useProtectedGalleries: vi.fn(),
}));

vi.mock('../../../../logic/useLightroomCatalogs', () => ({
    useLightroomCatalogs: vi.fn(),
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import { useUsers } from '../../../../logic/useUsers';
import { useProtectedGalleries } from '../../../../logic/useGalleries';
import { useLightroomCatalogs } from '../../../../logic/useLightroomCatalogs';
import { useUI } from '../../../components/UIContext';

const catalogs = [
    { id: 'c1', name: '2026-08', position: 0 },
    { id: 'c2', name: '2026-07', position: 1 },
];

function setupMocks(overrides: Record<string, unknown> = {}) {
    const showToast = vi.fn();
    vi.mocked(useUsers).mockReturnValue({ users: [] });
    vi.mocked(useProtectedGalleries).mockReturnValue({
        tree: { root_galleries: [], groups: [] },
    });
    vi.mocked(useLightroomCatalogs).mockReturnValue({
        lightroomCatalogs: catalogs,
        isLoading: false,
        error: undefined,
        create: vi.fn(),
        update: vi.fn(),
        remove: vi.fn(),
        ...overrides,
    });
    vi.mocked(useUI).mockReturnValue({ showToast });
    return { showToast };
}

function getCatalogSelect() {
    const formControl = screen.getByText('Lightroom-Katalog').closest('.form-control') as HTMLElement;
    return within(formControl).getByRole('combobox');
}

describe('PhotoJobModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setupMocks();
    });

    it('renders catalog options in the Lightroom-Katalog select', () => {
        renderWithProviders(
            <PhotoJobModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="shooting"
                statusOptions={[{ value: 'shooting', label: 'Shooting' }]}
                onSave={vi.fn()}
            />,
        );

        const select = getCatalogSelect();
        expect(within(select).getByRole('option', { name: 'Kein Katalog' })).toBeInTheDocument();
        expect(within(select).getByRole('option', { name: '2026-08' })).toBeInTheDocument();
        expect(within(select).getByRole('option', { name: '2026-07' })).toBeInTheDocument();
    });

    it('renders only the empty option when the catalogs request errors', () => {
        setupMocks({ error: new Error('Kaputt'), lightroomCatalogs: undefined });

        renderWithProviders(
            <PhotoJobModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="shooting"
                statusOptions={[{ value: 'shooting', label: 'Shooting' }]}
                onSave={vi.fn()}
            />,
        );

        const select = getCatalogSelect();
        expect(within(select).getAllByRole('option')).toHaveLength(1);
        expect(within(select).getByRole('option', { name: 'Kein Katalog' })).toBeInTheDocument();
    });

    it('submits the selected catalog name as lightroom_catalog', async () => {
        const user = userEvent.setup();
        const onSave = vi.fn().mockResolvedValue(undefined);

        renderWithProviders(
            <PhotoJobModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="shooting"
                statusOptions={[{ value: 'shooting', label: 'Shooting' }]}
                onSave={onSave}
            />,
        );

        const titleInput = screen.getByText('Titel').closest('.form-control')!.querySelector('input')!;
        await user.type(titleInput, 'Hochzeit Sommer');

        await user.selectOptions(getCatalogSelect(), '2026-08');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ lightroom_catalog: '2026-08' }));
    });
});
