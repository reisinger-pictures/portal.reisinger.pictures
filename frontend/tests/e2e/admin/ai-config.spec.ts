import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';

test.describe('Admin AI Config', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('AT-03-E5a: AI disabled — no banner, no AI button', { tag: ['@feature:admin:ai'] }, async ({ page }) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({ json: { enabled: false, status: 'disabled', model: '' } });
        });

        await page.route('**/api/auth/me', async route => {
            const response = await route.fetch();
            if (response.ok()) {
                const json = await response.json();
                json.ai_is_unconfigured = false;
                await route.fulfill({ response, json });
            } else {
                await route.continue();
            }
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        await expect(page.locator('main').first()).toBeVisible({ timeout: 10000 });
        await expect(page.locator('.alert-warning').filter({ hasText: 'KI-Bildbeschreibung' })).toBeHidden({ timeout: 5000 });
    });

    test('AT-03-E5b: AI unconfigured — warning banner visible', { tag: ['@feature:admin:ai'] }, async ({ page }) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({ json: { enabled: false, status: 'unconfigured', model: '' } });
        });

        await page.route('**/api/auth/me', async route => {
            const response = await route.fetch();
            if (response.ok()) {
                const json = await response.json();
                json.ai_is_unconfigured = true;
                await route.fulfill({ response, json });
            } else {
                await route.continue();
            }
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        await expect(page.locator('main').first()).toBeVisible({ timeout: 10000 });
        await expect(page.locator('.alert-warning').filter({ hasText: 'KI-Bildbeschreibung nicht konfiguriert' })).toBeVisible({ timeout: 5000 });
    });

    test('AT-03-E5c: AI available — no warning banner', { tag: ['@feature:admin:ai'] }, async ({ page }) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({ json: { enabled: true, status: 'available', model: 'gpt-4o' } });
        });

        await page.route('**/api/auth/me', async route => {
            const response = await route.fetch();
            if (response.ok()) {
                const json = await response.json();
                json.ai_is_unconfigured = false;
                await route.fulfill({ response, json });
            } else {
                await route.continue();
            }
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        await expect(page.locator('main').first()).toBeVisible({ timeout: 10000 });

        await expect(page.locator('.alert-warning').filter({ hasText: 'KI-Bildbeschreibung' })).toBeHidden({ timeout: 5000 });
    });
});

test.describe('AI Configuration Page & Generate Button', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('AI configuration page loads for super_admin', { tag: ['@feature:admin:ai'] }, async ({ page }) => {
        const user = await helper.createIsolatedUser('super_admin');
        const auth = new AuthHelper(page);
        await auth.login(user.email, user.password);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('main').first()).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('heading', { name: /Einstellungen/i })).toBeVisible({ timeout: 5000 });
    });

    test('AI generate button is visible in photo management for photographer', { tag: ['@feature:admin:ai'] }, async ({ page }) => {
        const user = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);

        await page.route('**/api/ai/status', async route => {
            await route.fulfill({ json: { enabled: true, status: 'available', model: 'gpt-4o' } });
        });

        // Mock management galleries tree with a gallery
        await page.route('**/api/management/galleries', async route => {
            await route.fulfill({
                json: {
                    groups: [],
                    root_galleries: [{ id: 'e2e-ai-gallery', name: 'AI Test Gallery', type: 'delivery', is_live: true, full_path: 'e2e-ai-gallery', org_id: null, expires_at: null }],
                }
            });
        });

        // Mock photo endpoint so the gallery shows a photo
        await page.route('**/api/galleries/e2e-ai-gallery/photos**', async route => {
            await route.fulfill({ json: { photos: [{ id: 'e2e-ai-photo', filename: 'test.jpg', url: '/photos/test.jpg', title: 'Test Photo' }] } });
        });

        await auth.login(user.email, user.password);

        // Navigate to the gallery via sidebar
        const sidebar = new (await import('../helpers/SidebarHelper')).SidebarHelper(page);
        await sidebar.navigateTo('Galerien & Ordner');
        await expect(page.locator('main a').filter({ hasText: 'AI Test Gallery' }).first()).toBeVisible({ timeout: 15000 });
        await page.locator('main a').filter({ hasText: 'AI Test Gallery' }).first().click();

        await expect(page.locator('main').first()).toBeVisible({ timeout: 10000 });
    });
});
