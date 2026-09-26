import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen} from '@testing-library/react';
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

    it('associates unique IDs with semantic profile field labels', () => {
        vi.mocked(usePermissions).mockReturnValue({isPhotographer: true} as never);
        renderWithProviders(<ProfileSettingsCard />);

        const nameInput = screen.getByRole('textbox', {name: /^Dein Name$/});
        const ftpSlugInput = screen.getByRole('textbox', {name: /^FTP Upload Ordner \(Slug\)/});
        const copyrightInput = screen.getByRole('textbox', {name: /^Standard-Urheber \(IPTC Copyright\)/});
        const inputIds = [nameInput.id, ftpSlugInput.id, copyrightInput.id];

        expect(nameInput).toBeRequired();
        expect(inputIds.every(id => id.length > 0)).toBe(true);
        expect(new Set(inputIds).size).toBe(inputIds.length);
        expect(nameInput).toHaveAccessibleName('Dein Name');
        expect(ftpSlugInput).toHaveAccessibleName(/^FTP Upload Ordner \(Slug\)/);
        expect(copyrightInput).toHaveAccessibleName(/^Standard-Urheber \(IPTC Copyright\)/);
    });
});
