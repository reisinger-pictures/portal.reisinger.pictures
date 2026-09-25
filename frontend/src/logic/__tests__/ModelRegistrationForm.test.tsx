import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../test-setup';
import { RegistrationForm } from '../../ui/ModelRegistrationView';
import { MAX_PERSONS_PER_REGISTRATION, type ModelRegistrationCheck } from '../modelRegistration';

const catalog: ModelRegistrationCheck = {
    brand: 'rp',
    status: 'open',
    email: 'manager@example.com',
    person_count: 0,
    expires_at: null,
    catalog_version: 'v1',
    categories: [
        { key: 'portrait', label: 'Portrait', description: '', requires_age_proof: false },
        { key: 'bikini', label: 'Bikini', description: '', requires_age_proof: true },
    ],
    sections: {
        basisdaten: {
            key: 'basisdaten',
            label: 'Basisdaten',
            questions: [
                { key: 'first_name', label: 'Vorname', type: 'text', required: true, scope: 'person' },
                { key: 'email', label: 'E-Mail', type: 'email', required: true, scope: 'person' },
                { key: 'birthdate', label: 'Geburtsdatum', type: 'date', required: false, scope: 'person' },
                { key: 'experience_bikini', label: 'Erfahrung: Bikini', type: 'select', required: false, scope: 'person', options: ['keine', 'Anfänger'] },
            ],
        },
        einwilligungen: {
            key: 'einwilligungen',
            label: 'Einwilligungen',
            questions: [
                { key: 'age_proof', label: 'Altersnachweis', type: 'file', required: true, scope: 'person', visible_if: [{ type: 'requires_age_proof' }] },
                { key: 'consent_privacy', label: 'DSGVO', type: 'checkbox', required: true, scope: 'person' },
                { key: 'consent_all_persons', label: 'Alle Personen einverstanden', type: 'checkbox', required: true, scope: 'person', visible_if: [{ type: 'is_manager' }] },
            ],
        },
        sonstiges: {
            key: 'sonstiges',
            label: 'Sonstiges',
            questions: [
                { key: 'act_notes', label: 'Anmerkungen zum Act', type: 'textarea', required: false, scope: 'act' },
            ],
        },
    },
};

