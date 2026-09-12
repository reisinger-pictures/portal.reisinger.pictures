import {describe, it, expect, vi} from 'vitest';
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
});
