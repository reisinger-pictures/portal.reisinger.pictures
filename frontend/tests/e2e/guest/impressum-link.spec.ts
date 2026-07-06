import { test, expect } from '@playwright/test';

test.describe('Impressum & Datenschutz Links (G8)', () => {
    test('Impressum link is visible in sidebar', { tags: ['@feature:guest'] }, async ({ page }) => {
        await page.goto('/');

        await expect(page.locator('aside').locator('a:has-text("Impressum & Datenschutz")').first()).toBeVisible({ timeout: 10000 });
    });

    test('Guest can access /impressum page', { tags: ['@feature:guest'] }, async ({ page }) => {
        await page.goto('/impressum');

        await expect(page.getByRole('heading', { name: 'Impressum' })).toBeVisible({ timeout: 10000 });
        await expect(page.locator('main')).toBeVisible();
    });

    test('Guest can access /privacy page', { tags: ['@feature:guest'] }, async ({ page }) => {
        await page.goto('/privacy');

        await expect(page.getByRole('heading', { name: 'Datenschutzerklärung' })).toBeVisible({ timeout: 10000 });
        await expect(page.locator('main')).toBeVisible();
    });
});
