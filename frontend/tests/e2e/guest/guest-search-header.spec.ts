import { test, expect } from '@playwright/test';

test.describe('Guest Search & Header (G9)', () => {
    test('Guest can see search input on landing page', async ({ page }) => {
        await page.goto('/');
        const searchInput = page.locator('input[placeholder="Galerien und Bilder suchen..."]');
        await expect(searchInput).toBeVisible();
    });

    test('Guest sees header with brand elements', async ({ page }) => {
        await page.goto('/');
        const header = page.locator('header').first();
        await expect(header).toHaveCount(1);
        const searchInput = page.locator('input[placeholder="Galerien und Bilder suchen..."]');
        await expect(searchInput).toBeVisible();
    });

    test('Guest search returns results', async ({ page }) => {
        await page.goto('/');
        const searchInput = page.locator('input[placeholder="Galerien und Bilder suchen..."]');
        await expect(searchInput).toBeVisible();

        const randomSearchTerm = `Search-${Math.random().toString(36).substring(2, 10)}`;
        await searchInput.fill(randomSearchTerm);
        await searchInput.press('Enter');

        await expect(page).toHaveURL(new RegExp(`/search\\?q=${randomSearchTerm}`));
        await expect(page.getByRole('heading', { name: new RegExp(randomSearchTerm) })).toBeVisible({ timeout: 15000 });
    });
});
