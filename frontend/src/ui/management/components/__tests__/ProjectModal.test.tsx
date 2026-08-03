import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import { renderWithProviders } from '../../../../test-setup';
import userEvent from '@testing-library/user-event';
import ProjectModal from '../ProjectModal';
import type { Project } from '../../../../logic/useProjectsBoard';

vi.mock('../../../../logic/useUsers', () => ({
    useUsers: vi.fn(),
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: vi.fn(),
}));

vi.mock('../../../components/AutocompleteInput', () => {
    interface MockAutocompleteProps {
        label?: string;
        value: string;
        onChange: (value: string) => void;
    }

    const MockAutocompleteInput = ({ label, value, onChange }: MockAutocompleteProps) => (
        <div className="form-control">
            {label && <label className="label"><span className="label-text font-bold">{label}</span></label>}
            <input type="text" value={value} onChange={(e) => onChange(e.target.value)} />
        </div>
    );

    return { default: MockAutocompleteInput };
});

import { useUsers } from '../../../../logic/useUsers';
import { useUI } from '../../../components/UIContext';

const statusOptions = [
    { value: 'anfrage', label: 'Anfrage' },
    { value: 'angebot', label: 'Angebot' },
    { value: 'beauftragt', label: 'Beauftragt' },
    { value: 'rechnung', label: 'Rechnung' },
    { value: 'bezahlt', label: 'Bezahlt' },
    { value: 'storniert', label: 'Storniert' },
];

const editingProject: Project = {
    id: 'p1',
    status: 'bezahlt',
    position: 1,
    owner: { id: 'o1', name: 'Owner' },
    assignee: null,
    created_at: '2026-01-01T00:00:00.000Z',
    client_name: 'Max Mustermann',
    email: 'max@example.com',
    phone: null,
    package: null,
    price_cents: 250000,
    payment_status: 'paid',
    linked_photo_job_id: null,
    notes: null,
};

function setupMocks() {
    const showToast = vi.fn();
    vi.mocked(useUsers).mockReturnValue({ users: [] });
    vi.mocked(useUI).mockReturnValue({ showToast });
    return { showToast };
}

function getClientNameInput() {
    const formControl = screen.getByText('Kundenname').closest('.form-control') as HTMLElement;
    return within(formControl).getByRole('textbox') as HTMLInputElement;
}

function getStatusSelect() {
    const formControl = screen.getByText('Status').closest('.form-control') as HTMLElement;
    return within(formControl).getByRole('combobox') as HTMLSelectElement;
}

function getPaymentStatusSelect() {
    const formControl = screen.getByText('Zahlungsstatus').closest('.form-control') as HTMLElement;
    return within(formControl).getByRole('combobox') as HTMLSelectElement;
}

describe('ProjectModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setupMocks();
    });

    it('submits the defaultStatus when creating a new project', async () => {
        const user = userEvent.setup();
        const onSave = vi.fn().mockResolvedValue(undefined);

        renderWithProviders(
            <ProjectModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="angebot"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        await waitFor(() => {
            expect(getStatusSelect().value).toBe('angebot');
        });

        await user.type(getClientNameInput(), 'Max Mustermann');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ status: 'angebot' }));
    });

    it('submits the editing status when editing a project', async () => {
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

        await waitFor(() => {
            expect(getStatusSelect().value).toBe('bezahlt');
        });

        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ status: 'bezahlt' }));
    });

    it('submits the selected payment status when creating a project', async () => {
        const user = userEvent.setup();
        const onSave = vi.fn().mockResolvedValue(undefined);

        renderWithProviders(
            <ProjectModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="angebot"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        await waitFor(() => {
            expect(getPaymentStatusSelect().value).toBe('open');
        });

        await user.selectOptions(getPaymentStatusSelect(), 'paid');
        await user.type(getClientNameInput(), 'Max Mustermann');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ payment_status: 'paid' }));
    });

    it('resets the status to the new defaultStatus when reopened for a different column', async () => {
        const user = userEvent.setup();
        const onSave = vi.fn().mockResolvedValue(undefined);

        const { rerender } = renderWithProviders(
            <ProjectModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="anfrage"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        await waitFor(() => {
            expect(getStatusSelect().value).toBe('anfrage');
        });

        rerender(
            <ProjectModal
                isOpen={false}
                onClose={vi.fn()}
                defaultStatus="anfrage"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Speichern' })).not.toBeInTheDocument();

        rerender(
            <ProjectModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="beauftragt"
                statusOptions={statusOptions}
                onSave={onSave}
            />,
        );

        await waitFor(() => {
            expect(getStatusSelect().value).toBe('beauftragt');
        });

        await user.type(getClientNameInput(), 'Neuer Kunde');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ status: 'beauftragt' }));
    });
});
