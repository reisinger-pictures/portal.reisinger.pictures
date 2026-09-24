import { describe, it, expect, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../../test-setup';
import CustomerModal from '../CustomerModal';

describe('CustomerModal save regression', () => {
    it('keeps entered customer data open when saving rejects', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        const onSave = vi.fn().mockRejectedValue(new Error('Speichern fehlgeschlagen'));
        const { container } = renderWithProviders(
            <CustomerModal isOpen onClose={onClose} onSave={onSave} />,
        );

        const nameInput = container.querySelector('input[name="name"]') as HTMLInputElement;
        await user.type(nameInput, 'Max Mustermann');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Speichern' })).toBeEnabled());

        expect(onClose).not.toHaveBeenCalled();
        expect(nameInput).toHaveValue('Max Mustermann');
        expect(screen.getByText('Neuen Kunden anlegen')).toBeInTheDocument();
    });
});