describe('RegistrationForm person state', () => {
    const submit = vi.fn();

    const renderForm = (props: {
        submit: typeof submit;
        onSubmitted?: (result: { success: true; act_id: string; person_count: number }) => void;
        onFatalError?: (message: string) => void;
    }) => renderWithProviders(
        <RegistrationForm
            catalog={catalog}
            submit={props.submit}
            onSubmitted={props.onSubmitted ?? vi.fn()}
            onFatalError={props.onFatalError ?? vi.fn()}
        />,
    );

    beforeEach(() => {
        vi.clearAllMocks();
        submit.mockResolvedValue({ success: true, act_id: 'a1', person_count: 1 });
    });

    it('renders one person block initially', () => {
        renderForm({ submit });
        expect(screen.getByTestId('model-person-0')).toBeInTheDocument();
        expect(screen.queryByTestId('model-person-1')).not.toBeInTheDocument();
    });

    it('adds and removes person blocks', async () => {
        const user = userEvent.setup();
        renderForm({ submit });

        await user.click(screen.getByTestId('add-person'));
        expect(screen.getByTestId('model-person-1')).toBeInTheDocument();

        const removeButtons = screen.getAllByRole('button', { name: /entfernen/i });
        await user.click(removeButtons[1]);
        expect(screen.queryByTestId('model-person-1')).not.toBeInTheDocument();
        expect(screen.getByTestId('model-person-0')).toBeInTheDocument();
    });

    it('hides the remove action when only one person remains', () => {
        renderForm({ submit });
        expect(screen.queryByRole('button', { name: /entfernen/i })).not.toBeInTheDocument();
    });

    it('stops at the person limit and prevents an over-limit append', async () => {
        const user = userEvent.setup();
        renderForm({ submit });
        const addButton = screen.getByTestId('add-person');

        for (let index = 1; index < MAX_PERSONS_PER_REGISTRATION; index += 1) {
            await user.click(addButton);
        }

        expect(screen.getAllByTestId(/^model-person-\d+$/)).toHaveLength(MAX_PERSONS_PER_REGISTRATION);
        expect(addButton).toBeDisabled();
        expect(screen.getByRole('status')).toHaveTextContent(
            `Maximal ${MAX_PERSONS_PER_REGISTRATION} Personen pro Registrierung.`,
        );

        await user.click(addButton);
        expect(screen.getAllByTestId(/^model-person-\d+$/)).toHaveLength(MAX_PERSONS_PER_REGISTRATION);
    });

    it('resets a full act and reopens multi-person entry', async () => {
        const user = userEvent.setup();
        renderForm({ submit });
        const addButton = screen.getByTestId('add-person');

        for (let index = 1; index < MAX_PERSONS_PER_REGISTRATION; index += 1) {
            await user.click(addButton);
        }
        expect(addButton).toBeDisabled();

        await user.click(screen.getByRole('radio', { name: 'Eine Person' }));
        expect(screen.getAllByTestId(/^model-person-\d+$/)).toHaveLength(1);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(addButton).not.toBeDisabled();

        await user.click(screen.getByRole('radio', { name: 'Mehrere Personen' }));
        expect(screen.getAllByTestId(/^model-person-\d+$/)).toHaveLength(2);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('shows the manager consent only for the manager person and hides the radio for a single person', async () => {
        const user = userEvent.setup();
        renderForm({ submit });

        // Single person: no manager radio (implicit manager).
        expect(screen.queryByLabelText('Managerperson')).not.toBeInTheDocument();

        await user.click(screen.getByTestId('add-person'));

        // Person 0 is the manager by default.
        expect(screen.getAllByText('Alle Personen einverstanden')).toHaveLength(1);

        const managerRadios = screen.getAllByLabelText('Managerperson');
        await user.click(managerRadios[1]);
        expect(screen.getAllByText('Alle Personen einverstanden')).toHaveLength(1);
    });

    it('shows the computed age next to the birthdate', () => {
        renderForm({ submit });
        fireEvent.change(screen.getByLabelText('Geburtsdatum'), { target: { value: '2000-01-01' } });
        expect(screen.getByTestId('birthdate-age')).toHaveTextContent(/\d+ Jahre/);
    });

    it('caps photo uploads at five per person', () => {
        renderForm({ submit });
        const input = screen.getByTestId('model-photos').querySelector('input[type="file"]') as HTMLInputElement;
        const files = Array.from({ length: 5 }, (_, i) => new File(['x'], `foto-${i}.jpg`, { type: 'image/jpeg' }));
        fireEvent.change(input, { target: { files } });

        expect(screen.getAllByLabelText('Sichtbarkeit')).toHaveLength(5);
        expect(input).toBeDisabled();
    });

    it('renders the act notes only for a multi-person act', async () => {
        const user = userEvent.setup();
        renderForm({ submit });
        expect(screen.queryByLabelText('Anmerkungen zum Act')).not.toBeInTheDocument();
        await user.click(screen.getByTestId('add-person'));
        expect(screen.getByLabelText('Anmerkungen zum Act')).toBeInTheDocument();
    });

    it('reveals the age proof upload once a protected category is selected', async () => {
        renderForm({ submit });

        expect(screen.queryByLabelText('Altersnachweis')).not.toBeInTheDocument();

        // Experience renders as a slider now; index 1 = "Anfänger".
        fireEvent.change(screen.getByLabelText('Erfahrung: Bikini'), { target: { value: '1' } });

        expect(await screen.findByLabelText('Altersnachweis')).toBeInTheDocument();
    });

    it('submits the collected values with the manager index', async () => {
        const user = userEvent.setup();
        const onSubmitted = vi.fn();
        renderForm({ submit, onSubmitted });

        await user.type(screen.getByLabelText('Vorname'), 'Maria');
        await user.type(screen.getByLabelText('E-Mail'), 'maria@example.com');
        await user.click(screen.getByLabelText('DSGVO'));
        await user.click(screen.getByLabelText('Alle Personen einverstanden'));
        await user.click(screen.getByRole('button', { name: /registrierung absenden/i }));

        expect(submit).toHaveBeenCalledTimes(1);
        const values = submit.mock.calls[0][0];
        expect(values.manager_index).toBe(0);
        expect(values.persons).toHaveLength(1);
        expect(values.persons[0].answers.first_name).toBe('Maria');
        expect(values.persons[0].answers.consent_privacy).toBe(true);
        expect(onSubmitted).toHaveBeenCalledWith({ success: true, act_id: 'a1', person_count: 1 });
    });

    it('blocks submit and shows validation errors when required fields are missing', async () => {
        const user = userEvent.setup();
        renderForm({ submit });

        await user.click(screen.getByRole('button', { name: /registrierung absenden/i }));

        expect(submit).not.toHaveBeenCalled();
        expect(await screen.findAllByText('Dieses Feld ist erforderlich.')).not.toHaveLength(0);
    });

    it('maps 422 validation errors from the backend to the matching fields', async () => {
        const user = userEvent.setup();
        const apiError = new Error('Unprocessable') as Error & { status: number; info: unknown };
        apiError.status = 422;
        apiError.info = { errors: { 'persons.0.answers.first_name': ['Der Vorname ist ungültig.'] } };
        submit.mockRejectedValueOnce(apiError);

        renderForm({ submit });

        await user.type(screen.getByLabelText('Vorname'), 'Maria');
        await user.type(screen.getByLabelText('E-Mail'), 'maria@example.com');
        await user.click(screen.getByLabelText('DSGVO'));
        await user.click(screen.getByLabelText('Alle Personen einverstanden'));
        await user.click(screen.getByRole('button', { name: /registrierung absenden/i }));

        expect(await screen.findByText('Der Vorname ist ungültig.')).toBeInTheDocument();
        expect(await screen.findByText('Bitte prüfe die markierten Felder.')).toBeInTheDocument();
    });

    it('renders server-side photo errors under the photo upload', async () => {
        const user = userEvent.setup();
        const apiError = new Error('Unprocessable') as Error & { status: number; info: unknown };
        apiError.status = 422;
        apiError.info = { errors: { 'persons.0.photos': ['Maximal 5 Fotos pro Person.'] } };
        submit.mockRejectedValueOnce(apiError);

        renderForm({ submit });

        await user.type(screen.getByLabelText('Vorname'), 'Maria');
        await user.type(screen.getByLabelText('E-Mail'), 'maria@example.com');
        await user.click(screen.getByLabelText('DSGVO'));
        await user.click(screen.getByLabelText('Alle Personen einverstanden'));
        await user.click(screen.getByRole('button', { name: /registrierung absenden/i }));

        expect(await screen.findByTestId('model-photos-error')).toHaveTextContent('Maximal 5 Fotos pro Person.');
    });

    it.each([409, 410, 404])('routes a fatal submit %i to the error page instead of an inline form error', async (status) => {
        const user = userEvent.setup();
        const onFatalError = vi.fn();
        const apiError = new Error(status === 409 ? 'Diese Einladung wurde bereits verwendet.' : 'Diese Einladung ist abgelaufen.') as Error & { status: number };
        apiError.status = status;
        submit.mockRejectedValueOnce(apiError);

        renderForm({ submit, onFatalError });

        await user.type(screen.getByLabelText('Vorname'), 'Maria');
        await user.type(screen.getByLabelText('E-Mail'), 'maria@example.com');
        await user.click(screen.getByLabelText('DSGVO'));
        await user.click(screen.getByLabelText('Alle Personen einverstanden'));
        await user.click(screen.getByRole('button', { name: /registrierung absenden/i }));

        expect(onFatalError).toHaveBeenCalledTimes(1);
        expect(onFatalError).toHaveBeenCalledWith(apiError.message);
        // No inline error banner is rendered — the parent swaps to the error page.
        expect(screen.queryByText('Bitte prüfe die markierten Felder.')).not.toBeInTheDocument();
        expect(screen.queryByText(apiError.message)).not.toBeInTheDocument();
    });

    it('keeps a non-fatal submit error (e.g. 500) as an inline form error', async () => {
        const user = userEvent.setup();
        const onFatalError = vi.fn();
        const apiError = new Error('Serverfehler') as Error & { status: number };
        apiError.status = 500;
        submit.mockRejectedValueOnce(apiError);

        renderForm({ submit, onFatalError });

        await user.type(screen.getByLabelText('Vorname'), 'Maria');
        await user.type(screen.getByLabelText('E-Mail'), 'maria@example.com');
        await user.click(screen.getByLabelText('DSGVO'));
        await user.click(screen.getByLabelText('Alle Personen einverstanden'));
        await user.click(screen.getByRole('button', { name: /registrierung absenden/i }));

        expect(onFatalError).not.toHaveBeenCalled();
        expect(await screen.findByText('Serverfehler')).toBeInTheDocument();
    });
});
