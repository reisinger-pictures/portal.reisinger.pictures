import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import { renderWithProviders } from '../../../../test-setup';
import userEvent from '@testing-library/user-event';
import PhotoJobModal from '../PhotoJobModal';
import type { PhotoJob } from '../../../../logic/useProductionBoard';

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

const statusOptions = [
    { value: 'shooting', label: 'Shooting' },
    { value: 'culling', label: 'Culling' },
    { value: 'bearbeitung', label: 'Bearbeitung' },
    { value: 'export', label: 'Export' },
    { value: 'veroeffentlicht', label: 'Veröffentlicht' },
    { value: 'abgebrochen', label: 'Abgebrochen' },
];

const editingPhotoJob: PhotoJob = {
    id: 'j1',
    status: 'veroeffentlicht',
    position: 2,
    owner: { id: 'o1', name: 'Owner' },
    assignee: null,
    created_at: '2026-01-01T00:00:00.000Z',
    title: 'Hochzeit Sommer',
    lightroom_catalog: null,
    lightroom_catalog_is_mine: false,
    total_count: 0,
    selected_count: 0,
    target_gallery_id: null,
    notes: null,
};

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

function getStatusSelect() {
    const formControl = screen.getByText('Status').closest('.form-control') as HTMLElement;
    return within(formControl).getByRole('combobox') as HTMLSelectElement;
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

    it('submits the notes textarea as notes', async () => {
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

        const notesTextarea = screen.getByText('Notiz').closest('.form-control')!.querySelector('textarea')!;
        await user.type(notesTextarea, 'Wichtige Notiz für die Bearbeitung');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ notes: 'Wichtige Notiz für die Bearbeitung' }));
    });

    it('submits notes as null when left empty', async () => {
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
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ notes: null }));
    });

    it('submits the defaultStatus when creating a new photo job', async () => {
        const user = userEvent.setup();
        const onSave = vi.fn().mockResolvedValue(undefined);

        renderWithProviders(
            <PhotoJobModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="culling"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        await waitFor(() => {
            expect(getStatusSelect().value).toBe('culling');
        });

        const titleInput = screen.getByText('Titel').closest('.form-control')!.querySelector('input')!;
        await user.type(titleInput, 'Hochzeit Sommer');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ status: 'culling' }));
    });

    it('submits the editing status when editing a photo job', async () => {
        const user = userEvent.setup();
        const onSave = vi.fn().mockResolvedValue(undefined);

        renderWithProviders(
            <PhotoJobModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="culling"
                statusOptions={statusOptions}
                editing={editingPhotoJob}
                onSave={onSave}
            />,
        );

        await waitFor(() => {
            expect(getStatusSelect().value).toBe('veroeffentlicht');
        });

        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ status: 'veroeffentlicht' }));
    });

    it('resets the status to the new defaultStatus when reopened for a different column', async () => {
        const user = userEvent.setup();
        const onSave = vi.fn().mockResolvedValue(undefined);

        const { rerender } = renderWithProviders(
            <PhotoJobModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="culling"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        await waitFor(() => {
            expect(getStatusSelect().value).toBe('culling');
        });

        rerender(
            <PhotoJobModal
                isOpen={false}
                onClose={vi.fn()}
                defaultStatus="culling"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Speichern' })).not.toBeInTheDocument();

        rerender(
            <PhotoJobModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="bearbeitung"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        await waitFor(() => {
            expect(getStatusSelect().value).toBe('bearbeitung');
        });

        const titleInput = screen.getByText('Titel').closest('.form-control')!.querySelector('input')!;
        await user.type(titleInput, 'Hochzeit Sommer');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ status: 'bearbeitung' }));
    });
});
