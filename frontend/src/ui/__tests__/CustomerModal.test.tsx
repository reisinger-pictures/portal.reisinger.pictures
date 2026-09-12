import {describe, it, expect, vi} from 'vitest';
import {renderWithProviders} from '../../test-setup';
import CustomerModal from '../management/components/CustomerModal';

describe('CustomerModal', () => {
    it('marks the name field as required', () => {
        const {container} = renderWithProviders(
            <CustomerModal isOpen onClose={vi.fn()} onSave={vi.fn()} />,
        );

        expect(container.querySelector('input[name="name"]')).toBeRequired();
    });
});
