import { test, expect } from '@playwright/test';

test.describe('Brand data-theme and data-brand attributes', () => {
    test.skip(({ browserName }) => browserName !== 'chromium', 'Desktop only');

    test('B2B domain has correct theme attributes', { tag: ['@smoke', '@feature:brand'] }, async ({ page }) => {
        await page.goto('http://localhost:4321/');

        const html = page.locator('html');
        await expect(html).toHaveAttribute('data-brand', 'rp');
        await expect(html).toHaveAttribute('data-theme', /^(b2b-light|b2b-dark|reisinger-light)$/);
    });
});
