import { describe, it, expect, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../../test-setup';
import ProductModal from '../ProductModal';

describe('ProductModal', () => {
    it('keeps the entered product data open when saving rejects', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        const onSave = vi.fn().mockRejectedValue(new Error('Speichern fehlgeschlagen'));

        const { container } = renderWithProviders(
            <ProductModal isOpen onClose={onClose} onSave={onSave} />,
        );

        const nameInput = container.querySelector('input[name="name"]') as HTMLInputElement;
        await user.type(nameInput, 'Hochzeitsreportage');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Speichern' })).toBeEnabled());

        expect(onClose).not.toHaveBeenCalled();
        expect(nameInput).toHaveValue('Hochzeitsreportage');
        expect(screen.getByText('Neuen Eintrag anlegen')).toBeInTheDocument();
    });
});
