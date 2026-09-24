import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import IptcMetadataEditor from '../IptcMetadataEditor';
import { renderWithProviders } from '../../../test-setup';

vi.mock('../AutocompleteInput', () => ({
    default: ({ label }: { label?: string }) => <div>{label}</div>,
}));

describe('IptcMetadataEditor', () => {
    it('formats captured_at as a read-only UTC field', () => {
        renderWithProviders(
            <IptcMetadataEditor
                data={{}}
                onChange={vi.fn()}
                capturedAt="2026-05-01T14:30:00.000000Z"
            />,
        );

        expect(screen.getByText('Aufnahmedatum')).toBeVisible();
        expect(screen.getByText(/01\.05\.2026, 14:30/)).toHaveTextContent('Uhr');
        expect(screen.queryByDisplayValue('01.05.2026, 14:30')).not.toBeInTheDocument();
    });
});
