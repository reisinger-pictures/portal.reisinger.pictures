import { test, expect } from '@playwright/test';

test.describe('Guest Workflow', () => {
    test('Guest can access discovery search and perform a query', { tag: ['@smoke', '@feature:guest'] }, async ({ page }) => {
        await page.goto('/');

        await expect(page.getByRole('main').getByRole('heading', { name: 'Neueste Entdeckungen' })).toBeVisible();

        // Suche ausführen (nach etwas, das voraussichtlich nicht da ist, um Error-States zu vermeiden)
        const randomSearchTerm = `Search-${Math.random().toString(36).substring(2, 10)}`;
        await page.getByRole('main').locator('input[placeholder="Suche in allen Galerien..."]').fill(randomSearchTerm);
        await page.getByRole('main').locator('input[placeholder="Suche in allen Galerien..."]').press('Enter');

        // Die UI sollte sich zu "Suchergebnisse für X" ändern, unabhängig davon ob es Treffer gibt
        await expect(page.getByRole('main').locator(`h1:has-text("${randomSearchTerm}")`)).toBeVisible();
    });
});
