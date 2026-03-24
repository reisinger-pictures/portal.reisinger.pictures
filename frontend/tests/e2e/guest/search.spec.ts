import { test, expect } from '@playwright/test';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Global Search Workflow', () => {
    let sidebar: SidebarHelper;

    test.beforeEach(async ({ page }) => {
        sidebar = new SidebarHelper(page);
    });

    test('Guest can use global sidebar search to find content', async ({ page }) => {
        await page.goto('/');

        // Auf Mobile das Menü öffnen, damit die Sidebar sichtbar wird
        // await sidebar.openMobileMenu(); // Search is now in header

        const searchInput = page.locator('input[placeholder="Galerien und Bilder suchen..."]');
        await expect(searchInput).toBeVisible();

        const searchTerm = `GlobalSearch${Date.now()}`;
        await searchInput.fill(searchTerm);
        await searchInput.press('Enter');

        // Architektur-Regel: Geduldiges Assert
        await expect(page).toHaveURL(new RegExp(`/search\\?q=${searchTerm}`));
        await expect(page.locator(`h1:has-text("${searchTerm}")`)).toBeVisible({ timeout: 15000 });
    });
});
