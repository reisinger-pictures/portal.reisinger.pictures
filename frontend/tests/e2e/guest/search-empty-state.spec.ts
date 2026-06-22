import {test, expect} from '@playwright/test';

// E2E-01 §1 (Leer-/Grenzzustände, echt): Suche ohne Treffer zeigt dedizierte Empty-State-UI
// statt einer stillen leeren Liste. Grounded in `src/ui/SearchView.tsx`.
test.describe('Global Search — Empty-Result State', () => {
    test('Search with no matches shows dedicated empty-state messages', async ({page}) => {
        await page.goto('/');

        const searchInput = page.locator('input[placeholder="Galerien und Bilder suchen..."]');
        await expect(searchInput).toBeVisible();

        // Garantiert nicht existierender Begriff — keine Kollision mit realen Galerie-/Bildnamen.
        const noMatchTerm = `zzz_no_such_term_${Math.random().toString(36).substring(2, 12)}`;
        await searchInput.fill(noMatchTerm);
        await searchInput.press('Enter');

        await expect(page).toHaveURL(new RegExp(`/search\\?q=${noMatchTerm}`));

        // Heading zeigt den gesuchten Begriff an.
        await expect(page.getByRole('heading', {name: new RegExp(noMatchTerm)})).toBeVisible({timeout: 15000});

        // Sektion-Überschriften melden explizit null Treffer.
        await expect(page.getByRole('heading', {name: /^Galerien \(0\)$/})).toBeVisible();
        await expect(page.getByRole('heading', {name: /^Fotos \(0\)$/})).toBeVisible();

        // Echte Empty-State-UI (nicht nur eine leere Liste).
        await expect(page.getByText('Keine passenden Galerien gefunden.')).toBeVisible();
        await expect(page.getByText('Keine passenden Fotos gefunden.')).toBeVisible();
    });
});
