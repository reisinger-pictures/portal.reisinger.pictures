import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useEurDocuments } from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurDocumentsPanel from '../EurDocumentsPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {...actual, useEurDocuments: vi.fn()};
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function emptyDocuments() {
    return swrResult<ReturnType<typeof useEurDocuments>>({documents: []});
}

describe('EurDocumentsPanel upload control', () => {
    it('shows a German-labelled file control without browser-default English strings', () => {
        vi.mocked(useEurDocuments).mockReturnValue(emptyDocuments());

        renderWithProviders(<EurDocumentsPanel year={2026} month={1}/>);

        expect(screen.getByText('Datei auswählen')).toBeVisible();
        expect(screen.getByText('Keine Datei ausgewählt')).toBeVisible();
        expect(screen.queryByText(/Choose File/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/No file chosen/i)).not.toBeInTheDocument();

        const input = screen.getByLabelText('Datei auswählen');
        expect(input).toHaveAttribute('type', 'file');
        expect(input).toBeRequired();
    });

    it('shows the selected file name instead of the empty-state text after choosing a file', async () => {
        vi.mocked(useEurDocuments).mockReturnValue(emptyDocuments());

        renderWithProviders(<EurDocumentsPanel year={2026} month={1}/>);

        await userEvent.upload(screen.getByLabelText('Datei auswählen'),
            new File(['beleg'], 'beleg-2026.pdf', {type: 'application/pdf'}));

        expect(screen.getByText('beleg-2026.pdf')).toBeVisible();
        expect(screen.queryByText('Keine Datei ausgewählt')).not.toBeInTheDocument();
    });
});
