import { test, expect } from '@playwright/test';

test.describe('Guest Search & Header (G9)', () => {

    test('Guest sees header with brand elements', { tag: ['@feature:guest'] }, async ({ page }) => {
        await page.goto('/');
        const header = page.getByRole('banner');
        await expect(header).toHaveCount(1);
        const searchInput = page.getByRole('main').locator('input[placeholder="Suche in allen Galerien..."]');
        await expect(searchInput).toBeVisible();
    });

    test('Guest search exposes an accessible keyboard path and returns results', { tag: ['@regression', '@feature:guest'] }, async ({ page }) => {
        await page.goto('/');
        const main = page.getByRole('main');
        const searchInput = main.getByRole('textbox', { name: 'Suche' });
        const searchButton = main.getByRole('button', { name: 'Suche' });
        await expect(searchInput).toBeVisible();
        await expect(searchButton).toBeVisible();
        await expect(searchInput).toHaveAccessibleName('Suche');
        await expect(searchButton).toHaveAccessibleName('Suche');

        const randomSearchTerm = `Search-${Math.random().toString(36).substring(2, 10)}`;
        await searchInput.fill(randomSearchTerm);
        await searchInput.press('Enter');

        await expect(page).toHaveURL(new RegExp(`/search\\?q=${randomSearchTerm}`));
        await expect(main.getByRole('heading', { name: new RegExp(randomSearchTerm) })).toBeVisible({ timeout: 15000 });
    });
});
