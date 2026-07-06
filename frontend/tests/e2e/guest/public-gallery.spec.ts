import { test, expect } from '@playwright/test';

test.describe('Guest Public Gallery Access (G5)', () => {
    test('Guest can view public galleries without authentication', { tags: ['@smoke', '@feature:guest'] }, async ({ page }) => {
        await page.goto('/');

        await expect(page.getByRole('heading', { name: 'Neueste Entdeckungen' }).first()).toBeVisible({ timeout: 10000 });

        const searchInput = page.locator('input[placeholder="Suche in allen Galerien..."]').first();
        await expect(searchInput).toBeVisible();

        await expect(page.locator('main')).toBeVisible();

        const loginForm = page.locator('aside').locator('input[placeholder="E-Mail Adresse"]');
        expect(await loginForm.count()).toBe(1);
    });
});
