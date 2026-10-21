import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';

vi.mock('react-router-dom', () => ({
    useParams: () => ({ token: shareState.token }),
}));

vi.mock('../components/UIContext', () => ({
    useUI: () => ({
        showToast: vi.fn(),
        confirm: vi.fn(),
        hasUnsavedChanges: false,
        setUnsavedChanges: vi.fn(),
    }),
}));

// Die Brand kommt sonst aus einem SWR-Request — für die Datenfreiheit der
// Seite ist sie irrelevant, der Logo-Img gehört zum Header, nicht zum Inhalt.
vi.mock('../../logic/useBrand', () => ({
    useBrand: () => ({
        logoSrc: '/brands/rp/android-chrome-192x192.png',
        portalName: 'Test Portal',
    }),
}));

import ModelContactSheetShareView from '../ModelContactSheetShareView';

const shareState = vi.hoisted(() => ({ token: '' }));

/** Base64url eines JSON-Objekts (ohne Padding) — wie im JWT. */
function base64Url(value: unknown): string {
    return btoa(JSON.stringify(value))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

/**
 * Ein Token wie der Server ihn ausstellt: der Payload trägt die
 * Profil-IDs, die Seite darf daraus **nichts** anzeigen.
 */
function shareToken(expiresAt: Date, profileIds: string[]): string {
    const claims = {
        exp: Math.floor(expiresAt.getTime() / 1000),
        offer: {
            type: 'model_contact_sheet',
            model_profile_ids: profileIds,
            variant: 'external',
            brand: 'rp',
            link_id: '99999999-8888-4777-8666-555555555555',
        },
    };

    return `${base64Url({ alg: 'HS256', typ: 'JWT' })}.${base64Url(claims)}.signature`;
}

const profileId = '11111111-2222-4333-8444-555555555555';

describe('ModelContactSheetShareView', () => {
    it('shows the download button and the expiry hint', () => {
        shareState.token = shareToken(new Date(Date.now() + 7 * 24 * 3600 * 1000), [profileId]);

        renderWithProviders(<ModelContactSheetShareView />);

        expect(screen.getByTestId('contact-sheet-share-download')).toBeInTheDocument();
        expect(screen.getByTestId('contact-sheet-share-expiry')).toBeInTheDocument();
    });

    /**
     * Die öffentliche Seite ist bewusst **datenfrei**: sie trägt weder Namen
     * noch Fotos noch Profildaten — der externe Bogen ist PII-frei, und die
     * Seite darf kein zweiter Datenkanal werden.
     */
    it('shows no model data at all', () => {
        shareState.token = shareToken(new Date(Date.now() + 7 * 24 * 3600 * 1000), [profileId]);

        const { container } = renderWithProviders(<ModelContactSheetShareView />);

        // Kein Künstlername, kein Realname, keine E-Mail.
        expect(screen.queryByText('StarletA')).toBeNull();
        expect(screen.queryByText('RealnameA')).toBeNull();
        expect(screen.queryByText('anna@example.com')).toBeNull();

        // Keine Profil-ID — auch nicht die, die im Token steckt.
        expect(container.textContent).not.toContain(profileId);
        expect(container.innerHTML).not.toContain(profileId);

        // Kein Foto: im Inhalt (<main>) gibt es kein <img>-Element. Das Logo
        // des Brand-Headers liegt außerhalb von <main>.
        const main = screen.getByRole('main');
        expect(main.querySelectorAll('img')).toHaveLength(0);
    });
});
