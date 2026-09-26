import { describe, it, expect, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../../test-setup';
import UserPermissionsModal from '../UserPermissionsModal';
import type { UserDetailed } from '../../../../logic/useUsers';
import { UserRole } from '../../../../logic/useUsers';

const user: UserDetailed = {
    id: 'user-1',
    name: 'Test User',
    email: 'test@example.com',
    is_super_admin: false,
    is_admin: true,
    is_photographer: false,
    is_pending: false,
    can_edit_metadata: false,
    flatrate_level: 'none',
    roles: [],
    gallery_groups: [],
    galleries: [],
};

describe('UserPermissionsModal', () => {
    it('keeps selected permissions open when saving rejects', async () => {
        const eventUser = userEvent.setup();
        const onClose = vi.fn();
        const onSave = vi.fn().mockRejectedValue(new Error('Speichern fehlgeschlagen'));

        const { container } = renderWithProviders(
            <UserPermissionsModal
                user={user}
                roles={[{ id: 'role-1', name: UserRole.ADMIN }]}
                flatGroups={[]}
                flatGalleries={[]}
                onClose={onClose}
                onSave={onSave}
            />,
        );

        const metadataCheckbox = container.querySelector('input[type="checkbox"]') as HTMLInputElement;
        await eventUser.click(metadataCheckbox);
        await eventUser.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Speichern' })).toBeEnabled());

        expect(onClose).not.toHaveBeenCalled();
        expect(metadataCheckbox).toBeChecked();
        expect(screen.getByText('Test User bearbeiten')).toBeInTheDocument();
    });
});
