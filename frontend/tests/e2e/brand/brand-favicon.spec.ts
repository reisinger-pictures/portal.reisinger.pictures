import { test, expect } from '@playwright/test';

test.describe('Brand favicon and manifest', () => {
    test.skip(({ browserName }) => browserName !== 'chromium', 'Desktop only');

    test('B2B domain loads RP favicon and manifest', { tag: ['@feature:brand'] }, async ({ page }) => {
        await page.goto('http://localhost:4321/');

        await expect(page.locator('link[rel="icon"][sizes="32x32"]')).toHaveAttribute('href', /\/brands\/rp\//);
        await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('href', /\/brands\/rp\//);
        await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', /.+/);
    });

    test('RP favicon links are static root paths in initial HTML', { tag: ['@feature:brand', '@smoke'] }, async ({ request }) => {
        const response = await request.get('http://localhost:4321/');
        const html = await response.text();

        expect(html).toContain('<link rel="icon" type="image/x-icon" href="/favicon.ico"');
        expect(html).toContain('<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png"');
        expect(html).toContain('<link rel="manifest" href="/site.webmanifest"');
        expect(html).not.toContain('document.createElement(\'link\')');
    });
});
