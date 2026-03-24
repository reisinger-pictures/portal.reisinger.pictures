import { test, expect } from '@playwright/test';

test.describe('Guest Workflow', () => {
    test('Guest can access discovery search and perform a query', async ({ page }) => {
        await page.goto('/');

        await expect(page.getByRole('heading', { name: 'Neueste Entdeckungen' })).toBeVisible();

        // Suche ausführen (nach etwas, das voraussichtlich nicht da ist, um Error-States zu vermeiden)
        const randomSearchTerm = `Search-${Date.now()}`;
        await page.fill('input[placeholder="Galerien und Bilder suchen..."]', randomSearchTerm);
        await page.getByRole('button', { name: 'Suchen' }).click();

        // Die UI sollte sich zu "Suchergebnisse für X" ändern, unabhängig davon ob es Treffer gibt
        await expect(page.locator(`h1:has-text("${randomSearchTerm}")`)).toBeVisible();
    });
});
