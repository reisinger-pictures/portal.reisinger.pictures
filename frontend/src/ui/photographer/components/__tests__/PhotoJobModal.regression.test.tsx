import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../../test-setup';
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

const statusOptions = [{ value: 'importiert', label: 'Importiert' }];
const assignee = { id: 'u2', name: 'Max Mustermann' };
const editingJob: PhotoJob = {
    id: 'j1',
    status: 'importiert',
    position: 0,
    owner: { id: 'owner', name: 'Owner' },
    assignee,
    created_at: '2026-01-01T00:00:00.000Z',
    title: 'Auftrag',
    lightroom_catalog: null,
    lightroom_catalog_is_mine: false,
    total_count: 24,
    selected_count: 12,
    target_gallery_id: null,
    notes: null,
};

function getControl(label: string): HTMLElement {
    return screen.getByText(label).closest('.form-control') as HTMLElement;
}

function getTextInput(label: string): HTMLInputElement {
    return within(getControl(label)).getByRole('textbox') as HTMLInputElement;
}

function getNumberInput(label: string): HTMLInputElement {
    return within(getControl(label)).getByRole('spinbutton') as HTMLInputElement;
}

function getSelect(label: string): HTMLSelectElement {
    return within(getControl(label)).getByRole('combobox') as HTMLSelectElement;
}

describe('PhotoJobModal board save regression', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useUsers).mockReturnValue({ users: [assignee] });
        vi.mocked(useProtectedGalleries).mockReturnValue({ tree: { root_galleries: [], groups: [] } });
        vi.mocked(useLightroomCatalogs).mockReturnValue({
            lightroomCatalogs: [],
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            remove: vi.fn(),
        });
        vi.mocked(useUI).mockReturnValue({ showToast: vi.fn() });
    });

    it('keeps the photo job data open when saving rejects', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        const onSave = vi.fn().mockRejectedValue(new Error('Speichern fehlgeschlagen'));
        renderWithProviders(
            <PhotoJobModal
                isOpen
                onClose={onClose}
                defaultStatus="importiert"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        await user.type(getTextInput('Titel'), 'Auftrag mit Daten');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Speichern' })).toBeEnabled());
        expect(onClose).not.toHaveBeenCalled();
        expect(getTextInput('Titel')).toHaveValue('Auftrag mit Daten');
    });

    it('sends zero and null values when clearing counts and assignee', async () => {
        const user = userEvent.setup();
        const onSave = vi.fn().mockResolvedValue(undefined);
        renderWithProviders(
            <PhotoJobModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="importiert"
                statusOptions={statusOptions}
                editing={editingJob}
                onSave={onSave}
            />,
        );

        await waitFor(() => expect(getNumberInput('Bilder gesamt')).toHaveValue(24));
        await user.clear(getNumberInput('Bilder gesamt'));
        await user.clear(getNumberInput('Bilder selektiert'));
        await user.selectOptions(getSelect('Zuständig'), '');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({
            total_count: 0,
            selected_count: 0,
            assignee_id: null,
        }));
    });
});
