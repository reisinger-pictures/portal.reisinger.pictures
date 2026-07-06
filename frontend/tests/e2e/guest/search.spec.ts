import { test, expect } from '@playwright/test';

test.describe('Global Search Workflow', () => {
    test('Guest can use global sidebar search to find content', { tags: ['@feature:guest'] }, async ({ page }) => {
        await page.goto('/');

        // Auf Mobile das Menü öffnen, damit die Sidebar sichtbar wird
        // await sidebar.openMobileMenu(); // Search is now in header

        const searchInput = page.locator('input[placeholder="Suche in allen Galerien..."]');
        await expect(searchInput).toBeVisible();

        const searchTerm = `GlobalSearch${Math.random().toString(36).substring(2, 10)}`;
        await searchInput.fill(searchTerm);
        await searchInput.press('Enter');

        // Architektur-Regel: Geduldiges Assert
        await expect(page).toHaveURL(new RegExp(`/search\\?q=${searchTerm}`));
        await expect(page.locator(`h1:has-text("${searchTerm}")`)).toBeVisible();
    });
});
