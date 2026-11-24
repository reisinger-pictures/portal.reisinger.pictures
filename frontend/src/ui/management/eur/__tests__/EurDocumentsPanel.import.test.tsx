import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { importEurEntries, useEurAssetGroups, useEurDocuments } from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurDocumentsPanel from '../EurDocumentsPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {
        ...actual,
        useEurDocuments: vi.fn(),
        useEurAssetGroups: vi.fn(),
        uploadEurDocument: vi.fn(),
        deleteEurDocument: vi.fn(),
        importEurEntries: vi.fn(),
    };
});

beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(useEurAssetGroups).mockReturnValue(swrResult<ReturnType<typeof useEurAssetGroups>>({groups: []}));
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function renderPanel() {
    vi.mocked(useEurDocuments).mockReturnValue(swrResult<ReturnType<typeof useEurDocuments>>({documents: []}));
    return renderWithProviders(<EurDocumentsPanel year={2026} month={3}/>);
}

describe('EurDocumentsPanel CSV import', () => {
    it('offers a German-labelled CSV file input and imports into the selected month', async () => {
        vi.mocked(importEurEntries).mockResolvedValue({created: 4, skipped: 1, errors: []});
        renderPanel();

        const input = screen.getByLabelText('CSV-Datei auswählen');
        expect(input).toHaveAttribute('type', 'file');
        expect(input).toHaveAttribute('accept', '.csv,text/csv');
        expect(screen.getByText('Keine CSV-Datei ausgewählt')).toBeVisible();

        const file = new File(['Amount;Share\n100;100'], 'buchungen.csv', {type: 'text/csv'});
        await userEvent.upload(input, file);
        await userEvent.click(screen.getByRole('button', {name: 'CSV importieren'}));

        expect(importEurEntries).toHaveBeenCalledWith({file, year: 2026, month: 3});
        expect(screen.getByRole('status')).toHaveTextContent(/Import abgeschlossen: 4 angelegt, 1 übersprungen/);
    });

    it('shows import errors in German when the request fails', async () => {
        vi.mocked(importEurEntries).mockRejectedValue(new Error('boom'));
        renderPanel();

        await userEvent.upload(screen.getByLabelText('CSV-Datei auswählen'),
            new File(['x'], 'buchungen.csv', {type: 'text/csv'}));
        await userEvent.click(screen.getByRole('button', {name: 'CSV importieren'}));

        expect(screen.getByRole('alert')).toHaveTextContent(/Der CSV-Import ist fehlgeschlagen/);
    });

    it('lists row-level errors returned by the import', async () => {
        vi.mocked(importEurEntries).mockResolvedValue({created: 0, skipped: 0, errors: ['Zeile 2: ungültiger Betrag']});
        renderPanel();

        await userEvent.upload(screen.getByLabelText('CSV-Datei auswählen'),
            new File(['x'], 'buchungen.csv', {type: 'text/csv'}));
        await userEvent.click(screen.getByRole('button', {name: 'CSV importieren'}));

        expect(screen.getByText('Zeile 2: ungültiger Betrag')).toBeVisible();
    });
});
