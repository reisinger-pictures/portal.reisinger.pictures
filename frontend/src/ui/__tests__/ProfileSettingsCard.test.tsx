import {describe, it, expect, vi, beforeEach} from 'vitest';
import {renderWithProviders} from '../../test-setup';
import ProfileSettingsCard from '../management/components/ProfileSettingsCard';

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import {useAuth} from '../../logic/useAuth';
import {usePermissions} from '../../logic/usePermissions';
import {useUI} from '../components/UIContext';

describe('ProfileSettingsCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useAuth).mockReturnValue({
            user: {id: 'u1', name: 'Max Mustermann', email: 'max@example.com', roles: []},
            mutate: vi.fn(),
        } as never);
        vi.mocked(usePermissions).mockReturnValue({isPhotographer: false} as never);
        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('marks the name field as required', () => {
        const {container} = renderWithProviders(<ProfileSettingsCard />);

        expect(container.querySelector('input[name="name"]')).toBeRequired();
    });
});
