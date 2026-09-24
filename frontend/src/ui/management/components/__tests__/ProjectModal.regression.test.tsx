import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../../test-setup';
import ProjectModal from '../ProjectModal';
import type { Project } from '../../../../logic/useProjectsBoard';

vi.mock('../../../../logic/useUsers', () => ({
    useUsers: vi.fn(),
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: vi.fn(),
}));

vi.mock('../../../components/AutocompleteInput', () => ({
    default: ({ label, value, onChange }: { label?: string; value: string; onChange: (value: string) => void }) => (
        <div className="form-control">
            {label && <label className="label"><span className="label-text font-bold">{label}</span></label>}
            <input type="text" value={value} onChange={event => onChange(event.target.value)} />
        </div>
    ),
}));

import { useUsers } from '../../../../logic/useUsers';
import { useUI } from '../../../components/UIContext';

const statusOptions = [
    { value: 'anfrage', label: 'Anfrage' },
    { value: 'angebot', label: 'Angebot' },
];

const editingProject: Project = {
    id: 'p1',
    status: 'anfrage',
    position: 0,
    owner: { id: 'owner', name: 'Owner' },
    assignee: { id: 'u2', name: 'Max Mustermann' },
    created_at: '2026-01-01T00:00:00.000Z',
    client_name: 'Kunde',
    email: '',
    phone: null,
    package: null,
    price_cents: 12345,
    payment_status: 'open',
    linked_photo_job_id: null,
    notes: null,
};

function getControl(label: string): HTMLElement {
    return screen.getByText(label).closest('.form-control') as HTMLElement;
}

function getInput(label: string): HTMLInputElement {
    return within(getControl(label)).getByRole('textbox') as HTMLInputElement;
}

function getNumberInput(label: string): HTMLInputElement {
    return within(getControl(label)).getByRole('spinbutton') as HTMLInputElement;
}

function getSelect(label: string): HTMLSelectElement {
    return within(getControl(label)).getByRole('combobox') as HTMLSelectElement;
}

describe('ProjectModal board save regression', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useUI).mockReturnValue({ showToast: vi.fn() });
        vi.mocked(useUsers).mockReturnValue({ users: [{ id: 'u2', name: 'Max Mustermann' }] });
    });

    it('keeps the project data open when saving rejects', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        const onSave = vi.fn().mockRejectedValue(new Error('Speichern fehlgeschlagen'));
        renderWithProviders(
            <ProjectModal
                isOpen
                onClose={onClose}
                defaultStatus="anfrage"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        await user.type(getInput('Kundenname'), 'Projekt mit Daten');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Speichern' })).toBeEnabled());
        expect(onClose).not.toHaveBeenCalled();
        expect(getInput('Kundenname')).toHaveValue('Projekt mit Daten');
    });

    it('sends explicit null values when clearing price and assignee', async () => {
        const user = userEvent.setup();
        const onSave = vi.fn().mockResolvedValue(undefined);
        renderWithProviders(
            <ProjectModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="anfrage"
                statusOptions={statusOptions}
                editing={editingProject}
                onSave={onSave}
            />,
        );

        await waitFor(() => expect(getNumberInput('Preis (€)')).toHaveValue(123.45));
        await user.clear(getNumberInput('Preis (€)'));
        await user.selectOptions(getSelect('Zuständig'), '');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({
            price_cents: null,
            assignee_id: null,
        }));
    });
});
