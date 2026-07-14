import { test, expect } from '@playwright/test';

test.describe('Brand favicon and manifest', () => {
    test.skip(({ browserName }) => browserName !== 'chromium', 'Desktop only');

    test('B2B domain loads RP favicon and manifest', { tag: ['@feature:brand'] }, async ({ page }) => {
        await page.goto('http://localhost:4321/');

        await expect(page.locator('link[rel="icon"][sizes="32x32"]')).toHaveAttribute('href', /\/brands\/rp\//);
        await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('href', /\/brands\/rp\//);
        await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', /.+/);
    });
});
