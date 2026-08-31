import { test, expect } from '@playwright/test';

test.describe('Rechtliche Seiten (AGB & Widerruf) (G)', () => {
    test('Guest can access /widerruf page', { tag: ['@feature:legal'] }, async ({ page }) => {
        await page.goto('/widerruf');

        await expect(page.getByRole('main').getByRole('heading', { name: 'Widerrufsbelehrung' })).toBeVisible({ timeout: 10000 });
        // Schlüssel-Inhalte: 14-tägige Frist + Erlöschen bei digitalen Inhalten
        await expect(page.locator('main')).toContainText('vierzehn Tagen');
        await expect(page.locator('main').getByRole('heading', { name: 'Erlöschen des Rücktrittsrechts bei digitalen Inhalten' })).toBeVisible();
    });

    test('Guest can access /license-terms page', { tag: ['@feature:legal'] }, async ({ page }) => {
        await page.goto('/license-terms');

        await expect(page.getByRole('main').getByRole('heading', { name: 'AGB & Lizenzbedingungen' })).toBeVisible({ timeout: 10000 });
        // Zentrale Rechtsgrundlage für digitale Inhalte (FAGG)
        await expect(page.locator('main')).toContainText('FAGG');
        await expect(page.locator('main')).toContainText('sofortiger Download');
    });

    test('Impressum page links to the Widerrufsbelehrung', { tag: ['@feature:legal'] }, async ({ page }) => {
        await page.goto('/impressum');

        await page.getByRole('main').getByRole('link', { name: 'Widerrufsbelehrung' }).click();
        await expect(page).toHaveURL(/\/widerruf$/);
        await expect(page.getByRole('main').getByRole('heading', { name: 'Widerrufsbelehrung' })).toBeVisible({ timeout: 10000 });
    });
});