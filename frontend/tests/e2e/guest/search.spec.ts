import { test, expect } from '@playwright/test';

test.describe('Global Search Workflow', () => {
    // Carries @smoke since 2026-09-26: guest.spec.ts was deleted as a duplicate
    // of this test, and deleting it would otherwise have removed the only
    // @smoke coverage of the guest search path.
    test('Guest can use global sidebar search to find content', { tag: ['@smoke', '@feature:guest'] }, async ({ page }) => {
        await page.goto('/');

        // Auf Mobile das Menü öffnen, damit die Sidebar sichtbar wird
        // await sidebar.openMobileMenu(); // Search is now in header

        const searchInput = page.getByRole('main').locator('input[placeholder="Suche in allen Galerien..."]');
        await expect(searchInput).toBeVisible();

        const searchTerm = `GlobalSearch${Math.random().toString(36).substring(2, 10)}`;
        await searchInput.fill(searchTerm);
        await searchInput.press('Enter');

        // Architektur-Regel: Geduldiges Assert
        await expect(page).toHaveURL(new RegExp(`/search\\?q=${searchTerm}`));
        await expect(page.getByRole('main').locator(`h1:has-text("${searchTerm}")`)).toBeVisible();
    });
});
