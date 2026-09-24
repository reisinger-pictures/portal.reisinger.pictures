import {describe, it, expect, vi} from 'vitest';
import {screen} from '@testing-library/react';
import {renderWithProviders} from '../../test-setup';
import CustomerModal from '../management/components/CustomerModal';

describe('CustomerModal', () => {
    it('exposes the save fields by accessible name and marks the name field as required', () => {
        renderWithProviders(
            <CustomerModal isOpen onClose={vi.fn()} onSave={vi.fn()} />,
        );

        expect(screen.getByRole('textbox', {name: 'Name / Ansprechpartner'})).toBeRequired();
        expect(screen.getByRole('textbox', {name: 'Firma'})).toBeInTheDocument();
        expect(screen.getByLabelText('Geburtsdatum')).toHaveAttribute('type', 'date');
    });
});
