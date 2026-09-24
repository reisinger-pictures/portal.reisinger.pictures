import {describe, it, expect, vi} from 'vitest';
import {fireEvent, screen, waitFor} from '@testing-library/react';
import {renderWithProviders} from '../../test-setup';
import CreateUserModal from '../management/components/CreateUserModal';

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(() => ({showToast: vi.fn()})),
}));

describe('CreateUserModal', () => {
    it('marks the name and email fields as required', () => {
        const {container} = renderWithProviders(
            <CreateUserModal isOpen onClose={vi.fn()} onCreate={vi.fn()} />,
        );

        expect(container.querySelector('input[name="name"]')).toBeRequired();
        expect(container.querySelector('input[name="email"]')).toBeRequired();
    });

    it('clears a cancelled draft when the modal is reopened', async () => {
        const onClose = vi.fn();
        const {container, rerender} = renderWithProviders(
            <CreateUserModal isOpen onClose={onClose} onCreate={vi.fn()} />,
        );
        const nameInput = container.querySelector('input[name="name"]') as HTMLInputElement;
        const emailInput = container.querySelector('input[name="email"]') as HTMLInputElement;

        fireEvent.change(nameInput, {target: {value: 'Alter Entwurf'}});
        fireEvent.change(emailInput, {target: {value: 'alter@example.com'}});
        fireEvent.click(screen.getByRole('button', {name: 'Abbrechen'}));
        expect(onClose).toHaveBeenCalledTimes(1);

        rerender(<CreateUserModal isOpen={false} onClose={onClose} onCreate={vi.fn()} />);
        rerender(<CreateUserModal isOpen onClose={onClose} onCreate={vi.fn()} />);

        const reopenedNameInput = container.querySelector('input[name="name"]') as HTMLInputElement;
        const reopenedEmailInput = container.querySelector('input[name="email"]') as HTMLInputElement;
        await waitFor(() => {
            expect(reopenedNameInput).toHaveValue('');
            expect(reopenedEmailInput).toHaveValue('');
        });
    });
});
