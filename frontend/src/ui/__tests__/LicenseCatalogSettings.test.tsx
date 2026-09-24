import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import userEvent from '@testing-library/user-event';
import LicenseCatalogSettings from '../management/components/LicenseCatalogSettings';

vi.mock('../../logic/useLicenseCatalog', () => ({
    useLicenseCatalog: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import { useLicenseCatalog } from '../../logic/useLicenseCatalog';
import { useUI } from '../components/UIContext';

const mockCatalog = {
    use_cases: [
        { id: 'uc1', name: 'Web-Nutzung', description: 'Für Web', base_price: 500000, flatrate_tier: 'web', sort_order: 0, is_commercial: false },
        { id: 'uc2', name: 'Print', description: 'Für Print', base_price: 1500000, flatrate_tier: 'print', sort_order: 1, is_commercial: false },
    ],
    modifiers: [
        { id: 'm1', name: 'Titelseite', description: 'Titelseiten-Zuschlag', percent_surcharge: 100, is_included_in_flatrate: false, sort_order: 0 },
    ],
};

describe('LicenseCatalogSettings', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: mockCatalog,
            isLoading: false,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });

        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(() => Promise.resolve(true)),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('shows loading spinner while catalog is loading', () => {
        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: undefined,
            isLoading: true,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });

        renderWithProviders(<LicenseCatalogSettings />);
        const spinner = document.querySelector('.loading.loading-spinner');
        expect(spinner).toBeInTheDocument();
    });

    it('renders use cases table with correct data', () => {
        renderWithProviders(<LicenseCatalogSettings />);

        expect(screen.getByText('Lizenz-Katalog (RSV Modell)')).toBeInTheDocument();
        expect(screen.getByText('Grundhonorare')).toBeInTheDocument();
        expect(screen.getByText('Web-Nutzung')).toBeInTheDocument();
        expect(screen.getAllByText('Print')[0]).toBeInTheDocument();
    });

    it('renders modifiers table with correct data', () => {
        renderWithProviders(<LicenseCatalogSettings />);

        expect(screen.getByText('Zuschläge (Aufschläge in %)')).toBeInTheDocument();
        expect(screen.getByText('Titelseite')).toBeInTheDocument();
        expect(screen.getByText('Kostenpflichtig')).toBeInTheDocument();
    });

    it('shows empty state when no use cases exist', () => {
        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: { use_cases: [], modifiers: mockCatalog.modifiers },
            isLoading: false,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });

        renderWithProviders(<LicenseCatalogSettings />);
        expect(screen.getByText('Noch keine Grundhonorare angelegt.')).toBeInTheDocument();
    });

    it('shows empty state when no modifiers exist', () => {
        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: { use_cases: mockCatalog.use_cases, modifiers: [] },
            isLoading: false,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });

        renderWithProviders(<LicenseCatalogSettings />);
        expect(screen.getByText('Noch keine Zuschläge angelegt.')).toBeInTheDocument();
    });

    it('adds a new use case via form', async () => {
        const user = userEvent.setup();
        const createUseCase = vi.fn().mockResolvedValue(undefined);
        const showToast = vi.fn();

        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: { use_cases: [], modifiers: [] },
            isLoading: false,
            createUseCase,
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });

        vi.mocked(useUI).mockReturnValue({ showToast, confirm: vi.fn(), hasUnsavedChanges: false, setUnsavedChanges: vi.fn() });

        renderWithProviders(<LicenseCatalogSettings />);

        const nameInput = screen.getByPlaceholderText('z.B. PR & Social Media');
        await user.type(nameInput, 'New Use Case');

        const priceInput = screen.getByPlaceholderText('z.B. 150');
        await user.type(priceInput, '200');

        const addButtons = screen.getAllByText('Hinzufügen');
        await user.click(addButtons[0]);

        expect(createUseCase).toHaveBeenCalledWith(
            expect.objectContaining({
                name: 'New Use Case',
                base_price: 20000,
            }),
        );
        expect(showToast).toHaveBeenCalledWith('success', 'Grundhonorar hinzugefügt');
    });

    it('shows validation error when adding use case without name or price', async () => {
        const user = userEvent.setup();
        const showToast = vi.fn();

        vi.mocked(useUI).mockReturnValue({ showToast, confirm: vi.fn(), hasUnsavedChanges: false, setUnsavedChanges: vi.fn() });

        renderWithProviders(<LicenseCatalogSettings />);

        const addButtons = screen.getAllByText('Hinzufügen');
        await user.click(addButtons[0]);

        expect(showToast).toHaveBeenCalledWith('error', 'Name und Preis sind Pflichtfelder');
    });

    it('adds a new modifier via form', async () => {
        const user = userEvent.setup();
        const createModifier = vi.fn().mockResolvedValue(undefined);
        const showToast = vi.fn();

        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: { use_cases: [], modifiers: [] },
            isLoading: false,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier,
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });

        vi.mocked(useUI).mockReturnValue({ showToast, confirm: vi.fn(), hasUnsavedChanges: false, setUnsavedChanges: vi.fn() });

        renderWithProviders(<LicenseCatalogSettings />);

        const nameInputs = screen.getAllByPlaceholderText('z.B. Titelseite');
        await user.type(nameInputs[0], 'New Modifier');

        const percentInputs = screen.getAllByPlaceholderText('z.B. 100');
        await user.type(percentInputs[0], '50');

        const addButtons = screen.getAllByText('Hinzufügen');
        await user.click(addButtons[1]);

        expect(createModifier).toHaveBeenCalledWith(
            expect.objectContaining({
                name: 'New Modifier',
                percent_surcharge: 50,
            }),
        );
        expect(showToast).toHaveBeenCalledWith('success', 'Zuschlag hinzugefügt');
    });

    it('resets a use-case draft after cancel and SWR revalidation', async () => {
        const user = userEvent.setup();
        const { rerender } = renderWithProviders(<LicenseCatalogSettings />);
        const row = screen.getByText('Web-Nutzung', { exact: true }).closest('tr');
        if (!row) throw new Error('Use-case row not found');

        await user.click(row.querySelector('button') as HTMLButtonElement);
        const titleInput = screen.getByDisplayValue('Web-Nutzung');
        await user.clear(titleInput);
        await user.type(titleInput, 'Lokaler Entwurf');
        await user.click(screen.getByRole('button', { name: 'Abbrechen' }));

        await user.click(row.querySelector('button') as HTMLButtonElement);
        expect(screen.getByDisplayValue('Web-Nutzung')).toBeInTheDocument();

        const updatedCatalog = {
            ...mockCatalog,
            use_cases: mockCatalog.use_cases.map((useCase) => useCase.id === 'uc1'
                ? { ...useCase, name: 'Server-aktualisiert', base_price: 700000 }
                : useCase),
        };
        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: updatedCatalog,
            isLoading: false,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });
        rerender(<LicenseCatalogSettings />);

        await waitFor(() => {
            expect(screen.getByDisplayValue('Server-aktualisiert')).toBeInTheDocument();
        });
    });

    it('resets a modifier draft when its SWR row changes', async () => {
        const user = userEvent.setup();
        const { rerender } = renderWithProviders(<LicenseCatalogSettings />);
        const row = screen.getByText('Titelseite', { exact: true }).closest('tr');
        if (!row) throw new Error('Modifier row not found');

        await user.click(row.querySelector('button') as HTMLButtonElement);
        const titleInput = screen.getByDisplayValue('Titelseite');
        await user.clear(titleInput);
        await user.type(titleInput, 'Ungespeicherter Titel');
        await user.click(screen.getByRole('button', { name: 'Abbrechen' }));

        const updatedCatalog = {
            ...mockCatalog,
            modifiers: mockCatalog.modifiers.map((modifier) => modifier.id === 'm1'
                ? { ...modifier, name: 'Aktualisierter Zuschlag', percent_surcharge: 75 }
                : modifier),
        };
        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: updatedCatalog,
            isLoading: false,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });
        rerender(<LicenseCatalogSettings />);

        const updatedRow = screen.getByText('Aktualisierter Zuschlag', { exact: true }).closest('tr');
        if (!updatedRow) throw new Error('Updated modifier row not found');
        await user.click(updatedRow.querySelector('button') as HTMLButtonElement);
        await waitFor(() => {
            expect(screen.getByDisplayValue('Aktualisierter Zuschlag')).toBeInTheDocument();
        });
    });

    it('refreshes an active modifier editor when its SWR row changes', async () => {
        const user = userEvent.setup();
        const { rerender } = renderWithProviders(<LicenseCatalogSettings />);
        const row = screen.getByText('Titelseite', { exact: true }).closest('tr');
        if (!row) throw new Error('Modifier row not found');

        const editButton = row.querySelector('button');
        if (!editButton) throw new Error('Modifier edit button not found');
        await user.click(editButton);

        const titleInput = screen.getByDisplayValue('Titelseite');
        await user.clear(titleInput);
        await user.type(titleInput, 'Ungespeicherter Titel');
        expect(titleInput).toHaveValue('Ungespeicherter Titel');

        const updatedCatalog = {
            ...mockCatalog,
            modifiers: mockCatalog.modifiers.map((modifier) => modifier.id === 'm1'
                ? { ...modifier, name: 'Server-aktualisierter Titel', percent_surcharge: 75 }
                : modifier),
        };
        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: updatedCatalog,
            isLoading: false,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });
        rerender(<LicenseCatalogSettings />);

        await waitFor(() => {
            const refreshedTitleInput = screen.getByDisplayValue('Server-aktualisierter Titel');
            const activeRow = refreshedTitleInput.closest('tr');
            const percentageInput = activeRow?.querySelector('input[type="number"]');
            if (!percentageInput) throw new Error('Active modifier editor not found');

            expect(refreshedTitleInput).toHaveValue('Server-aktualisierter Titel');
            expect(percentageInput).toHaveValue(75);
        });
        expect(screen.getByRole('button', { name: 'Speichern' })).toBeInTheDocument();
        expect(screen.queryByDisplayValue('Ungespeicherter Titel')).not.toBeInTheDocument();
    });
});
