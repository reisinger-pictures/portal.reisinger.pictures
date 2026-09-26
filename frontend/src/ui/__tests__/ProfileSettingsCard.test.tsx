import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen, waitFor} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {renderWithProviders} from '../../test-setup';
import ProfileSettingsCard from '../management/components/ProfileSettingsCard';
import {apiMutate} from '../../api';

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
}));

vi.mock('../../api', () => ({
    apiMutate: vi.fn(),
}));

import {useAuth} from '../../logic/useAuth';
import {usePermissions} from '../../logic/usePermissions';
import {useUI} from '../components/UIContext';

const FORMAT_HINT = 'Nur Kleinbuchstaben, Ziffern, - und _, 3 bis 32 Zeichen, Start mit Buchstabe oder Ziffer.';

describe('ProfileSettingsCard', () => {
    const mutateUser = vi.fn();
    const showToast = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(apiMutate).mockResolvedValue({} as never);
        vi.mocked(useAuth).mockReturnValue({
            user: {id: 'u1', name: 'Max Mustermann', email: 'max@example.com', roles: []},
            mutate: mutateUser,
        } as never);
        vi.mocked(usePermissions).mockReturnValue({isPhotographer: true} as never);
        vi.mocked(useUI).mockReturnValue({
            showToast,
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('associates unique IDs with semantic profile field labels', () => {
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

    it.each([
        ['a dot', 'a.b'],
        ['an at sign', 'a@b'],
        ['a slash', 'a/b'],
        ['a leading underscore', '_jdoe'],
        ['a leading dash', '-jdoe'],
        ['too few characters', 'ab'],
        ['too many characters', 'a'.repeat(33)],
    ])('rejects an ftp login with %s and names the format', async (_reason, value) => {
        const user = userEvent.setup();
        renderWithProviders(<ProfileSettingsCard />);

        const ftpSlugInput = screen.getByRole('textbox', {name: /^FTP Upload Ordner \(Slug\)/});
        await user.clear(ftpSlugInput);
        await user.type(ftpSlugInput, value);
        await user.click(screen.getByRole('button', {name: /Profil speichern/}));

        expect(await screen.findByText(FORMAT_HINT)).toBeInTheDocument();
        expect(ftpSlugInput).toHaveAttribute('aria-invalid', 'true');
        expect(apiMutate).not.toHaveBeenCalled();
    });

    it.each([
        ['dash', 'j-doe'],
        ['underscore', 'j_doe'],
        ['trailing digit', 'jdoe1'],
        ['the minimum length', 'abc'],
        ['the maximum length', 'a'.repeat(32)],
    ])('accepts an ftp login built from %s', async (_case, value) => {
        const user = userEvent.setup();
        renderWithProviders(<ProfileSettingsCard />);

        const ftpSlugInput = screen.getByRole('textbox', {name: /^FTP Upload Ordner \(Slug\)/});
        await user.clear(ftpSlugInput);
        await user.type(ftpSlugInput, value);
        await user.click(screen.getByRole('button', {name: /Profil speichern/}));

        await waitFor(() => {
            expect(apiMutate).toHaveBeenCalledWith(
                '/api/auth/profile',
                'PUT',
                expect.objectContaining({ftp_slug: value}),
            );
        });
        expect(screen.queryByText(FORMAT_HINT)).not.toBeInTheDocument();
        expect(ftpSlugInput).not.toHaveAttribute('aria-invalid');
    });

    it('leaves an empty ftp login alone instead of reporting an error', async () => {
        const user = userEvent.setup();
        renderWithProviders(<ProfileSettingsCard />);

        await user.clear(screen.getByRole('textbox', {name: /^FTP Upload Ordner \(Slug\)/}));
        await user.click(screen.getByRole('button', {name: /Profil speichern/}));

        await waitFor(() => {
            expect(apiMutate).toHaveBeenCalledWith(
                '/api/auth/profile',
                'PUT',
                expect.objectContaining({ftp_slug: ''}),
            );
        });
        expect(screen.queryByText(FORMAT_HINT)).not.toBeInTheDocument();
    });

    it('does not offer the ftp login field to non-photographers', () => {
        vi.mocked(usePermissions).mockReturnValue({isPhotographer: false} as never);
        renderWithProviders(<ProfileSettingsCard />);

        expect(screen.queryByRole('textbox', {name: /^FTP Upload Ordner \(Slug\)/})).not.toBeInTheDocument();
    });
});
